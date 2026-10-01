<?php
/**
 * Relationship Dynamics — shared UI charts: inline SVG, no external libraries, no script
 * (roadmap player-profile-page, full-parameter-editor; memory project_player_profile_spider:
 * Rangroo's shareable spider graph).
 *
 * Every renderer here is pure (strings in, an SVG string out), escapes every text it draws, and
 * draws with presentation attributes only (no CSS classes needed), so the same SVG works inline
 * on a page, saved as a standalone .svg file, or pasted into an HTML snippet.
 *
 *   radar()             generic spider graph: axes, one or more series of 0..1 fractions.
 *   playerRadar()       the player mirror's six dimensions (RelDynMirror::spider() axes, 0..100).
 *   fulfillmentSpider() one NPC's needs vs how much of each the relationship covers
 *                       (RelDynFulfillment::graph(): need weight 0..1, coverage -1..1); for the
 *                       player page and the per-NPC editor.
 *   card()              the shareable profile card (a whole standalone SVG document).
 *   sparkline()         a small trend line (0..100 values).
 *
 * Numbers are fine here (decisions §3: "feelings, not numbers" is for LLM text only).
 */

final class RelDynUiCharts
{
    /** Colours (CHIM 3.4.1 UI: dark ground, orange accent). */
    const INK = [
        'ground'  => '#1a1a1a',
        'panel'   => '#242424',
        'line'    => '#3a3a3a',
        'ring'    => '#4a4a4a',
        'text'    => '#e0e0e0',
        'muted'   => '#9fb1c9',
        'accent'  => '#f27c11',   // rgb(242, 124, 17), CHIM's heading orange
        'accent2' => '#5ab0f0',
        'good'    => '#5cc98a',
        'bad'     => '#e06666',
    ];

