<?php
/**
 * Relationship Dynamics — Intrinsic goals, tier 1 (MDD 13.2 / 14.2; dimension draft "Intrinsic
 * Goal Generation"; roadmap intrinsic-goals)
 *
 * Persistent goals an NPC forms from her own character and state, stored in her dynamics
 * (KEY, persistent across sessions like the rest of RelDyn's state):
 *   bond_seeking        affinity trajectory rising, or a relationship stage reached (MDD 14.2:
 *                       reaching Established creates "deepen bond")
 *   independence        affinity trajectory falling, for an NPC whose attachment avoidance says
 *                       she needs room ("I need space", MDD 13.2)
 *   mastery             her strongest interest (signed facet preference, decisions §6)
 *   purpose / revenge / safety   backstory keywords in her own CHIM bio template (never for an
 *                       NPC the trait reader screens: Ashe is never read)
 *   self_worth_recovery dimensional pain (draft): deficit = (50 - respect) + (50 - maturity) +
 *                       resentment_self / 2 above self_worth_deficit_min with maturity above
 *                       self_worth_maturity_min -> "I need to change"; held (maturity kept at or
 *                       above where it stood, sustain days) -> her maturity baseline rises and the
 *                       goal becomes keeping it; slipping below -> the goal lapses and the shame
 *                       adds resentment_self.
 * Progress (0..1): experiences whose facets carry the goal's facets (the place she is in with the
 * player, a topic, a gift; once per thing per game day), positive eval items with the goal's
 * tags, and quest stages whose name or objective carries the goal's keywords (the quest event
 * hook). A goal without progress loses priority per game day; below drop_below_priority it is
 * dropped. Reaching progress 1 achieves it. Finished goals go to history; the same goal does
 * not form again for regenerate_after_game_days.
 * Backstory goals are character-defining (MDD 14.2, "persistent across sessions"): time without
 * progress lowers their priority only to backstory_priority_floor, newer goals never crowd them
 * out, and her backstory as last read (meta 'backstory') forms them again whenever one is not
 * active, unless it was achieved (meta 'backstory_done'; a changed bio template is a new story).
 *
 * The LLM gets the top goal as a feeling (felt line 'intrinsic_goal', no numbers); Jev gets the
 * numbers. Clocks: the game calendar (raw gamets / GAMETS_PER_DAY).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynGoals
{
    const KEY = '_intrinsic_goals';
    const META_KEY = '_intrinsic_goals_meta';
    const HISTORY_KEY = '_intrinsic_goals_history';
    const TYPES = ['bond_seeking', 'purpose', 'mastery', 'safety', 'independence', 'revenge', 'self_worth_recovery'];

    /**
     * Defaults for config 'intrinsic_goals' (a stored key replaces that key whole).
     * Units: priority and progress 0..1; slopes in core affinity points per game day;
     * dimension points 0..100; attachment axes 0..1; game days on the game calendar.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'max_active' => 4,
            'history_keep' => 6,
            'regenerate_after_game_days' => 7,
            // backstory: fields of her CHIM bio template (combined_bio_templates) scanned once per
            // text; a sentence with a pattern forms the goal, its proper names are the goal's
            // keywords (quest matching), its facets (facet classifier tag keywords) the goal's facets
            'backstory_fields' => ['npc_static_bio', 'goals'],
            'backstory' => [
                'revenge' => ['priority' => 0.8, 'patterns' => ['avenge', 'vengeance', 'revenge', 'hunt down', 'blood feud',
                    'make them pay', 'swore to destroy', 'wipe out']],
                'purpose' => ['priority' => 0.6, 'patterns' => ['search for', 'searching for', 'in search of', 'looking for',
                    'seeks to', 'wants to find', 'unfinished', 'restore', 'prove herself', 'prove himself']],
                'safety' => ['priority' => 0.6, 'patterns' => ['on the run', 'hunted by', 'fled from', 'fleeing', 'in hiding',
                    'hides from', 'afraid of']],
            ],
            'keywords_max' => 4,
            // affinity trajectory: slope of the last trajectory_days daily affinity samples
            // (baseline drift's _baseline_drift_samples.affinity, core units)
            'trajectory_days' => 3,
            'bond_seeking_slope' => 2.0,
            'independence_slope' => -2.0,
            'independence_avoidance_min' => 0.4,
            'trajectory_priority' => 0.5,
            // stage reached => bond_seeking priority
            'stage_goals' => ['established' => 0.7, 'deep' => 0.8],
            // interest weights: strongest preference among mastery_facets, at least mastery_pref_min
            'mastery_facets' => ['combat', 'crafting', 'alchemy', 'enchanting', 'scholarly', 'nature', 'social'],
            'mastery_pref_min' => 0.6,
            'mastery_priority' => 0.5,
            // self-worth (dimension draft)
            'self_worth_deficit_min' => 60.0,
            'self_worth_maturity_min' => 20.0,
            'self_worth_sustain_days' => 3,
            'self_worth_margin' => 2.0,
            'self_worth_success_baseline' => 2.0,
            'self_worth_failure_shame' => 5.0,
            // progress
            'progress_experience' => 0.05,
            'progress_eval' => 0.1,
            'eval_tags' => [
                'bond_seeking' => ['quality_time', 'confiding', 'confessing', 'touch', 'reassurance', 'praise'],
                'safety' => ['rescue', 'reassurance', 'help'],
                'purpose' => ['help'],
                'revenge' => ['help'],
            ],
            'priority_decay_per_game_day' => 0.02,
            'drop_below_priority' => 0.1,
            // a backstory goal (character-defining) decays no lower than this priority (felt_min_priority:
            // still felt at its quietest), never fades
            'backstory_priority_floor' => 0.4,
            // the felt line: from this priority; {NAME} {PLAYER} {PURSUIT} {SUBJECT}
            'felt_min_priority' => 0.4,
            'pursuit' => ['combat' => 'fighting', 'crafting' => 'the craft', 'alchemy' => 'alchemy', 'enchanting' => 'enchanting',
                'scholarly' => 'study and learning', 'nature' => 'the wilds and the hunt', 'social' => 'people and song'],
            'felt_text' => [
                'bond_seeking' => '{NAME} wants to be closer to {PLAYER}: they look for reasons to share time and let a little more of themselves show',
                'purpose' => '{NAME} carries a purpose of their own{SUBJECT}; it pulls at their attention whenever it comes up',
                'mastery' => '{NAME} is set on getting better at {PURSUIT}; anything to do with it catches their interest',
                'safety' => '{NAME} needs to feel safe; they keep an eye on the ways out and on who can be trusted',
                'independence' => '{NAME} needs room to breathe; being asked goes over far better than being told',
                'revenge' => '{NAME} has an old score to settle{SUBJECT}; it hardens them whenever it comes near',
                'self_worth_recovery' => '{NAME} knows they need to change and is trying; small setbacks sting more than they let on',
                'self_worth_maintain' => '{NAME} has found firmer ground in themselves lately and means to keep it',
            ],
        ];
    }

    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('intrinsic_goals');
        return array_replace(self::configDefaults(), is_array($stored) ? $stored : []);
    }

    private static function day(float $gamets): int
    {
        return (int) floor($gamets / RelationshipDynamics::GAMETS_PER_DAY);
    }

    /** Active goals, highest priority first. */
    public static function active(array $dynamics): array
    {
        $out = [];
        foreach ((array) ($dynamics[self::KEY] ?? []) as $g) {
            if (is_array($g) && !empty($g['active']) && in_array($g['type'] ?? null, self::TYPES, true)) $out[] = $g;
        }
        usort($out, fn($a, $b) => floatval($b['priority'] ?? 0) <=> floatval($a['priority'] ?? 0));
        return $out;
    }

    private static function find(array $dynamics, string $id): ?int
    {
        foreach ((array) ($dynamics[self::KEY] ?? []) as $i => $g) {
            if (is_array($g) && ($g['id'] ?? null) === $id && !empty($g['active'])) return $i;
        }
        return null;
    }

    /** True when a goal with $id finished (achieved / lapsed / dropped) within the regenerate window. */
    private static function recentlyEnded(array $dynamics, string $id, int $today, array $cfg): bool
    {
        foreach ((array) ($dynamics[self::HISTORY_KEY] ?? []) as $h) {
            if (!is_array($h) || ($h['id'] ?? null) !== $id) continue;
            if ($today - intval($h['ended_day'] ?? -PHP_INT_MAX) < intval($cfg['regenerate_after_game_days'])) return true;
        }
        return false;
    }

    /**
     * Form (or strengthen) a goal. An active goal of the same id keeps its progress and takes the
     * higher priority. Returns the goal id when it formed or rose, null otherwise.
     */
    public static function form(array &$dynamics, string $type, float $priority, string $source, float $now, array $extra = []): ?string
    {
        if (!in_array($type, self::TYPES, true)) return null;
        $cfg = self::config();
        $id = $type;
        $priority = max(0.0, min(1.0, $priority));
        $today = self::day($now);
        $i = self::find($dynamics, $id);
        if ($i !== null) {
            if ($priority <= floatval($dynamics[self::KEY][$i]['priority'] ?? 0) + 1e-9) return null;
            $dynamics[self::KEY][$i]['priority'] = round($priority, 4);
            $dynamics[self::KEY][$i]['source'] = $source;
            return $id;
        }
        if (self::recentlyEnded($dynamics, $id, $today, $cfg)) return null;
        $goals = array_values(array_filter((array) ($dynamics[self::KEY] ?? []), fn($g) => is_array($g) && !empty($g['active'])));
        $goals[] = array_merge([
            'id' => $id, 'type' => $type, 'source' => $source, 'priority' => round($priority, 4), 'progress' => 0.0,
            'active' => true, 'created_gamets' => $now, 'created_day' => $today, 'last_progress_day' => $today,
            'last_tick_day' => $today, 'facets' => [], 'keywords' => [],
        ], $extra);
        usort($goals, fn($a, $b) => floatval($b['priority'] ?? 0) <=> floatval($a['priority'] ?? 0));
        // Over max_active the lowest crowd out, backstory goals never (character-defining)
        $max = max(1, intval($cfg['max_active']));
        $story = array_values(array_filter($goals, fn($g) => self::isBackstory($g)));
        $others = array_values(array_filter($goals, fn($g) => !self::isBackstory($g)));
        $room = max(0, $max - count($story));
        foreach (array_slice($others, $room) as $dropped) self::retire($dynamics, $dropped, 'crowded_out', $today);
        $kept = array_merge($story, array_slice($others, 0, $room));
        usort($kept, fn($a, $b) => floatval($b['priority'] ?? 0) <=> floatval($a['priority'] ?? 0));
        $dynamics[self::KEY] = $kept;
        return self::find($dynamics, $id) !== null ? $id : null;
    }

    /** A goal her backstory formed (character-defining). */
    private static function isBackstory(array $goal): bool
    {
        return ($goal['source'] ?? null) === 'backstory';
    }

    private static function retire(array &$dynamics, array $goal, string $outcome, int $today): void
    {
        if ($outcome === 'achieved' && self::isBackstory($goal)) {
            // done for good: her backstory does not form it again (until a new bio)
            $meta = is_array($dynamics[self::META_KEY] ?? null) ? $dynamics[self::META_KEY] : [];
            $meta['backstory_done'][(string) ($goal['type'] ?? '')] = $today;
            $dynamics[self::META_KEY] = $meta;
        }
        $goal['active'] = false;
        $goal['outcome'] = $outcome;
        $goal['ended_day'] = $today;
        $history = (array) ($dynamics[self::HISTORY_KEY] ?? []);
        array_unshift($history, $goal);
        $dynamics[self::HISTORY_KEY] = array_slice($history, 0, max(1, intval(self::config()['history_keep'])));
    }

    private static function end(array &$dynamics, int $i, string $outcome, int $today): void
    {
        $goal = $dynamics[self::KEY][$i];
        unset($dynamics[self::KEY][$i]);
        $dynamics[self::KEY] = array_values($dynamics[self::KEY]);
        self::retire($dynamics, $goal, $outcome, $today);
    }

    /** Add progress to an active goal; achieved at 1. Returns true when it moved. */
    private static function advance(array &$dynamics, string $id, float $amount, float $now): bool
    {
        if ($amount <= 0.0) return false;
        $i = self::find($dynamics, $id);
        if ($i === null) return false;
        $g = &$dynamics[self::KEY][$i];
        if (($g['type'] ?? '') === 'self_worth_recovery') return false;   // held, not progressed (draft)
        $g['progress'] = round(min(1.0, floatval($g['progress'] ?? 0) + $amount), 4);
        $g['last_progress_day'] = self::day($now);
        $done = $g['progress'] >= 1.0;
        unset($g);
        if ($done) self::end($dynamics, $i, 'achieved', self::day($now));
        return true;
    }

    // =====================================================================
    // GENERATION
    // =====================================================================

    /**
     * Prerequest (after contact is marked and resentment / autonomy ran): form goals from the
     * NPC's state, then the daily tick. Returns the ids formed or raised this contact.
     */
    public static function onContact(string $npcName, array &$dynamics, float $now): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $now <= 0) return [];
        $formed = [];
        $meta = is_array($dynamics[self::META_KEY] ?? null) ? $dynamics[self::META_KEY] : [];

        // Her backstory (as last read): each goal it holds that is not active forms again, unless achieved
        foreach (self::backstoryGoals($npcName, $meta, $cfg) as $type => $g) {
            if (isset($meta['backstory_done'][$type]) || self::find($dynamics, $type) !== null) continue;
            $dynamics[self::META_KEY] = $meta;
            if (($id = self::form($dynamics, $type, $g['priority'], 'backstory', $now, ['keywords' => $g['keywords'], 'facets' => $g['facets']])) !== null) $formed[] = $id;
            $meta = $dynamics[self::META_KEY];
        }

        $slope = self::affinitySlope($dynamics, intval($cfg['trajectory_days']));
        if ($slope !== null && $slope >= floatval($cfg['bond_seeking_slope'])) {
            if (($id = self::form($dynamics, 'bond_seeking', floatval($cfg['trajectory_priority']), 'trajectory', $now)) !== null) $formed[] = $id;
        } elseif ($slope !== null && $slope <= floatval($cfg['independence_slope'])
            && RelationshipDynamics::getAttachmentAxes($dynamics)['avoidance'] >= floatval($cfg['independence_avoidance_min'])) {
            if (($id = self::form($dynamics, 'independence', floatval($cfg['trajectory_priority']), 'trajectory', $now)) !== null) $formed[] = $id;
        }

        $stage = (string) ($dynamics['stage'] ?? '');
        $lastStage = $meta['last_stage'] ?? null;
        if ($lastStage !== null && $stage !== $lastStage && isset($cfg['stage_goals'][$stage])) {
            if (($id = self::form($dynamics, 'bond_seeking', floatval($cfg['stage_goals'][$stage]), "stage:{$stage}", $now)) !== null) $formed[] = $id;
        }
        $meta['last_stage'] = $stage;

        $mastery = self::masteryFacet($npcName, $dynamics, $cfg);
        if ($mastery !== null) {
            if (($id = self::form($dynamics, 'mastery', floatval($cfg['mastery_priority']), 'interest', $now, ['facets' => [$mastery => 1.0]])) !== null) $formed[] = $id;
        }

        $sw = self::selfWorth($dynamics, $cfg);
        if ($sw !== null && self::find($dynamics, 'self_worth_recovery') === null) {
            $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
            if (($id = self::form($dynamics, 'self_worth_recovery', min(1.0, $sw / 100.0), 'self_worth', $now,
                ['deficit' => round($sw, 2), 'maturity_start' => round($maturity, 2), 'held_days' => 0, 'phase' => 'change'])) !== null) {
                $formed[] = $id;
            }
        }

        $dynamics[self::META_KEY] = $meta;
        self::tick($npcName, $dynamics, $now, $cfg);
        if ($formed !== []) {
            RelationshipDynamics::log("[RelDyn-GOALS] {$npcName}: formed " . implode(', ', array_unique($formed)));
        }
        return array_values(array_unique($formed));
    }

    /** Self-worth deficit (dimension points) when it forms the goal, else null (draft formula). */
    public static function selfWorth(array $dynamics, ?array $cfg = null): ?float
    {
        $cfg = $cfg ?? self::config();
        $dims = (array) ($dynamics['dimensions'] ?? []);
        $maturity = floatval($dims['maturity']['x'] ?? 50);
        if ($maturity <= floatval($cfg['self_worth_maturity_min'])) return null;
        $deficit = (50 - floatval($dims['respect']['x'] ?? 50)) + (50 - $maturity) + floatval($dims['resentment_self']['x'] ?? 0) / 2;
        return $deficit > floatval($cfg['self_worth_deficit_min']) ? $deficit : null;
    }

    /** Slope of the last $days daily affinity samples (core points per game day), null with fewer than two. */
    public static function affinitySlope(array $dynamics, int $days): ?float
    {
        $list = array_values(array_filter((array) ($dynamics['_baseline_drift_samples']['affinity'] ?? []),
            fn($s) => is_array($s) && isset($s['v'], $s['day'])));
        $list = array_slice($list, -max(2, $days));
        if (count($list) < 2) return null;
        $first = $list[0];
        $last = $list[count($list) - 1];
        $span = intval($last['day']) - intval($first['day']);
        if ($span <= 0) return null;
        return (floatval($last['v']) - floatval($first['v'])) / $span;
    }

    /** Her strongest mastery facet at or above mastery_pref_min, or null. */
    private static function masteryFacet(string $npcName, array $dynamics, array $cfg): ?string
    {
        $prefs = RelDynFacets::preferences($dynamics, $npcName);
        $best = null;
        $bestV = floatval($cfg['mastery_pref_min']);
        foreach ((array) $cfg['mastery_facets'] as $f) {
            $v = floatval($prefs[$f] ?? 0);
            if ($v >= $bestV) { $best = (string) $f; $bestV = $v + 1e-12; }
        }
        return $best;
    }

    /**
     * Backstory goals from her bio template: type => ['priority', 'keywords', 'facets'], scanned
     * once per template text (meta bio_hash) and kept as her backstory (meta 'backstory'; a new
     * text clears backstory_done); the kept read when the text is the same; [] for an NPC the
     * trait reader screens (Ashe).
     */
    private static function backstoryGoals(string $npcName, array &$meta, array $cfg): array
    {
        foreach (RelDynTraitRead::keyCandidates($npcName) as $key) {
            if (RelDynTraitRead::isSkipped($key)) return [];
        }
        try {
            $tpl = RelDynTraitRead::fetchTemplate($npcName);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('intrinsic goals bio template', $e);
            return [];
        }
        if ($tpl === null || RelDynTraitRead::isSkipped((string) $tpl['key'])) return [];
        $text = '';
        foreach ((array) $cfg['backstory_fields'] as $f) {
            $text .= ' ' . trim((string) ($tpl['fields'][$f] ?? ''));
        }
        $hash = sha1(json_encode([$text, $cfg['backstory']]));
        if (($meta['bio_hash'] ?? null) === $hash && is_array($meta['backstory'] ?? null)) return $meta['backstory'];
        if (($meta['bio_hash'] ?? null) !== $hash) unset($meta['backstory_done']);   // a new story
        $meta['bio_hash'] = $hash;
        $sentences = preg_split('/(?<=[.!?;])\s+/u', trim($text)) ?: [];
        $out = [];
        foreach ((array) $cfg['backstory'] as $type => $spec) {
            if (!in_array($type, self::TYPES, true)) continue;
            foreach ($sentences as $s) {
                $hit = false;
                foreach ((array) ($spec['patterns'] ?? []) as $p) {
                    if (RelDynQuests::names(strtolower($s), strtolower((string) $p))) { $hit = true; break; }
                }
                if (!$hit) continue;
                $out[$type] = ['priority' => floatval($spec['priority'] ?? 0.5),
                    'keywords' => self::properNames($s, $npcName, intval($cfg['keywords_max'])),
                    'facets' => RelDynFacetClassifier::keywordFacets($s, (array) (RelDynFacetClassifier::config()['tag_keywords'] ?? []))];
                break;
            }
        }
        $meta['backstory'] = $out;
        return $out;
    }

    /** Capitalised name phrases of a sentence (not its first word, not her own name or the player's). */
    public static function properNames(string $sentence, string $npcName, int $max): array
    {
        preg_match_all('/(?<!^)(?<![.!?]\s)\b(?:the\s+)?(\p{Lu}[\p{L}\'-]+(?:\s+\p{Lu}[\p{L}\'-]+)*)/u', trim($sentence), $m);
        $skip = array_map('strtolower', array_filter([$npcName, (string) ($GLOBALS['PLAYER_NAME'] ?? '')]));
        foreach (preg_split('/\s+/', strtolower($npcName)) ?: [] as $part) $skip[] = $part;
        $out = [];
        foreach ($m[1] as $name) {
            $name = trim($name);
            if ($name === '' || in_array(strtolower($name), $skip, true)) continue;
            $out[strtolower($name)] = $name;
            if (count($out) >= max(1, $max)) break;
        }
        return array_values($out);
    }

    // =====================================================================
    // LIFECYCLE
    // =====================================================================

    /**
     * The daily tick (once per game-calendar day on contact): a goal without progress that day
     * loses priority_decay_per_game_day per day missed and is dropped below drop_below_priority;
     * a backstory goal only down to backstory_priority_floor (or its own lower priority), never
     * dropped; the self-worth goal is held or lapses.
     */
    private static function tick(string $npcName, array &$dynamics, float $now, array $cfg): void
    {
        $today = self::day($now);
        foreach (array_keys((array) ($dynamics[self::KEY] ?? [])) as $i) {
            $g = $dynamics[self::KEY][$i] ?? null;
            if (!is_array($g) || empty($g['active'])) continue;
            $last = intval($g['last_tick_day'] ?? $today);
            if ($today <= $last) continue;
            $days = $today - $last;
            $dynamics[self::KEY][$i]['last_tick_day'] = $today;
            if (($g['type'] ?? '') === 'self_worth_recovery') {
                self::tickSelfWorth($npcName, $dynamics, $i, $days, $today, $cfg);
                continue;
            }
            $idle = max(0, $today - max($last, intval($g['last_progress_day'] ?? $last)));
            $floor = self::isBackstory($g) ? min(floatval($cfg['backstory_priority_floor']), floatval($g['priority'])) : 0.0;
            $p = floatval($g['priority']) - floatval($cfg['priority_decay_per_game_day']) * min($days, $idle);
            $dynamics[self::KEY][$i]['priority'] = round(max($floor, $p), 4);
        }
        foreach (array_reverse(array_keys((array) ($dynamics[self::KEY] ?? []))) as $i) {
            $g = $dynamics[self::KEY][$i];
            if (is_array($g) && !empty($g['active']) && ($g['type'] ?? '') !== 'self_worth_recovery' && !self::isBackstory($g)
                && floatval($g['priority']) < floatval($cfg['drop_below_priority'])) {
                self::end($dynamics, $i, 'faded', $today);
            }
        }
    }

    private static function tickSelfWorth(string $npcName, array &$dynamics, int $i, int $days, int $today, array $cfg): void
    {
        $g = $dynamics[self::KEY][$i];
        $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50);
        $start = floatval($g['maturity_start'] ?? $maturity);
        $margin = floatval($cfg['self_worth_margin']);
        $temperament = $dynamics['inferred_temperament'] ?? null;
        if ($maturity < $start - $margin) {
            // Slipped below where she stood: the goal lapses and the shame adds up (draft: shame +5)
            RelationshipDynamics::applyDelta('resentment_self', $dynamics, floatval($cfg['self_worth_failure_shame']), $temperament);
            self::end($dynamics, $i, 'lapsed', $today);
            RelationshipDynamics::log("[RelDyn-GOALS] {$npcName}: 'I need to change' lapsed (maturity " . round($maturity, 1) . " below " . round($start, 1) . ")");
            return;
        }
        if (($g['phase'] ?? 'change') !== 'change') return;
        $held = intval($g['held_days'] ?? 0) + ($maturity >= $start + $margin ? $days : 0);
        $dynamics[self::KEY][$i]['held_days'] = $held;
        if ($held >= intval($cfg['self_worth_sustain_days'])) {
            // Held: her baseline drifts up, and the goal becomes keeping it (draft success mode)
            $base = floatval($dynamics['dimensions']['maturity']['baseline'] ?? $start);
            $dynamics['dimensions']['maturity']['baseline'] = min(100.0, $base + floatval($cfg['self_worth_success_baseline']));
            $dynamics[self::KEY][$i]['phase'] = 'maintain';
            $dynamics[self::KEY][$i]['maturity_start'] = round($maturity, 2);
            RelationshipDynamics::log("[RelDyn-GOALS] {$npcName}: 'I need to change' held: maturity baseline "
                . round($base, 1) . ' -> ' . round($dynamics['dimensions']['maturity']['baseline'], 1));
        }
    }

    // =====================================================================
    // PROGRESS SOURCES
    // =====================================================================

    /**
     * Something experienced with the player (kind: place | topic | item), once per thing per game
     * day: every active goal with facets advances progress_experience x the thing's weight on the
     * goal's strongest shared facet.
     */
    public static function onExperience(string $npcName, array &$dynamics, string $kind, string $name, array $facets, float $now): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $now <= 0 || $facets === [] || trim($name) === '') return [];
        $meta = is_array($dynamics[self::META_KEY] ?? null) ? $dynamics[self::META_KEY] : [];
        $key = $kind . ':' . strtolower(trim($name));
        $today = self::day($now);
        if (intval($meta['credited'][$key] ?? -1) === $today) return [];
        $moved = [];
        foreach (self::active($dynamics) as $g) {
            $w = 0.0;
            foreach ((array) ($g['facets'] ?? []) as $f => $gw) {
                $w = max($w, floatval($gw) * floatval($facets[$f] ?? 0));
            }
            if ($w > 0 && self::advance($dynamics, (string) $g['id'], floatval($cfg['progress_experience']) * $w, $now)) $moved[] = $g['id'];
        }
        if ($moved !== []) {
            $credited = array_filter((array) ($meta['credited'] ?? []), fn($d) => intval($d) === $today);
            $credited[$key] = $today;
            $meta['credited'] = $credited;
            $dynamics[self::META_KEY] = $meta;
            RelationshipDynamics::log("[RelDyn-GOALS] {$npcName}: {$kind} '{$name}' advanced " . implode(', ', $moved));
        }
        return $moved;
    }

    /** An applied eval item: a positive exchange with a goal's tags advances it (progress_eval x significance). */
    public static function onEvalItem(string $npcName, array &$dynamics, array $n, float $now): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || empty($n['positive_interaction'])) return [];
        $tags = (array) ($n['tags'] ?? []);
        $moved = [];
        foreach (self::active($dynamics) as $g) {
            $want = (array) ($cfg['eval_tags'][$g['type']] ?? []);
            if ($want === [] || array_intersect($want, $tags) === []) continue;
            $amount = floatval($cfg['progress_eval']) * max(0.0, min(1.0, floatval($n['significance'] ?? 0)));
            if (self::advance($dynamics, (string) $g['id'], $amount, $now)) $moved[] = $g['id'];
        }
        if ($moved !== []) RelationshipDynamics::log("[RelDyn-GOALS] {$npcName}: eval [" . implode(',', $tags) . '] advanced ' . implode(', ', $moved));
        return $moved;
    }

    /** A quest stage that names her (quest event hook): goals whose keywords its name or objective carries advance goal_progress. */
    public static function onQuestEvent(string $npcName, array &$dynamics, array $event, float $now): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return [];
        $text = trim((string) ($event['name'] ?? '') . ' ' . (string) ($event['objective'] ?? ''));
        $moved = [];
        foreach (self::active($dynamics) as $g) {
            foreach ((array) ($g['keywords'] ?? []) as $k) {
                if (RelDynQuests::names($text, (string) $k)) {
                    if (self::advance($dynamics, (string) $g['id'], floatval(RelDynQuests::config()['goal_progress']), $now)) $moved[] = $g['id'];
                    break;
                }
            }
        }
        return $moved;
    }

    // =====================================================================
    // OUTPUT
    // =====================================================================

    /** The felt line of her top goal (a feeling, no numbers), or null. */
    public static function feltText(string $npcName, string $playerRef, array $dynamics): ?string
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return null;
        $top = self::active($dynamics)[0] ?? null;
        if ($top === null || floatval($top['priority']) < floatval($cfg['felt_min_priority'])) return null;
        $type = (string) $top['type'];
        if ($type === 'self_worth_recovery' && ($top['phase'] ?? 'change') === 'maintain') $type = 'self_worth_maintain';
        $text = $cfg['felt_text'][$type] ?? null;
        if (!is_string($text) || trim($text) === '') return null;
        return strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerRef] + self::phraseVars($top, $cfg));
    }

    /**
     * A goal's {PURSUIT} (its strongest facet as a pursuit) and {SUBJECT} (' tied to ' its first
     * keyword, or '') for felt text: the goal's own line, and the impulse layer's inner conflict.
     */
    public static function phraseVars(array $goal, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $facet = null;
        foreach ((array) ($goal['facets'] ?? []) as $f => $w) {
            if ($facet === null || floatval($w) > floatval($goal['facets'][$facet])) $facet = (string) $f;
        }
        $pursuit = $facet !== null ? (string) ($cfg['pursuit'][$facet] ?? str_replace('_', ' ', $facet)) : 'what matters to them';
        $keywords = array_values(array_filter((array) ($goal['keywords'] ?? []), 'is_string'));
        return ['{PURSUIT}' => $pursuit, '{SUBJECT}' => $keywords !== [] ? ' tied to ' . $keywords[0] : ''];
    }

    /** Jev: the active goals (numbers). */
    public static function jev(array $dynamics): array
    {
        return array_map(fn($g) => [
            'type' => (string) $g['type'], 'priority' => round(floatval($g['priority'] ?? 0), 3),
            'progress' => round(floatval($g['progress'] ?? 0), 3), 'source' => (string) ($g['source'] ?? ''),
            'phase' => isset($g['phase']) ? (string) $g['phase'] : null,
            'keywords' => array_values((array) ($g['keywords'] ?? [])),
        ], self::active($dynamics));
    }
}
