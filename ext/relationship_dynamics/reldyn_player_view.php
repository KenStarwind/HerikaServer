<?php
/**
 * Relationship Dynamics — the player profile page's view model (roadmap player-profile-page;
 * D:\docs\reldyn-player-profile-design.md "The Profile Page"; memory project_player_profile_spider).
 *
 * Pure: from the stored mirror state (RelDynMirror::state()) and its config, what player.php shows:
 * the design's dimension bars with bands, charisma, attachment with its transition (the read before
 * the latest one, when it named another pattern), love language, reputation, validation locus, a
 * prose paragraph of how NPCs experience the player, the growth trajectory and the shareable card.
 * Read-only: "You cannot edit it. Only change it."
 *
 * Numbers are fine here (decisions §3: feelings-not-numbers is for LLM text). The prose is
 * composed from the design's band keywords (no LLM).
 */

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_ui_charts.php';

final class RelDynPlayerView
{
    /** Short names of the design's five bands (0-20 .. 81-100). */
    const BAND_NAMES = ['very low', 'low', 'middling', 'high', 'very high'];

    const CHARISMA = [
        'rock'     => ['The Rock', 'Steady under pressure; people lean on that calm.'],
        'catalyst' => ['The Catalyst', 'Brings a spark and a push and pull that keeps people on their toes.'],
        'charmer'  => ['The Charmer', 'Warm and generous; wins people over with attention.'],
    ];

    const ATTACHMENT = [
        'secure'       => ['Secure', 'Comes back steadily and repairs well after a rough moment.'],
        'anxious'      => ['Anxious', 'Comes back quickly after a bad moment, pressing for reassurance.'],
        'avoidant'     => ['Avoidant', 'Keeps a distance: long gaps and light talk with the people who care.'],
        'disorganized' => ['Disorganized', 'Runs hot and cold: close one day, gone the next.'],
    ];

    const LOVE_LANGUAGE = [
        RelationshipDynamics::LL_WORDS   => ['Words of Affirmation', 'Shows care in words: praise, reassurance, an apology when it counts.'],
        RelationshipDynamics::LL_TIME    => ['Quality Time', 'Shows care by spending time: long talks, staying near.'],
        RelationshipDynamics::LL_TOUCH   => ['Physical Touch', 'Shows care through closeness and touch.'],
        RelationshipDynamics::LL_SERVICE => ['Acts of Service', 'Shows care by doing: help, protection, a rescue.'],
        RelationshipDynamics::LL_GIFTS   => ['Gifts', 'Shows care with gifts.'],
    ];

    const LOCUS = [
        'internal'              => ['Internal', 'Acts on their own judgment; does not come back to check how it landed.'],
        'external'              => ['External', 'Comes back after things go wrong to see where they stand.'],
        'concentrated_external' => ['Concentrated external', 'Seeks approval mostly from a very few people.'],
    ];

    /** Observations with evidence a dimension needs before the prose speaks of it. */
    const PROSE_MIN_EVIDENCE = 5;

