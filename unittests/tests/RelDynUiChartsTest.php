<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_ui_charts.php';

/**
 * player-profile-page / full-parameter-editor: the shared inline-SVG charts
 * (ext/relationship_dynamics/reldyn_ui_charts.php). Pure renderers: well-formed standalone SVG,
 * every drawn text escaped, no script or external reference, values clamped, deterministic.
 */
final class RelDynUiChartsTest extends TestCase
{
    private static function xml(string $svg): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($svg);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        self::assertNotFalse($x, "not well-formed XML:\n" . implode("\n", array_map(fn($e) => trim($e->message), $errors)) . "\n" . substr($svg, 0, 400));
        $x->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');
        return $x;
    }

    /** No script, no event handler, no link or external resource anywhere in the markup. */
    private static function assertInert(string $svg): void
    {
        $x = self::xml($svg);
        self::assertSame([], $x->xpath('//s:script | //script'), 'no script element');
        self::assertSame([], $x->xpath('//@*[starts-with(translate(name(), "ON", "on"), "on")]'), 'no event-handler attribute');
        self::assertSame([], $x->xpath('//@*[contains(name(), "href") or name() = "src"]'), 'no link');
        self::assertDoesNotMatchRegularExpression('/<\s*script/i', $svg);
        self::assertSame(substr_count($svg, '<svg '), substr_count($svg, 'http'), 'only the SVG namespace URI');
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
    }

    private static function points(SimpleXMLElement $polygon): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim((string) $polygon['points'])) as $p) {
            [$x, $y] = array_map('floatval', explode(',', $p));
            $out[] = [$x, $y];
        }
        return $out;
    }

    public function testRadarIsAWellFormedInertSvgWithRingsSpokesAndOnePolygonPerSeries(): void
    {
        $axes = [];
        foreach (['Maturity', 'Trust Rating', 'Warmth', 'Respect Rating', 'Comfort Effect', 'Self-Confidence'] as $l) $axes[] = ['label' => $l, 'sub' => '50'];
        $svg = RelDynUiCharts::radar($axes, [['name' => 'You', 'values' => [0.5, 0.6, 0.7, 0.8, 0.9, 1.0]]], ['title' => 'Test']);
        self::assertInert($svg);
        $x = self::xml($svg);
        self::assertSame('Test', (string) $x->title);
        // five rings + one data polygon, six spokes, six labels (+ six sub-labels)
        self::assertCount(6, $x->xpath('//s:polygon'));
        self::assertCount(6, $x->xpath('//s:line'));
        self::assertCount(6, $x->xpath('//s:circle'));
        $labels = array_map('strval', $x->xpath('//s:text[@font-weight="600"]'));
        self::assertSame(array_column($axes, 'label'), $labels);
        $data = $x->xpath('//s:polygon[s:title="You"]')[0];
        self::assertCount(6, self::points($data));
        self::assertSame($svg, RelDynUiCharts::radar($axes, [['name' => 'You', 'values' => [0.5, 0.6, 0.7, 0.8, 0.9, 1.0]]], ['title' => 'Test']), 'deterministic');
    }

    public function testRadarValuesAreClampedToTheCentreAndTheEdge(): void
    {
        $axes = [['label' => 'a'], ['label' => 'b'], ['label' => 'c'], ['label' => 'd']];
        $x = self::xml(RelDynUiCharts::radar($axes, [['name' => 's', 'values' => [-3, 7, NAN, 'x']]], ['radius' => 100]));
        $rings = $x->xpath('//s:polygon');
        $outer = self::points($rings[4]);          // the 1.0 ring
        $data = self::points($x->xpath('//s:polygon[s:title="s"]')[0]);
        $vb = array_map('floatval', explode(' ', (string) $x['viewBox']));
        $cx = $vb[2] / 2;
        // -3 -> the centre, 7 -> the edge, NaN and a non-number -> the centre
        self::assertEqualsWithDelta($cx, $data[0][0], 0.1);
        self::assertEqualsWithDelta($outer[1][0], $data[1][0], 0.1);
        self::assertEqualsWithDelta($outer[1][1], $data[1][1], 0.1);
        self::assertEqualsWithDelta($cx, $data[2][0], 0.1);
        self::assertEqualsWithDelta($cx, $data[3][0], 0.1);
    }

    public function testEveryDrawnTextAndTheTitleAreEscapedAndAHostileColourFallsBack(): void
    {
        $evil = '<script>alert(1)</script>" onload="x';
        $svg = RelDynUiCharts::radar(
            [['label' => $evil, 'sub' => $evil], ['label' => 'b & c'], ['label' => "d'e"]],
            [['name' => $evil, 'values' => [0.5, 0.5, 0.5], 'stroke' => '"/><script>alert(2)</script>', 'fill' => 'url(http://evil)']],
            ['title' => $evil, 'ring_labels' => [[0.5, $evil], 'junk'], 'id' => '"><x']
        );
        self::assertInert($svg);
        $x = self::xml($svg);
        self::assertSame($evil, (string) $x->title, 'the title round-trips as text');
        self::assertSame($evil, (string) $x['aria-label']);
        self::assertContains('b & c', array_map('strval', $x->xpath('//s:text')));
        self::assertContains("d'e", array_map('strval', $x->xpath('//s:text')));
        self::assertSame(RelDynUiCharts::INK['accent'], (string) $x->xpath('//s:polygon[s:title]')[0]['stroke']);
        self::assertSame('x', (string) $x['id'], 'id reduced to safe characters');
    }

    public function testFewerThanThreeAxesDrawBarsAndNoAxesSayNothingYet(): void
    {
        $x = self::xml(RelDynUiCharts::radar([['label' => 'only'], ['label' => 'two']], [['name' => 's', 'values' => [1.0, 0.25]]]));
        self::assertCount(0, $x->xpath('//s:polygon'));
        $rects = $x->xpath('//s:rect');
        self::assertCount(4, $rects, 'a track and a bar per axis');
        self::assertEqualsWithDelta(230.0, floatval($rects[1]['width']), 0.01);
        self::assertEqualsWithDelta(57.5, floatval($rects[3]['width']), 0.01);
        $empty = self::xml(RelDynUiCharts::radar([], [], ['empty' => 'No needs known yet.']));
        self::assertStringContainsString('No needs known yet.', (string) $empty->asXML());
    }

    public function testPlayerRadarShowsTheSixMirrorAxesWithValueAndTrendAndTheNeutralRing(): void
    {
        $spider = ['axes' => [
            ['axis' => 'maturity', 'label' => 'Maturity', 'value' => 68.4, 'trend' => 'up'],
            ['axis' => 'trust', 'label' => 'Trust Rating', 'value' => 62.0, 'trend' => 'stable'],
            ['axis' => 'warmth', 'label' => 'Warmth', 'value' => 65.0, 'trend' => null],
            ['axis' => 'respect', 'label' => 'Respect Rating', 'value' => 70.0, 'trend' => 'down'],
            ['axis' => 'comfort', 'label' => 'Comfort Effect', 'value' => 55.0, 'trend' => null],
            ['axis' => 'confidence', 'label' => 'Self-Confidence', 'value' => 25.0, 'trend' => null],
        ]];
        $x = self::xml(RelDynUiCharts::playerRadar($spider));
        $texts = array_map('strval', $x->xpath('//s:text'));
        foreach (['Maturity', 'Trust Rating', 'Self-Confidence', '68 ↑', '62 →', '70 ↓', '25', '50'] as $t) {
            self::assertContains($t, $texts, $t);
        }
    }

    public function testFulfillmentSpiderDrawsNeedsRelativeToTheLargestAndCoverageFromCentreToEdge(): void
    {
        $graph = ['npc' => 'Aela the Huntress', 'axes' => [
            ['axis' => 'combat', 'label' => 'a hunt or a fight beside her', 'need' => 0.4, 'coverage' => 1.0],
            ['axis' => 'physical_intimacy', 'label' => 'closeness', 'need' => 0.2, 'coverage' => -1.0],
            ['axis' => 'quality_time', 'label' => 'time together', 'need' => 0.1, 'coverage' => 0.0],
        ]];
        $svg = RelDynUiCharts::fulfillmentSpider($graph, ['radius' => 100]);
        self::assertInert($svg);
        $x = self::xml($svg);
        self::assertSame('Aela the Huntress: needs and how well they are met', (string) $x->title);
        $outer = self::points($x->xpath('//s:polygon')[4]);
        $vb = array_map('floatval', explode(' ', (string) $x['viewBox']));
        $cx = $vb[2] / 2;
        $need = self::points($x->xpath('//s:polygon[s:title="needs"]')[0]);
        $cover = self::points($x->xpath('//s:polygon[s:title="what the bond covers"]')[0]);
        self::assertEqualsWithDelta($outer[0][1], $need[0][1], 0.1, 'her largest need reaches the edge');
        self::assertEqualsWithDelta($outer[0][1], $cover[0][1], 0.1, 'coverage +1 is the edge');
        self::assertEqualsWithDelta($cx, $cover[1][0], 0.1, 'coverage -1 is the centre');
        $mid = [($outer[2][0] + $cx) / 2];
        self::assertEqualsWithDelta($mid[0], $cover[2][0], 0.2, 'coverage 0 is the middle ring');
        $texts = array_map('strval', $x->xpath('//s:text'));
        self::assertContains('need 0.4 · +1', $texts);
        self::assertContains('need 0.2 · -1', $texts);
        self::assertContains('even', $texts);
        self::assertContains('needs', $texts, 'legend');
    }

    public function testTheCardIsAStandaloneSvgDocumentWithItsTextEscapedAndNoExternalReference(): void
    {
        $card = [
            'title' => 'Kaida <b>&</b>',
            'subtitle' => 'Behavioral mirror // 60 interactions with 4 people',
            'badge' => 'THE ROCK',
            'spider' => ['axes' => [['label' => 'Maturity', 'value' => 60], ['label' => 'Trust Rating', 'value' => 70], ['label' => 'Warmth', 'value' => 65]]],
            'tiles' => [['label' => 'Charisma', 'value' => 'The Rock', 'sub' => 'style'], ['label' => 'Attachment', 'value' => 'Anxious → Secure (in transition, 68%)', 'sub' => '']],
            'quote' => 'Dependable, follows through, good to their word. Steady under pressure; people lean on that calm.',
        ];
        $svg = RelDynUiCharts::card($card);
        self::assertInert($svg);
        $x = self::xml($svg);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $x['width']);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $x['height']);
        $texts = array_map('strval', $x->xpath('//s:text'));
        self::assertContains('Kaida <b>&</b>', $texts);
        self::assertContains('THE ROCK', $texts);
        self::assertContains('CHARISMA', $texts);
        self::assertContains('Anxious → Secure (in transition, 68%)', $texts);
        self::assertContains('CHIM // RELATIONSHIP DYNAMICS', $texts);
        self::assertCount(1, $x->xpath('/s:svg/s:svg'), 'the radar nested inside the card');
        self::assertStringStartsWith('“', implode(' ', array_filter($texts, fn($t) => str_contains($t, 'Dependable'))));
    }

    public function testNumbersAreLocaleIndependentAndWrapKeepsWords(): void
    {
        $prev = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');
        try {
            self::assertSame('12.5', RelDynUiCharts::num(12.5));
            self::assertSame('0', RelDynUiCharts::num(-0.01));
            self::assertSame('0', RelDynUiCharts::num(INF));
        } finally {
            setlocale(LC_NUMERIC, $prev ?: 'C');
        }
        self::assertSame(['one two', 'three'], RelDynUiCharts::wrap('one two three', 8));
        self::assertCount(2, RelDynUiCharts::wrap('aaa bbb ccc ddd eee fff', 8, 2));
        self::assertStringEndsWith('…', RelDynUiCharts::wrap('aaa bbb ccc ddd eee fff', 8, 2)[1]);
    }

    public function testSparklineDrawsAPolylineFromTwoValues(): void
    {
        $x = self::xml(RelDynUiCharts::sparkline([50, 60, 200, 'x']));
        $pl = $x->xpath('//s:polyline');
        self::assertCount(1, $pl);
        self::assertCount(3, preg_split('/\s+/', trim((string) $pl[0]['points'])));
        self::assertCount(0, self::xml(RelDynUiCharts::sparkline([50]))->xpath('//s:polyline'));
    }
}
