<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * intensity-formatting / behavioral-keywords / felt-steering, the pure parts (no database; the
 * shipped config defaults):
 *   - every band keyword describes behavior, never a label, a number or a mechanic;
 *   - high arousal / passion escalate to CAPS and '!' by the memory note's bands, low maturity
 *     degrades the text itself, the extreme low bands are hand-written; bounded and readable;
 *   - knowledge_of_player by context tier, tension bridges;
 *   - selection: a stranger gets no bond lines, must lines survive any cap or budget.
 * The whole prompt on real state is RelDynFeltSteeringPostgresTest.
 */
final class RelDynFeltIntensityTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $key) {
            $this->saved[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function dyn(float $arousal, float $passion, float $maturity, float $valence = 0.0): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['dimensions']['arousal']['x'] = $arousal;
        $d['dimensions']['valence']['x'] = $valence;
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['passion']['x'] = $passion;
        $d['passion'] = $passion;
        return $d;
    }

    /** Letters only, lower case: what a reader recovers from a degraded / emphasized line. */
    private static function plain(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z\' ]/', '', strtolower(str_replace(['...', '..'], ' ', $s)))));
    }

    private function assertReadable(string $original, string $shown, string $why): void
    {
        similar_text(self::plain($original), self::plain($shown), $pct);
        $this->assertGreaterThanOrEqual(85.0, $pct, "{$why}: unreadable\n  {$original}\n  {$shown}");
        $this->assertDoesNotMatchRegularExpression('/!{4,}/', $shown, "{$why}: at most three '!' in a row");
        $this->assertLessThanOrEqual(4, substr_count($shown, '!'), "{$why}: a few '!' per line");
        preg_match_all('/[A-Za-z]+/', $shown, $w);
        $changed = count(array_filter($w[0], fn($x) => strtolower($x) !== $x && ucfirst(strtolower($x)) !== $x));
        $this->assertLessThanOrEqual(8, $changed, "{$why}: CAPS / mixed case stays a few words");
    }

    // ------------------------------------------------------------------ behavioral-keywords

    public function testEveryBandKeywordIsBehaviorNotALabelNumberOrMechanic(): void
    {
        $labels = ['affinity', 'trust', 'comfort', 'respect', 'resentment', 'passion', 'warmth', 'maturity', 'jealousy',
            'walkaway', 'frozen', 'gains', 'internal state', 'hostile', 'contemptuous', 'no strong feelings', 'emotionally intelligent'];
        $n = 0;
        $all = RelationshipDynamics::DIMENSION_BANDS;
        foreach (RelationshipDynamics::MF_QUADRANT_BANDS as $q => $b) $all["mf {$q}"] = [$b];
        foreach (RelationshipDynamics::AROUSAL_VALENCE_BANDS as $q => $b) $all["av {$q}"] = [$b];
        foreach ($all as $dim => $bands) {
            foreach ($bands as $band) {
                $k = (string) $band['keywords'];
                if ($k === '') {
                    $this->assertContains($band['label'], ['Clean'], "{$dim}: only the clean band says nothing");
                    continue;
                }
                $n++;
                $this->assertDoesNotMatchRegularExpression('/\d|%/', $k, "{$dim} {$band['label']}");
                foreach ($labels as $label) {
                    $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($label, '/') . '\b/i', $k,
                        "{$dim} {$band['label']}: '{$label}' is a label, not behavior");
                }
                $this->assertGreaterThanOrEqual(2, count(explode(', ', $k)), "{$dim} {$band['label']}: a few behaviors");
            }
        }
        $this->assertSame(60 + 4 + 4, $n, 'the roadmap scope: the 62 bands less the two silent Clean ones, M/F quadrants, arousal/valence');
        // The roadmap's example, rewritten as what the NPC does
        $this->assertSame('turns away when they approach, answers in single words, wants them gone',
            RelationshipDynamics::DIMENSION_BANDS['affinity'][0]['keywords']);
    }

    public function testEmergentEmotionsAndMaskingAreBehaviorWithoutNumbers(): void
    {
        foreach (RelationshipDynamics::EMERGENT_EMOTIONS as $id => $spec) {
            $this->assertDoesNotMatchRegularExpression('/\d|\bis (infatuated|contempt)|This is|feels trapped/i', $spec['context'], $id);
        }
        $d = $this->dyn(40, 10, 70);
        $d['dimensions']['resentment']['x'] = 60.0;
        $d['dimensions']['comfort']['x'] = 25.0;
        $GLOBALS['CACHE_PEOPLE'] = '|Serana|Kaida|Isran|';
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $text = RelationshipDynamics::generateMaskingContext('Serana', $d, RelationshipDynamics::calculatePerformedState($d));
        unset($GLOBALS['CACHE_PEOPLE'], $GLOBALS['PLAYER_NAME']);
        $this->assertDoesNotMatchRegularExpression('/\d|=|TRUE STATE|PERFORMED/', $text);
        $this->assertStringContainsString('In front of Isran, Serana performs ease; underneath:', $text);
        $this->assertStringContainsString('The mask holds', $text, 'maturity 70: seamless');
    }

    // ------------------------------------------------------------------ intensity-formatting

    public function testFormattingEscalatesByTheMemoryNoteBands(): void
    {
        $k = 'drifts closer than needed, holds their gaze a beat too long, voice drops';
        // Low (0-35): normal text
        $this->assertSame($k, RelationshipDynamics::applyTextIntensity($k, $this->dyn(20, 30, 70)));
        // Medium (36-55): mild punctuation, no CAPS
        $mid = RelationshipDynamics::applyTextIntensity($k, $this->dyn(45, 30, 70));
        $this->assertStringContainsString('!', $mid);
        $this->assertDoesNotMatchRegularExpression('/\b[A-Z]{3,}\b/', $mid);
        // High (56-75): some CAPS and '!'
        $high = RelationshipDynamics::applyTextIntensity($k, $this->dyn(30, 65, 70));
        $this->assertMatchesRegularExpression('/\b[A-Z]{4,}\b/', $high);
        $this->assertStringContainsString('!', $high);
        // Extreme (76-100): FULL CAPS on the first phrase and '!!!'
        $extreme = RelationshipDynamics::applyTextIntensity($k, $this->dyn(90, 20, 70));
        $this->assertStringStartsWith('DRIFTS CLOSER THAN NEEDED!!!', $extreme);
        foreach ([$mid, $high, $extreme] as $shown) $this->assertReadable($k, $shown, 'emphasis');
        // Deterministic: the same state formats the same line the same way
        $this->assertSame($extreme, RelationshipDynamics::applyTextIntensity($k, $this->dyn(90, 20, 70)));
    }

    public function testLowMaturityDegradesTheTextItselfBoundedAndReadable(): void
    {
        $k = 'visibly frustrated, kind words from them no longer land, pulls back emotionally';
        $this->assertSame($k, RelationshipDynamics::degradeText($k, 70), 'mature: coherent');
        $moderate = RelationshipDynamics::degradeText($k, 30);
        $heavy = RelationshipDynamics::degradeText($k, 10);
        foreach ([$moderate, $heavy] as $shown) {
            $this->assertNotSame($k, $shown);
            $this->assertMatchesRegularExpression('/_|\b[a-z]+[A-Z]/', $shown, 'missing letters / chaotic casing');
            $this->assertReadable($k, $shown, 'degradation');
        }
        $this->assertStringContainsString('..', $heavy, 'heavy: the thought breaks off');
        $this->assertGreaterThanOrEqual(substr_count($moderate, '_') + preg_match_all('/[a-z][A-Z]/', $moderate),
            substr_count($heavy, '_') + preg_match_all('/[a-z][A-Z]/', $heavy), 'heavy degrades at least as much');
        // Short words are never touched (only the phrase break trails off)
        $this->assertSame('a bit off.. no', RelationshipDynamics::degradeText('a bit off, no', 5), 'no word long enough to break');
    }

    public function testTheExtremeLowBandsAreHandWrittenAndNotDegradedTwice(): void
    {
        $chaotic = RelationshipDynamics::getDimensionBand('maturity', 5)['keywords'];
        $this->assertStringContainsString('..', $chaotic, 'hand-degraded');
        $this->assertStringContainsString('TAKES', $chaotic);
        $calm = $this->dyn(20, 10, 5);
        $this->assertSame($chaotic, RelDynFelt::intensify($chaotic, $calm, true), 'hand-written: no automatic degradation');
        $this->assertNotSame($chaotic, RelDynFelt::intensify($chaotic, $calm, false));
    }

    public function testNumbHollowTextTrailsOffAndCalmIsLeftAlone(): void
    {
        $k = 'polite and brief, keeps to business, offers nothing personal';
        $this->assertSame($k, RelationshipDynamics::applyTextIntensity($k, $this->dyn(5, 5, 70, 10)), 'resting calm is not hollow');
        $numb = RelationshipDynamics::applyTextIntensity($k, $this->dyn(5, 5, 70, -60));
        $this->assertStringContainsString('...', $numb);
        $this->assertStringEndsWith('hard to tell', $numb);
    }

    // ------------------------------------------------------------------ knowledge_of_player

    private function atCore(float $coreAff, array $dims = [], int $hwm = 0): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $mirror = ($coreAff + 100) / 2;
        $d['dimensions']['affinity']['x'] = $mirror;
        $d['_aff_mirror_x'] = $mirror;
        $d['context_tier_hwm'] = $hwm;
        foreach ($dims as $k => $v) $d['dimensions'][$k]['x'] = $v;
        return $d;
    }

    public function testKnowledgeOfPlayerFollowsTheTierAndAStrangerDoesNotKnowTheName(): void
    {
        $stranger = RelDynFelt::knowledgeOfPlayer('Brelyna', 'Kaida', $this->atCore(0));
        $this->assertStringContainsString('stranger', $stranger);
        $this->assertStringNotContainsString('Kaida', $stranger);
        $hostile = RelDynFelt::knowledgeOfPlayer('Nazeem', 'Kaida', $this->atCore(-60));
        $this->assertStringContainsString('trouble', $hostile);
        $this->assertStringNotContainsString('Kaida', $hostile);
        $this->assertStringContainsString('by name', RelDynFelt::knowledgeOfPlayer('Aela', 'Kaida', $this->atCore(15)));
        $this->assertStringContainsString('knows Kaida well', RelDynFelt::knowledgeOfPlayer('Lydia', 'Kaida', $this->atCore(45)));
        $this->assertStringContainsString('deeply', RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(85)));
        // High-water mark: a friend the player fell out with still knows them, and it cuts
        $lapsed = RelDynFelt::knowledgeOfPlayer('Lydia', 'Kaida', $this->atCore(0, [], 2));
        $this->assertStringContainsString('exactly why it cuts', $lapsed);
        $this->assertSame(RelDynFelt::playerRef('Kaida', 0, $this->atCore(0)), 'this stranger');
        $this->assertSame(RelDynFelt::playerRef('Kaida', 0, $this->atCore(-60)), 'this person');
        $this->assertSame(RelDynFelt::playerRef('Kaida', 1, $this->atCore(15)), 'Kaida');
    }

    public function testTensionBridges(): void
    {
        $this->assertStringContainsString('fighting it',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['warmth' => 30.0, 'passion' => 70.0])));
        $this->assertStringContainsString('lets show',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(80, ['warmth' => 20.0])));
        $this->assertStringContainsString('one eye open',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(45, ['warmth' => 60.0, 'trust' => 20.0])));
        $this->assertStringContainsString('worn thin',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(60, ['warmth' => 60.0, 'resentment' => 70.0])));
        $this->assertStringContainsString('never quite at ease',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(60, ['warmth' => 60.0, 'trust' => 60.0, 'comfort' => 20.0])));
        $this->assertStringContainsString('nothing left to prove',
            RelDynFelt::knowledgeOfPlayer('Serana', 'Kaida', $this->atCore(85, ['warmth' => 70.0, 'trust' => 80.0, 'resentment' => 0.0])));
    }

    // ------------------------------------------------------------------ selection

    private function lines(): array
    {
        $mk = fn($key, $scope, $sal, $text, $extra = []) => array_merge(['key' => $key, 'scope' => $scope, 'lane' => 'core',
            'salience' => $sal, 'must' => false, 'intense' => false, 'handwritten' => false, 'tier0' => false, 'text' => $text], $extra);
        return [
            $mk('trust', 'bond', 0.4, 'shares personal matters, turns to them in danger'),
            $mk('place', 'self', 0.6, 'All this dusty learning makes her restless.', ['lane' => 'turn']),
            $mk('attraction', 'bond', 0.5, 'Her eyes keep finding the player.', ['tier0' => true]),
            $mk('fulfillment_boundary', 'bond', 1.0, 'She says it plainly, once.', ['must' => true, 'lane' => 'turn']),
            $mk('weather', 'self', 0.3, 'a little flat, sighs'),
        ];
    }

    public function testAStrangerGetsOwnStateOnlyAndMustLinesSurviveCapsAndBudget(): void
    {
        $d = $this->atCore(0);
        $keys = array_column(RelDynFelt::select('Aela', 'this stranger', $this->lines(), 0, $d), 'key');
        $this->assertNotContains('trust', $keys, 'no bond behavior toward a stranger');
        $this->assertContains('attraction', $keys, 'a first-sight read is allowed');
        $this->assertSame('fulfillment_boundary', $keys[0], 'must first');

        $cfg = RelDynFelt::config();
        $cfg['tier_max_lines'][2] = 1;
        $cfg['tier_token_budget'][2] = 5;
        $kept = RelDynFelt::select('Aela', 'Kaida', $this->lines(), 2, $this->atCore(45), $cfg);
        $this->assertSame(['fulfillment_boundary'], array_column($kept, 'key'), 'cap and budget never drop a must line');

        $sorted = array_column(RelDynFelt::select('Aela', 'Kaida', $this->lines(), 2, $this->atCore(45)), 'key');
        $this->assertSame(['fulfillment_boundary', 'place', 'attraction', 'trust', 'weather'], $sorted, 'by salience');
        [$core, $rest] = RelDynFelt::splitCore(RelDynFelt::select('Aela', 'Kaida', $this->lines(), 2, $this->atCore(45)), 2);
        $this->assertSame(['attraction', 'trust'], array_column($core, 'key'), 'the enduring lines go to the character block');
        $this->assertSame(['fulfillment_boundary', 'place', 'weather'], array_column($rest, 'key'));
    }

    public function testTokenEstimateCountsCapsHigher(): void
    {
        $this->assertSame(3, RelDynFelt::estimateTokens('holds their gaze'));
        $this->assertGreaterThan(RelDynFelt::estimateTokens('holds their gaze'), RelDynFelt::estimateTokens('HOLDS THEIR GAZE!!!'));
    }
}