    /**
     * The view model.
     * @param array  $state RelDynMirror::state()
     * @param array  $cfg   RelDynMirror::config()
     * @param float  $now   raw gamets (0 = unknown: no trend)
     * @param string $playerName the player character's name (core's PLAYER_NAME)
     */
    public static function build(array $state, array $cfg, float $now, string $playerName): array
    {
        $p = RelDynMirror::compute($state, $cfg, $now > 0 ? $now : null);
        $spider = RelDynMirror::spider($p);
        $day = RelationshipDynamics::GAMETS_PER_DAY;

        // trajectory reference, as compute() picks it
        $hist = array_values(array_filter((array) ($state['history'] ?? []), fn($h) => is_array($h) && is_numeric($h['g'] ?? null)));
        usort($hist, fn($a, $b) => floatval($a['g']) <=> floatval($b['g']));
        $ref = null;
        if ($now > 0) {
            $at = $now - floatval($cfg['history']['trend_game_days']) * $day;
            foreach ($hist as $h) if (floatval($h['g']) <= $at) $ref = $h;
            if ($ref === null && $hist !== []) $ref = $hist[0];
        }
        $refAge = $ref !== null ? max(0.0, ($now - floatval($ref['g'])) / $day) : null;

        $dims = [];
        foreach (RelDynMirror::DIMENSIONS as $d) {
            $x = $p['dimensions'][$d];
            $series = [];
            foreach ($hist as $h) if (is_numeric($h['scores'][$d] ?? null)) $series[] = floatval($h['scores'][$d]);
            $series[] = floatval($x['observed']);
            $dims[$d] = [
                'axis' => $d, 'label' => (string) $x['label'], 'score' => floatval($x['score']), 'observed' => floatval($x['observed']),
                'band' => intval($x['band']), 'band_name' => self::BAND_NAMES[max(0, min(4, intval($x['band']) - 1))],
                'keywords' => (string) $x['keywords'], 'evidence' => intval($x['n']), 'confidence' => floatval($x['confidence']),
                'trend' => $x['trend'], 'was' => $x['was'], 'history' => $series,
            ];
        }

        $charisma = self::charisma($p, $cfg);
        $attachment = self::attachment($state, $p, $cfg, $now);
        $love = self::love($p);
        $locus = self::locus($p, $cfg);
        $reputation = self::reputation($p, $cfg);
        $prose = self::prose($p, $dims, $charisma, $attachment, $love);
        $trajectory = self::trajectory($dims, $refAge, $hist !== []);

        $view = [
            'known' => (bool) $p['known'], 'mode' => (string) $p['mode'], 'observations' => intval($p['observations']),
            'interactions' => intval($p['total']), 'npcs' => intval($p['npcs']), 'player' => $playerName,
            'dimensions' => $dims, 'charisma' => $charisma, 'attachment' => $attachment, 'love_language' => $love,
            'validation_locus' => $locus, 'reputation' => $reputation, 'prose' => $prose, 'trajectory' => $trajectory,
            'prompt_enabled' => !empty($cfg['enabled']) && !empty($cfg['prompt']['enabled']),
            'mirror_enabled' => !empty($cfg['enabled']),
            'spider' => $spider,
        ];
        $view['card'] = self::card($view, $playerName);
        return $view;
    }

    private static function charisma(array $p, array $cfg): array
    {
        $c = (array) ($p['charisma'] ?? []);
        $primary = $c['primary'] ?? null;
        $secondary = $c['secondary'] ?? null;
        $graded = array_sum(array_map('intval', (array) ($c['counts'] ?? [])));
        if ($primary === null || !isset(self::CHARISMA[$primary])) {
            return ['primary' => null, 'secondary' => null, 'counts' => (array) ($c['counts'] ?? []),
                'text' => 'Not read yet', 'detail' => "{$graded} of " . intval($cfg['charisma']['min_graded'] ?? 5) . ' graded exchanges needed', 'sentence' => null];
        }
        $text = self::CHARISMA[$primary][0] . ($secondary !== null && isset(self::CHARISMA[$secondary]) ? ' / ' . self::CHARISMA[$secondary][0] : '');
        return ['primary' => $primary, 'secondary' => $secondary, 'counts' => (array) $c['counts'], 'text' => $text,
            'detail' => 'primary' . ($secondary !== null ? ' / secondary' : '') . ", over {$graded} graded exchanges",
            'sentence' => self::CHARISMA[$primary][1]];
    }

    /** The pattern's display name ('anxious-leaning' -> 'Anxious-leaning'). */
    private static function patternName(?string $label): ?string
    {
        if ($label === null || $label === '') return null;
        $base = str_replace('-leaning', '', $label);
        $name = self::ATTACHMENT[$base][0] ?? ucfirst($base);
        return str_ends_with($label, '-leaning') ? "{$name}-leaning" : $name;
    }