    /** HTML/XML-escape (text and attribute values). */
    public static function esc($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    /** Locale-independent number for SVG coordinates and labels. */
    public static function num(float $v, int $dp = 1): string
    {
        if (!is_finite($v)) $v = 0.0;
        $s = number_format($v, $dp, '.', '');
        if ($dp > 0) $s = rtrim(rtrim($s, '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    private static function frac($v): float
    {
        $v = is_numeric($v) ? floatval($v) : 0.0;
        if (!is_finite($v)) return 0.0;
        return max(0.0, min(1.0, $v));
    }

    /** A colour the caller passed, or the default: only #hex and rgb()/rgba() pass. */
    private static function colour($c, string $default): string
    {
        $c = trim((string) $c);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) || preg_match('/^rgba?\(\s*[0-9.\s,%]+\)$/', $c)) return $c;
        return $default;
    }

    /**
     * Spider graph.
     *
     * @param array $axes   list of ['label' => string, 'sub' => ?string (second line, e.g. "68 ↑")]
     * @param array $series list of ['name' => string, 'values' => list of 0..1 (one per axis),
     *                      'stroke' => colour, 'fill' => colour, 'fill_opacity' => 0..1, 'dash' => bool]
     * @param array $opts   'title' (accessible name), 'radius' (px, 120), 'rings' (fractions),
     *                      'ring_labels' (list of [fraction, text]), 'legend' (bool), 'standalone' (xmlns + size),
     *                      'font' / 'sub_font' / 'ring_font' / 'legend_font' (px in the drawing, 12 / 10 / 9 / 11),
     *                      'label_wrap' (characters per line, 0 = one line) with 'label_lines' (3), 'auto_pad'
     *                      (the room around the rim follows the labels, so none is cut off)
     * Fewer than three axes cannot make a polygon: the same data as horizontal bars.
     */
    public static function radar(array $axes, array $series, array $opts = []): string
    {
        $axes = array_values(array_filter($axes, 'is_array'));
        $n = count($axes);
        $title = (string) ($opts['title'] ?? 'Spider graph');
        if ($n < 3) return self::bars($axes, $series, $opts);

        $r = max(40.0, floatval($opts['radius'] ?? 120));
        $font = max(6.0, floatval($opts['font'] ?? 12));
        $subFont = max(6.0, floatval($opts['sub_font'] ?? 10));
        $ringFont = max(6.0, floatval($opts['ring_font'] ?? 9));
        $legend = !empty($opts['legend']) && count($series) > 1;
        $blocks = self::axisLabels($axes, $r, $font, $subFont, intval($opts['label_wrap'] ?? 0), intval($opts['label_lines'] ?? 3));
        if (!empty($opts['auto_pad'])) {
            $padX = $blocks['pad_x'];
            $padTop = $blocks['pad_top'];
            $padBottom = $blocks['pad_bottom'];
        } else {
            $padX = floatval($opts['pad_x'] ?? 118);
            $padTop = $padBottom = floatval($opts['pad_y'] ?? 44);
        }
        $w = 2 * ($r + $padX);
        $h = 2 * $r + $padTop + $padBottom + ($legend ? 26 : 0);
        $cx = $w / 2;
        $cy = $r + $padTop;
        $at = function (int $i, float $f) use ($n, $r, $cx, $cy): array {
            $a = -M_PI / 2 + 2 * M_PI * $i / $n;
            return [$cx + cos($a) * $r * $f, $cy + sin($a) * $r * $f];
        };
        $pt = fn(array $p) => self::num($p[0]) . ',' . self::num($p[1]);

        $out = self::svgOpen($w, $h, $title, $opts);
        // rings and spokes
        $rings = is_array($opts['rings'] ?? null) ? $opts['rings'] : [0.2, 0.4, 0.6, 0.8, 1.0];
        $out .= '<g fill="none" stroke="' . self::INK['ring'] . '" stroke-width="1">';
        foreach ($rings as $f) {
            $f = self::frac($f);
            if ($f <= 0) continue;
            $pts = [];
            for ($i = 0; $i < $n; $i++) $pts[] = $pt($at($i, $f));
            $out .= '<polygon points="' . implode(' ', $pts) . '" stroke-opacity="' . ($f >= 1.0 ? '0.9' : '0.45') . '"/>';
        }
        for ($i = 0; $i < $n; $i++) {
            [$x, $y] = $at($i, 1.0);
            $out .= '<line x1="' . self::num($cx) . '" y1="' . self::num($cy) . '" x2="' . self::num($x) . '" y2="' . self::num($y) . '" stroke-opacity="0.45"/>';
        }
        $out .= '</g>';
        foreach ((array) ($opts['ring_labels'] ?? []) as $rl) {
            if (!is_array($rl) || count($rl) < 2) continue;
            [$x, $y] = $at(0, self::frac($rl[0]));
            $label = $rl[1];
            $out .= '<text x="' . self::num($x + 4) . '" y="' . self::num($y + 10) . '" font-size="' . self::num($ringFont) . '" fill="' . self::INK['muted']
                . '" font-family="monospace">' . self::esc($label) . '</text>';
        }
        // series
        foreach (array_values($series) as $k => $s) {
            if (!is_array($s)) continue;
            $vals = array_values((array) ($s['values'] ?? []));
            $stroke = self::colour($s['stroke'] ?? null, $k === 0 ? self::INK['accent'] : self::INK['accent2']);
            $fill = self::colour($s['fill'] ?? null, $stroke);
            $pts = [];
            for ($i = 0; $i < $n; $i++) $pts[] = $pt($at($i, self::frac($vals[$i] ?? 0)));
            $out .= '<polygon points="' . implode(' ', $pts) . '" fill="' . $fill . '" fill-opacity="'
                . self::num(self::frac($s['fill_opacity'] ?? 0.22), 2) . '" stroke="' . $stroke . '" stroke-width="2" stroke-linejoin="round"'
                . (!empty($s['dash']) ? ' stroke-dasharray="5 4"' : '') . '><title>' . self::esc($s['name'] ?? '') . '</title></polygon>';
            if (empty($s['dash'])) {
                for ($i = 0; $i < $n; $i++) {
                    [$x, $y] = $at($i, self::frac($vals[$i] ?? 0));
                    $out .= '<circle cx="' . self::num($x) . '" cy="' . self::num($y) . '" r="3" fill="' . $stroke . '" stroke="' . self::INK['text'] . '" stroke-width="0.8"/>';
                }
            }
        }
        // axis labels (a long one wraps onto up to 'label_lines' lines, inside one text element)
        $lh = $font + 3.0;
        foreach ($blocks['blocks'] as $i => $b) {
            $x = $cx + $b['x'];
            $y0 = $cy + $b['y'];
            $out .= '<text x="' . self::num($x) . '" y="' . self::num($y0) . '" text-anchor="' . $b['anchor']
                . '" font-family="sans-serif" font-size="' . self::num($font) . '" font-weight="600" fill="' . self::INK['text'] . '">';
            if (count($b['lines']) === 1) {
                $out .= self::esc($b['lines'][0]);
            } else {
                foreach ($b['lines'] as $k => $line) {
                    $out .= '<tspan x="' . self::num($x) . '"' . ($k > 0 ? ' dy="' . self::num($lh) . '"' : '') . '>' . self::esc($line) . '</tspan>';
                }
            }
            $out .= '</text>';
            $sub = (string) ($axes[$i]['sub'] ?? '');
            if ($sub !== '') {
                $out .= '<text x="' . self::num($x) . '" y="' . self::num($cy + $b['sub_y']) . '" text-anchor="' . $b['anchor']
                    . '" font-family="monospace" font-size="' . self::num($subFont) . '" fill="' . self::colour($axes[$i]['sub_colour'] ?? null, self::INK['muted']) . '">' . self::esc($sub) . '</text>';
            }
        }
        if ($legend) $out .= self::legend($series, 12.0, $h - 10.0, floatval($opts['legend_font'] ?? 11));
        return $out . '</svg>';
    }

    /**
     * Where each axis label goes, relative to the centre, and how much room the drawing needs around
     * the rim so no label is cut off: ['blocks' => [i => lines, anchor, x, y (first baseline), sub_y],
     * 'pad_x', 'pad_top', 'pad_bottom']. Text width is estimated (about 0.6 em a character).
     */
    private static function axisLabels(array $axes, float $r, float $font, float $subFont, int $wrap, int $maxLines): array
    {
        $n = count($axes);
        $lh = $font + 3.0;
        $blocks = [];
        $padX = $padTop = $padBottom = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = -M_PI / 2 + 2 * M_PI * $i / $n;
            $cos = cos($a);
            $sin = sin($a);
            $rx = $cos * ($r + 16.0);
            $ry = $sin * ($r + 16.0);
            $anchor = abs($cos) < 0.15 ? 'middle' : ($cos > 0 ? 'start' : 'end');
            $dy = $sin < -0.5 ? -10.0 : ($sin > 0.5 ? 6.0 : -2.0);
            $label = (string) ($axes[$i]['label'] ?? '');
            $lines = $wrap > 0 ? self::wrap($label, $wrap, max(1, $maxLines)) : [$label];
            if ($lines === []) $lines = [''];
            $count = count($lines);
            $y0 = $sin < -0.5 ? $ry + $dy - ($count - 1) * $lh : ($sin > 0.5 ? $ry + $dy : $ry + $dy - ($count - 1) * $lh / 2);
            $last = $y0 + ($count - 1) * $lh;
            $subY = $last + $subFont + 4.0;
            $sub = (string) ($axes[$i]['sub'] ?? '');
            $width = max(0.6 * $font * max(array_map('mb_strlen', $lines)), $sub !== '' ? 0.6 * $subFont * mb_strlen($sub) : 0.0);
            $reach = $anchor === 'middle' ? $width / 2 : ($anchor === 'start' ? $rx + $width : -$rx + $width);
            $padX = max($padX, $reach - $r + 6.0);
            $padTop = max($padTop, -($y0 - 0.9 * $font) - $r + 6.0);
            $padBottom = max($padBottom, ($sub !== '' ? $subY : $last) + 0.35 * $font - $r + 6.0);
            $blocks[$i] = ['lines' => $lines, 'anchor' => $anchor, 'x' => $rx, 'y' => $y0, 'sub_y' => $subY];
        }
        return ['blocks' => $blocks, 'pad_x' => max(24.0, $padX), 'pad_top' => max(12.0, $padTop), 'pad_bottom' => max(12.0, $padBottom)];
    }

    /** Legend row: a swatch and the series name per series. */
    private static function legend(array $series, float $x, float $y, float $font = 11.0): string
    {
        $out = '<g font-family="sans-serif" font-size="' . self::num($font) . '" fill="' . self::INK['text'] . '">';
        foreach (array_values($series) as $k => $s) {
            if (!is_array($s)) continue;
            $stroke = self::colour($s['stroke'] ?? null, $k === 0 ? self::INK['accent'] : self::INK['accent2']);
            $out .= '<rect x="' . self::num($x) . '" y="' . self::num($y - 9) . '" width="14" height="10" fill="' . $stroke . '" fill-opacity="0.5" stroke="' . $stroke . '"'
                . (!empty($s['dash']) ? ' stroke-dasharray="3 2"' : '') . '/>';
            $name = (string) ($s['name'] ?? '');
            $out .= '<text x="' . self::num($x + 20) . '" y="' . self::num($y) . '">' . self::esc($name) . '</text>';
            $x += 34 + 6.5 * ($font / 11.0) * mb_strlen($name);
        }
        return $out . '</g>';
    }

    /** The radar's data as horizontal bars (fewer than three axes). */
    private static function bars(array $axes, array $series, array $opts): string
    {
        $n = max(1, count($axes));
        $rows = $n * max(1, count($series));
        $w = 420.0;
        $h = 16.0 + 22.0 * $rows;
        $out = self::svgOpen($w, $h, (string) ($opts['title'] ?? 'Chart'), $opts);
        if ($axes === []) {
            return $out . '<text x="12" y="24" font-family="sans-serif" font-size="12" fill="' . self::INK['muted'] . '">'
                . self::esc($opts['empty'] ?? 'Nothing to show yet.') . '</text></svg>';
        }
        $y = 12.0;
        foreach ($axes as $i => $axis) {
            foreach (array_values($series) as $k => $s) {
                $v = self::frac(((array) ($s['values'] ?? []))[$i] ?? 0);
                $stroke = self::colour($s['stroke'] ?? null, $k === 0 ? self::INK['accent'] : self::INK['accent2']);
                $out .= '<text x="8" y="' . self::num($y + 11) . '" font-family="sans-serif" font-size="11" fill="' . self::INK['text'] . '">'
                    . self::esc(($axis['label'] ?? '') . (count($series) > 1 ? ' (' . ($s['name'] ?? '') . ')' : '')) . '</text>';
                $out .= '<rect x="170" y="' . self::num($y + 2) . '" width="230" height="12" fill="' . self::INK['panel'] . '" stroke="' . self::INK['ring'] . '"/>';
                $out .= '<rect x="170" y="' . self::num($y + 2) . '" width="' . self::num(230 * $v) . '" height="12" fill="' . $stroke . '"/>';
                $y += 22;
            }
        }
        return $out . '</svg>';
    }

    /** <svg ...> with viewBox, an accessible name and (standalone) the namespace and pixel size. */
    private static function svgOpen(float $w, float $h, string $title, array $opts): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['id'] ?? ''));
        $size = !empty($opts['standalone'])
            ? ' width="' . self::num($w, 0) . '" height="' . self::num($h, 0) . '"'
            : ' width="100%" style="max-width:' . self::num($w, 0) . 'px;height:auto;display:block;margin:0 auto"';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::num($w, 0) . ' ' . self::num($h, 0) . '"' . $size
            . ($id !== '' ? ' id="' . $id . '"' : '') . ' role="img" aria-label="' . self::esc($title) . '"><title>' . self::esc($title) . '</title>';
    }

    /** Trend arrow for a mirror trend ('up' | 'down' | 'stable' | null). */
    public static function arrow(?string $trend): string
    {
        return $trend === 'up' ? '↑' : ($trend === 'down' ? '↓' : ($trend === 'stable' ? '→' : ''));
    }

    /**
     * The player mirror's spider graph: RelDynMirror::spider() axes (value 0..100, trend), one
     * series; the neutral ring (50) marked.
     */
    public static function playerRadar(array $spider, array $opts = []): string
    {
        $axes = [];
        $vals = [];
        foreach ((array) ($spider['axes'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $v = is_numeric($a['value'] ?? null) ? floatval($a['value']) : 50.0;
            $axes[] = ['label' => (string) ($a['label'] ?? $a['axis'] ?? ''), 'sub' => trim(self::num($v, 0) . ' ' . self::arrow($a['trend'] ?? null))];
            $vals[] = $v / 100.0;
        }
        return self::radar($axes, [['name' => (string) ($opts['series_name'] ?? 'You'), 'values' => $vals]],
            $opts + ['title' => 'Player profile spider graph', 'ring_labels' => [[0.5, '50']]]);
    }

    /**
     * One relationship's fulfillment (RelDynFulfillment::graph): what the NPC needs (need weight,
     * drawn relative to her largest need, dashed) against what the relationship covers (coverage
     * -1..1, drawn 0 at the centre, the middle ring = even, the edge = fully met).
     */
    public static function fulfillmentSpider(array $graph, array $opts = []): string
    {
        $axes = [];
        $need = [];
        $cover = [];
        $max = 0.0;
        foreach ((array) ($graph['axes'] ?? []) as $a) {
            if (is_array($a)) $max = max($max, floatval($a['need'] ?? 0));
        }
        foreach ((array) ($graph['axes'] ?? []) as $a) {
            if (!is_array($a)) continue;
            $c = max(-1.0, min(1.0, floatval($a['coverage'] ?? 0)));
            $axes[] = ['label' => (string) ($a['label'] ?? $a['axis'] ?? ''),
                'sub' => 'need ' . self::num(floatval($a['need'] ?? 0), 2) . ' · ' . ($c >= 0 ? '+' : '') . self::num($c, 2),
                'sub_colour' => $c < -0.25 ? self::INK['bad'] : ($c > 0.25 ? self::INK['good'] : self::INK['muted'])];
            $need[] = $max > 0 ? floatval($a['need'] ?? 0) / $max : 0.0;
            $cover[] = ($c + 1.0) / 2.0;
        }
        $npc = (string) ($graph['npc'] ?? '');
        return self::radar($axes, [
            ['name' => 'needs', 'values' => $need, 'stroke' => self::INK['accent2'], 'fill_opacity' => 0.08, 'dash' => true],
            ['name' => 'what the bond covers', 'values' => $cover, 'stroke' => self::INK['accent'], 'fill_opacity' => 0.25],
        ], $opts + ['title' => ($npc !== '' ? "{$npc}: " : '') . 'needs and how well they are met', 'legend' => true,
            'ring_labels' => [[0.5, 'even'], [1.0, 'met']], 'empty' => 'No needs known yet.',
            // sized for a phone: a smaller drawing with larger type, labels wrapped, the room around the rim fitted to them
            'radius' => 80, 'auto_pad' => true, 'label_wrap' => 12, 'label_lines' => 4, 'font' => 13, 'sub_font' => 12,
            'ring_font' => 12, 'legend_font' => 12]);
    }

    /** A small trend line of 0..100 values (oldest first). */
    public static function sparkline(array $values, array $opts = []): string
    {
        $vals = array_values(array_map(fn($v) => max(0.0, min(100.0, floatval($v))), array_filter($values, 'is_numeric')));
        $w = floatval($opts['width'] ?? 120);
        $h = floatval($opts['height'] ?? 24);
        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::num($w, 0) . ' ' . self::num($h, 0) . '" width="' . self::num($w, 0)
            . '" height="' . self::num($h, 0) . '" role="img" aria-label="' . self::esc($opts['title'] ?? 'trend') . '">';
        $out .= '<line x1="0" y1="' . self::num($h / 2) . '" x2="' . self::num($w) . '" y2="' . self::num($h / 2) . '" stroke="' . self::INK['ring'] . '" stroke-dasharray="2 3"/>';
        if (count($vals) >= 2) {
            $pts = [];
            $last = count($vals) - 1;
            foreach ($vals as $i => $v) $pts[] = self::num($w * $i / $last) . ',' . self::num(($h - 2) - ($h - 4) * $v / 100.0);
            $out .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . self::INK['accent'] . '" stroke-width="1.5"/>';
        }
        return $out . '</svg>';
    }

    /** Split $text into lines of at most $max characters (words kept whole), at most $lines lines. */
    public static function wrap(string $text, int $max, int $lines = 3): array
    {
        $out = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') continue;
            if ($cur !== '' && mb_strlen($cur . ' ' . $word) > $max) {
                $out[] = $cur;
                $cur = $word;
                if (count($out) >= $lines) break;
            } else {
                $cur = $cur === '' ? $word : "{$cur} {$word}";
            }
        }
        if (count($out) < $lines && $cur !== '') $out[] = $cur;
        elseif ($cur !== '' && count($out) >= $lines) $out[$lines - 1] = rtrim(mb_substr($out[$lines - 1], 0, max(1, $max - 1))) . '…';
        return $out;
    }

    /**
     * The shareable card: one standalone SVG document (namespace, pixel size, every style inline).
     * $card: 'title', 'subtitle', 'badge', 'spider' (RelDynMirror::spider()), 'tiles' (list of
     * ['label', 'value', 'sub']), 'quote', 'footer'.
     */
    public static function card(array $card): string
    {
        $w = 640.0;
        $tiles = array_values(array_filter((array) ($card['tiles'] ?? []), 'is_array'));
        $tileRows = (int) ceil(count($tiles) / 2);
        $quote = self::wrap((string) ($card['quote'] ?? ''), 78, 3);
        $radarTop = 76.0;
        $radarH = 360.0;
        $tilesTop = $radarTop + $radarH + 8;
        $quoteTop = $tilesTop + 64 * $tileRows + 10;
        $h = $quoteTop + 18 * count($quote) + 40;
        $title = (string) ($card['title'] ?? 'Player profile');

        $out = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::num($w, 0) . ' ' . self::num($h, 0) . '" width="' . self::num($w, 0)
            . '" height="' . self::num($h, 0) . '" role="img" aria-label="' . self::esc($title) . '"><title>' . self::esc($title) . '</title>';
        $out .= '<rect x="0" y="0" width="' . self::num($w, 0) . '" height="' . self::num($h, 0) . '" rx="14" fill="' . self::INK['ground'] . '" stroke="' . self::INK['line'] . '"/>';
        $out .= '<rect x="0" y="0" width="' . self::num($w, 0) . '" height="3" fill="' . self::INK['accent'] . '"/>';
        $out .= '<text x="28" y="40" font-family="serif" font-size="24" font-weight="700" letter-spacing="1" fill="' . self::INK['accent'] . '">' . self::esc($title) . '</text>';
        $out .= '<text x="28" y="60" font-family="monospace" font-size="11" fill="' . self::INK['muted'] . '">' . self::esc($card['subtitle'] ?? '') . '</text>';
        $badge = trim((string) ($card['badge'] ?? ''));
        if ($badge !== '') {
            $bw = 16 + 7.2 * mb_strlen($badge);
            $out .= '<rect x="' . self::num($w - 28 - $bw) . '" y="24" width="' . self::num($bw) . '" height="22" rx="4" fill="none" stroke="' . self::INK['accent2'] . '" stroke-opacity="0.6"/>';
            $out .= '<text x="' . self::num($w - 28 - $bw / 2) . '" y="39" text-anchor="middle" font-family="monospace" font-size="11" fill="' . self::INK['accent2'] . '">' . self::esc($badge) . '</text>';
        }
        // the radar, nested (its own viewBox scales into the slot)
        $radar = self::playerRadar((array) ($card['spider'] ?? []), ['standalone' => true, 'radius' => 120]);
        $radar = preg_replace('/^<svg /', '<svg x="20" y="' . self::num($radarTop, 0) . '" ', $radar, 1);
        $radar = preg_replace('/ width="[0-9.]+" height="[0-9.]+"/', ' width="' . self::num($w - 40, 0) . '" height="' . self::num($radarH, 0) . '"', $radar, 1);
        $out .= $radar;
        // tiles, two per row
        foreach ($tiles as $i => $t) {
            $tw = ($w - 56 - 12) / 2;
            $x = 28 + ($i % 2) * ($tw + 12);
            $y = $tilesTop + 64 * intdiv($i, 2);
            $out .= '<rect x="' . self::num($x) . '" y="' . self::num($y) . '" width="' . self::num($tw) . '" height="54" rx="6" fill="' . self::INK['panel'] . '" stroke="' . self::INK['line'] . '"/>';
            $out .= '<text x="' . self::num($x + 12) . '" y="' . self::num($y + 16) . '" font-family="monospace" font-size="9" letter-spacing="1.2" fill="' . self::INK['muted'] . '">'
                . self::esc(mb_strtoupper((string) ($t['label'] ?? ''))) . '</text>';
            $value = (string) ($t['value'] ?? '');
            $small = mb_strlen($value) > 28;
            $out .= '<text x="' . self::num($x + 12) . '" y="' . self::num($y + 34) . '" font-family="sans-serif" font-size="' . ($small ? '12' : '15')
                . '" font-weight="700" fill="' . self::INK['text'] . '">' . self::esc(self::wrap($value, $small ? 42 : 30, 1)[0] ?? '') . '</text>';
            $out .= '<text x="' . self::num($x + 12) . '" y="' . self::num($y + 48) . '" font-family="monospace" font-size="9" fill="' . self::INK['accent'] . '">'
                . self::esc(self::wrap((string) ($t['sub'] ?? ''), 48, 1)[0] ?? '') . '</text>';
        }
        foreach ($quote as $i => $line) {
            $out .= '<text x="' . self::num($w / 2) . '" y="' . self::num($quoteTop + 12 + 18 * $i) . '" text-anchor="middle" font-family="serif" font-style="italic" font-size="13" fill="'
                . self::INK['muted'] . '">' . self::esc(($i === 0 ? '“' : '') . $line . ($i === count($quote) - 1 ? '”' : '')) . '</text>';
        }
        $out .= '<text x="' . self::num($w - 24) . '" y="' . self::num($h - 14) . '" text-anchor="end" font-family="monospace" font-size="9" letter-spacing="1" fill="#6a6a6a">'
            . self::esc($card['footer'] ?? 'CHIM // RELATIONSHIP DYNAMICS') . '</text>';
        return $out . '</svg>';
    }
}