    /**
     * Attachment with its transition: the read before the latest one (update_every observations
     * earlier), computed the same way from the observations it had; a different pattern there is
     * the design's "Anxious -> Secure (in transition, 68%)".
     */
    private static function attachment(array $state, array $p, array $cfg, float $now): array
    {
        $a = $p['attachment'] ?? null;
        $min = intval($cfg['attachment']['min_observations'] ?? 25);
        if (!is_array($a) || ($a['primary'] ?? null) === null) {
            return ['primary' => null, 'label' => null, 'text' => 'Not read yet',
                'detail' => intval($p['total']) . " of {$min} interactions needed", 'transition' => null, 'sentence' => null];
        }
        $share = floatval($a['share'] ?? 0);
        $transition = null;
        if (empty($a['seeded']) && isset($a['at_count'])) {
            $every = max(1, intval($cfg['attachment']['update_every'] ?? 25));
            $prevAt = intval($a['at_count']) - $every;
            if ($prevAt >= $min) {
                $obs = array_values(array_filter((array) ($state['obs'] ?? []), fn($o) => is_array($o) && intval($o['q'] ?? PHP_INT_MAX) <= $prevAt));
                $prev = RelDynMirror::compute(['obs' => $obs, 'total' => $prevAt, 'history' => []] + $state, $cfg, $now > 0 ? $now : null)['attachment'] ?? null;
                if (is_array($prev) && ($prev['primary'] ?? null) !== null && $prev['primary'] !== $a['primary']) {
                    $transition = ['from' => (string) $prev['primary'], 'from_label' => self::patternName((string) $prev['label']),
                        'to' => (string) $a['primary'], 'to_label' => self::patternName((string) $a['label'])];
                }
            }
        }
        $pct = (int) round(100 * $share);
        $text = $transition !== null
            ? "{$transition['from_label']} → {$transition['to_label']} (in transition, {$pct}%)"
            : self::patternName((string) $a['label']) . (!empty($a['seeded']) ? ' (character)' : " ({$pct}% match)");
        $detail = ($a['secondary'] ?? null) !== null
            ? 'secondary: ' . self::patternName((string) $a['secondary']) . ' (' . (int) round(100 * floatval($a['secondary_share'] ?? 0)) . '%)'
            : (!empty($a['seeded']) ? 'held by Character mode' : 'no second pattern');
        return ['primary' => (string) $a['primary'], 'label' => (string) $a['label'], 'share' => $share, 'confident' => !empty($a['confident']),
            'text' => $text, 'detail' => $detail, 'transition' => $transition, 'shares' => (array) ($a['shares'] ?? []),
            'sentence' => self::ATTACHMENT[$a['primary']][1] ?? null];
    }

    private static function love(array $p): array
    {
        $l = (array) ($p['love_language'] ?? []);
        $name = fn(?string $k) => $k === null ? null : (self::LOVE_LANGUAGE[$k][0] ?? ucwords(str_replace('_', ' ', $k)));
        $primary = isset($l['primary']) ? (string) $l['primary'] : null;
        $secondary = isset($l['secondary']) ? (string) $l['secondary'] : null;
        if ($primary === null) {
            return ['primary' => null, 'secondary' => null, 'counts' => (array) ($l['counts'] ?? []), 'text' => 'Not clear yet', 'sentence' => null];
        }
        return ['primary' => $primary, 'secondary' => $secondary, 'counts' => (array) ($l['counts'] ?? []),
            'text' => $name($primary) . ($secondary !== null ? ' / ' . $name($secondary) : ''),
            'sentence' => self::LOVE_LANGUAGE[$primary][1] ?? null];
    }

    private static function locus(array $p, array $cfg): array
    {
        $v = $p['validation_locus'] ?? null;
        if (!is_array($v)) {
            return ['locus' => null, 'text' => 'Not read yet', 'detail' => intval($cfg['validation']['min_observations'] ?? 20) . ' interactions needed', 'validators' => []];
        }
        $k = (string) $v['locus'];
        return ['locus' => $k, 'text' => self::LOCUS[$k][0] ?? $k, 'detail' => self::LOCUS[$k][1] ?? '',
            'seeking_share' => floatval($v['seeking_share'] ?? 0), 'validators' => array_values(array_map('strval', (array) ($v['validators'] ?? [])))];
    }

    private static function reputation(array $p, array $cfg): array
    {
        $o = floatval($p['reputation_trust'] ?? 0);
        $on = !empty($cfg['reputation']['enabled']);
        if (!$on) return ['offset' => 0.0, 'text' => 'Off', 'detail' => 'reputation does not reach new NPCs'];
        if ($o > 0) return ['offset' => $o, 'text' => 'Well regarded', 'detail' => 'trust +' . RelDynUiCharts::num($o) . ' with new NPCs'];
        if ($o < 0) return ['offset' => $o, 'text' => 'Poorly regarded', 'detail' => 'trust ' . RelDynUiCharts::num($o) . ' with new NPCs'];
        return ['offset' => 0.0, 'text' => 'Unremarkable', 'detail' => 'new NPCs take you as they find you'];
    }

    /**
     * How NPCs experience the player, in words: the most telling bands with evidence (farthest
     * from neutral), the charisma style, the attachment pattern and the love language.
     */
    private static function prose(array $p, array $dims, array $charisma, array $attachment, array $love): string
    {
        if (empty($p['known'])) {
            return 'Nobody has formed a picture of you yet. Spend time with people; this mirror fills itself from what they experience.';
        }
        $cands = [];
        foreach ($dims as $d => $x) {
            if ($x['evidence'] < self::PROSE_MIN_EVIDENCE && $p['mode'] !== 'character') continue;
            if (trim($x['keywords']) === '') continue;
            $cands[$d] = abs($x['score'] - 50.0);
        }
        arsort($cands);
        $telling = array_filter($cands, fn($dist) => $dist > 10.5);
        $pick = array_slice(array_keys($telling !== [] ? $telling : $cands), 0, 3);
        $sentences = [];
        foreach ($pick as $d) $sentences[] = self::sentence($dims[$d]['keywords']);
        foreach ([$charisma['sentence'] ?? null, $attachment['sentence'] ?? null, $love['sentence'] ?? null] as $s) {
            if (is_string($s) && $s !== '') $sentences[] = $s;
        }
        if ($sentences === []) {
            return 'People have met you, but nothing about you stands out to them yet.';
        }
        return implode(' ', $sentences);
    }

    private static function sentence(string $keywords): string
    {
        $s = trim($keywords);
        if ($s === '') return '';
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return preg_match('/[.!?]$/u', $s) ? $s : "{$s}.";
    }

    private static function trajectory(array $dims, ?float $refAgeDays, bool $hasHistory): array
    {
        $lines = [];
        foreach ($dims as $d => $x) {
            if ($x['trend'] === null) continue;
            $words = $x['trend'] === 'up' ? 'trending up' : ($x['trend'] === 'down' ? 'trending down' : 'stable');
            $was = $x['was'] !== null ? 'was ' . RelDynUiCharts::num(floatval($x['was']), 0)
                . ($refAgeDays !== null ? ', ' . RelDynUiCharts::num($refAgeDays, 0) . ' game days ago' : '') : null;
            $lines[] = ['axis' => $d, 'label' => $x['label'], 'trend' => $x['trend'], 'arrow' => RelDynUiCharts::arrow($x['trend']),
                'text' => $words . ($was !== null && $x['trend'] !== 'stable' ? " ({$was})" : '')];
        }
        return ['lines' => $lines, 'note' => $lines === []
            ? ($hasHistory ? 'Not enough time has passed to see a trend.' : 'No trajectory yet: the first snapshot is taken with the first observations of a game day.')
            : null];
    }

    // =====================================================================
    // DATA ACCESS for player.php (reads, and the page's one write)
    // =====================================================================

    /**
     * Game time now (raw gamets): the request's, else core's newest eventlog time, else the newest
     * mirror observation's; 0 when none is known (then no trend is read).
     */
    public static function gameNow(array $state = []): float
    {
        $now = RelationshipDynamics::currentGamets();
        if ($now > 0) return $now;
        $db = $GLOBALS['db'] ?? null;
        if ($db) {
            try {
                $row = $db->fetchOne('SELECT MAX(gamets) AS g FROM eventlog WHERE gamets > 0');
                if (is_array($row) && is_numeric($row['g'] ?? null) && floatval($row['g']) > 0) return floatval($row['g']);
            } catch (\Throwable $e) {
                RelationshipDynamics::logError('player page game time', $e);
            }
        }
        $g = 0.0;
        foreach ((array) ($state['obs'] ?? []) as $o) if (is_array($o) && is_numeric($o['g'] ?? null)) $g = max($g, floatval($o['g']));
        return $g;
    }

    /** NPCs RelDyn keeps state for (core_npc_master.plugin_extended_data.reldyn.dynamics), by name. */
    public static function bondNames(int $limit = 500): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [];
        try {
            $rows = $db->fetchAll("SELECT npc_name FROM core_npc_master
                WHERE jsonb_typeof(plugin_extended_data -> 'reldyn' -> 'dynamics') = 'object'
                ORDER BY lower(npc_name), id LIMIT " . max(1, min(2000, $limit)));
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('player page bond list', $e);
            return [];
        }
        $names = [];
        foreach ((array) $rows as $r) {
            $n = trim((string) ($r['npc_name'] ?? ''));
            if ($n !== '' && !in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) $names[] = $n;
        }
        return $names;
    }

    /**
     * Rangroo's opt-in: whether NPCs sense the profile (player_mirror.prompt.enabled, the felt line,
     * words only). Written into the stored config row through saveConfig() (known keys, schema
     * stamp), keeping everything else the row holds.
     */
    public static function savePromptEnabled(bool $on): bool
    {
        $stored = RelationshipDynamics::loadStoredConfig();
        $pm = is_array($stored['player_mirror'] ?? null) ? $stored['player_mirror'] : [];
        $pm['prompt'] = is_array($pm['prompt'] ?? null) ? $pm['prompt'] : [];
        $pm['prompt']['enabled'] = $on;
        $stored['player_mirror'] = $pm;
        return RelationshipDynamics::saveConfig($stored);
    }

    /** The shareable card's model (RelDynUiCharts::card). NPC names never go on the card. */
    public static function card(array $view, string $playerName, bool $showName = true): array
    {
        $name = trim($playerName);
        $sentences = preg_split('/(?<=[.!?])\s+/u', (string) $view['prose']) ?: [];
        $quote = implode(' ', array_slice($sentences, 0, 2));
        return [
            'title' => $showName && $name !== '' ? $name : 'Anonymous adventurer',
            'subtitle' => 'Behavioral mirror // ' . $view['interactions'] . ' interactions with ' . $view['npcs'] . ' ' . ($view['npcs'] === 1 ? 'person' : 'people')
                . ($view['mode'] === 'character' ? ' // character mode' : ''),
            'badge' => $view['charisma']['primary'] !== null ? mb_strtoupper(self::CHARISMA[$view['charisma']['primary']][0]) : ($view['mode'] === 'character' ? 'CHARACTER' : ''),
            'spider' => $view['spider'],
            'tiles' => [
                ['label' => 'Charisma', 'value' => $view['charisma']['text'], 'sub' => $view['charisma']['primary'] !== null ? 'style' : ''],
                ['label' => 'Attachment', 'value' => $view['attachment']['text'], 'sub' => $view['attachment']['transition'] !== null ? 'in transition' : (string) ($view['attachment']['label'] ?? '')],
                ['label' => 'Love language', 'value' => $view['love_language']['text'], 'sub' => 'what you do, not what you say'],
                ['label' => 'Reputation', 'value' => $view['reputation']['text'], 'sub' => $view['reputation']['detail']],
            ],
            'quote' => $quote,
            'footer' => 'CHIM // RELATIONSHIP DYNAMICS',
        ];
    }
}
