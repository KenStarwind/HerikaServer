<?php
/**
 * Relationship Dynamics — Reputation: what she has heard before she has seen (reputation-layer;
 * dimension draft "Reputation vs Experience", roadmap PR 9)
 *
 * effective_stranger_baseline = baseline + reputation offset, the offset fading as she gets to
 * know the player. What the player is known for comes from RelDynPlayer::profile() (core's
 * Skyrim tracked stats, the eventlog's kills, the journal, the gold ledger; RelDyn never guesses):
 *   fame    0..1 = the soft-or of the fame evidence table (dragon souls, quests, questlines,
 *           main quests, powerful kills)
 *   infamy  0..1 = the soft-or of the infamy evidence table (murders, lifetime bounty,
 *           assaults, thefts, pickpocketing, horse theft, trespass)
 *   status  the profile's status pillar 0..1 (wealth, property, standing)
 * Offsets (dimension points, the draft's table; per-NPC through her signed facet preferences,
 * decisions §6):
 *   respect += fame x fame_respect  + infamy x infamy_respect x power_pull
 *              + status x wealth_respect x status_pull
 *   trust   += fame x fame_trust    - infamy x infamy_trust
 *   comfort -= infamy x infamy_comfort
 *   power_pull  = clamp(mean(pref danger, pref dark) / pull_full, -1, 1): some respect power,
 *                 some despise it ("respect +-10 (some respect power)")
 *   status_pull = clamp(mean(pref wealth, pref luxury) / pull_full, 0, 1): "from status-oriented NPCs"
 * then capped (RelationshipDynamics::REPUTATION_CAPS). Computed once, on the first contact the
 * profile knows anything (pre-contact: what she heard before meeting), and kept; the held offset
 * follows the weight on every contact (prerequest). A stranger's first impression only: an NPC
 * who already knows the player (metBefore: an interaction history, core's bond with a title or
 * an affinity outside the stranger band, a context tier reached) heard nothing new (offset 0).
 * The held offset is what was actually applied: at the edge of a dimension's range less is
 * taken, and exactly that much is given back as it fades (held offsets are neutral).
 * Weight = 0.5 ^ (meaningful interactions / fade_half_interactions): 1.5 puts it under 10% after
 * five meaningful interactions and near zero after fifteen (the draft). A meaningful interaction
 * is an applied eval item of at least meaningful_significance, or an exchange the legacy
 * classifier scored.
 * The offset is a held temporary offset (RelationshipDynamics::heldTemporaryOffset): the rubber
 * band and baseline drift do not treat it as who she is.
 * Not in CHIM 3.4.1 core, so not here: thane titles, faction ranks, rumours (the cascade
 * network would carry them).
 *
 * The prompt-gating port (who knows the player) reads scores() for its fame axis; RelDyn gives
 * the tone (a felt 'reputation' line while the weight holds), never who the player is.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynReputation
{
    const KEY = '_reputation';
    const DIMS = ['trust', 'respect', 'comfort'];

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // evidence key => [half, weight] (RelDynPlayer evidence format; 'stat:' = Skyrim tracked stat)
            'fame' => [
                'dragons' => [3, 1.0], 'stat:Questlines Completed' => [2, 0.9], 'stat:Main Quests Completed' => [10, 0.8],
                'stat:Quests Completed' => [25, 0.7], 'eventlog:powerful_kills' => [10, 0.3],
            ],
            'infamy' => [
                'stat:Murders' => [2, 1.0], 'stat:Total Lifetime Bounty' => [1000, 0.8], 'stat:Assaults' => [5, 0.5],
                'stat:Items Stolen' => [30, 0.4], 'stat:Pockets Picked' => [10, 0.4], 'stat:Horses Stolen' => [2, 0.3],
                'stat:Trespasses' => [10, 0.2],
            ],
            // dimension points at fame / infamy / status 1
            'fame_respect' => 20.0, 'fame_trust' => 5.0,
            'infamy_trust' => 20.0, 'infamy_comfort' => 10.0, 'infamy_respect' => 10.0,
            'wealth_respect' => 8.0,
            'pull_full' => 0.5,                    // preference mean (-1..1) that counts in full
            'fade_half_interactions' => 1.5,       // meaningful interactions that halve the weight
            'meaningful_significance' => 0.3,      // eval significance 0..1 that counts as meaningful
            // the felt line: while the weight is at least felt_min_weight and fame or infamy at least felt_min_score
            'felt_min_weight' => 0.5,
            'felt_min_score' => 0.2,
            'felt_text' => [
                'fame' => '{NAME} has heard stories of what {PLAYER} has done and meets them with a respectful curiosity: the benefit of the doubt, for now',
                'infamy' => '{NAME} has heard dark things said about {PLAYER} and keeps a careful, watchful distance until they show otherwise',
                'both' => '{NAME} has heard stories about {PLAYER}, some admiring and some dark, and has not made up their mind',
            ],
        ];
    }

    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('reputation');
        return array_replace(self::configDefaults(), is_array($stored) ? $stored : []);
    }

    /** The evidence tables (RelDynPlayer reads the tracked stats they name). */
    public static function evidenceTables(): array
    {
        $cfg = self::config();
        return [(array) $cfg['fame'], (array) $cfg['infamy']];
    }

    /**
     * The player's standing, NPC-independent (the prompt-gating port's fame axis):
     * ['fame' => 0..1, 'infamy' => 0..1, 'status' => 0..1, 'known' => bool].
     */
    public static function scores(?array $profile = null): array
    {
        $cfg = self::config();
        $profile = $profile ?? RelDynPlayer::profile();
        $fame = RelDynPlayer::evidenceScore($profile, (array) $cfg['fame']);
        $infamy = RelDynPlayer::evidenceScore($profile, (array) $cfg['infamy']);
        $status = $profile['pillars']['status'] ?? null;
        return ['fame' => round(floatval($fame ?? 0), 4), 'infamy' => round(floatval($infamy ?? 0), 4),
            'status' => round(floatval($status ?? 0), 4), 'known' => $fame !== null || $infamy !== null || $status !== null];
    }

    /** Offsets (dimension points) for these scores and her preferences, capped. */
    public static function offsets(array $scores, array $prefs, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $full = max(1e-9, floatval($cfg['pull_full']));
        $power = max(-1.0, min(1.0, ((floatval($prefs['danger'] ?? 0) + floatval($prefs['dark'] ?? 0)) / 2) / $full));
        $statusPull = max(0.0, min(1.0, ((floatval($prefs['wealth'] ?? 0) + floatval($prefs['luxury'] ?? 0)) / 2) / $full));
        $fame = floatval($scores['fame'] ?? 0);
        $infamy = floatval($scores['infamy'] ?? 0);
        $status = floatval($scores['status'] ?? 0);
        $o = [
            'respect' => $fame * floatval($cfg['fame_respect']) + $infamy * floatval($cfg['infamy_respect']) * $power
                + $status * floatval($cfg['wealth_respect']) * $statusPull,
            'trust' => $fame * floatval($cfg['fame_trust']) - $infamy * floatval($cfg['infamy_trust']),
            'comfort' => -$infamy * floatval($cfg['infamy_comfort']),
        ];
        foreach (RelationshipDynamics::REPUTATION_CAPS as $dim => $caps) {
            if (isset($o[$dim])) $o[$dim] = round(max(floatval($caps['min']), min(floatval($caps['max']), $o[$dim])), 4);
        }
        return $o;
    }

    public static function weight(array $state, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $n = max(0, intval($state['meaningful'] ?? 0));
        return pow(0.5, $n / max(1e-9, floatval($cfg['fade_half_interactions'])));
    }

    /**
     * Does this NPC already know the player (so no first impression is taken)? An interaction
     * history (interaction_count / total_positive_interactions, the April decay factor's
     * measure), core's Player type other than neutral, core affinity ($coreAff, core points;
     * default the mirror) outside the stranger tier, or a context tier high-water mark reached.
     */
    public static function metBefore(array $dynamics, ?float $coreAff = null): bool
    {
        if (max(intval($dynamics['interaction_count'] ?? 0), intval($dynamics['total_positive_interactions'] ?? 0)) > 0) return true;
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        if ($type !== '' && $type !== 'neutral') return true;
        $aff = $coreAff ?? (is_numeric($dynamics['_aff_mirror_x'] ?? null) ? RelationshipDynamics::getCoreAffinity($dynamics) : null);
        if ($aff !== null && RelationshipDynamics::getCurrentTier($aff) !== 'stranger') return true;
        return intval($dynamics['context_tier_hwm'] ?? 0) >= 1;
    }

    /**
     * On contact (prerequest, after core's Player entry was read: $coreAff core points): fix the
     * raw offsets at the first contact the profile knows anything (nothing for someone she
     * already knows: metBefore), and move the held offset toward raw x weight (the change since
     * the last contact). What is held is what the range let it take.
     */
    public static function apply(array &$dynamics, string $npcName, ?float $coreAff = null): void
    {
        $cfg = self::config();
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $applied = (array) ($state['effective'] ?? []);
        if (empty($cfg['enabled'])) {
            if ($applied !== []) {
                RelationshipDynamics::reverseAppliedDeltas($dynamics, $applied, '', 'reputation');
                $state['effective'] = [];
                $dynamics[self::KEY] = $state;
            }
            return;
        }
        if (!is_array($state['raw'] ?? null)) {
            if (intval($state['meaningful'] ?? 0) > 0 || self::metBefore($dynamics, $coreAff)) {
                $state['raw'] = array_fill_keys(self::DIMS, 0.0);   // met before anything was known
            } else {
                $scores = self::scores();
                if (!$scores['known']) return;   // nothing known yet: ask again on the next load
                $state['raw'] = self::offsets($scores, RelDynFacets::preferences($dynamics, $npcName), $cfg);
                $state['fame'] = $scores['fame'];
                $state['infamy'] = $scores['infamy'];
                $state['status'] = $scores['status'];
                RelationshipDynamics::log("[RelDyn-REPUTATION] {$npcName}: heard of the player (fame {$scores['fame']}, infamy {$scores['infamy']}, status {$scores['status']}) -> " . json_encode($state['raw']));
            }
        }
        $w = self::weight($state, $cfg);
        $effective = [];
        foreach (self::DIMS as $dim) {
            if (!isset($dynamics['dimensions'][$dim])) continue;
            $target = round(floatval($state['raw'][$dim] ?? 0) * $w, 4);
            $held = floatval($applied[$dim] ?? 0);
            $delta = $target - $held;
            $def = RelationshipDynamics::getDimensionDefinition($dim);
            if (abs($delta) > 1e-6 && $def) {
                // Only what the range lets it take is held (and later given back)
                $before = floatval($dynamics['dimensions'][$dim]['x'] ?? 0);
                $after = max((float) $def['range_min'], min((float) $def['range_max'], $before + $delta));
                $dynamics['dimensions'][$dim]['x'] = $after;
                $held += $after - $before;
            }
            if (abs($held) > 1e-6) $effective[$dim] = round($held, 4);
        }
        $state['effective'] = $effective;
        $state['weight'] = round($w, 4);
        $dynamics[self::KEY] = $state;
    }

    /** A meaningful interaction (an eval item of meaningful_significance or more, or a legacy-scored exchange). */
    public static function countInteraction(array &$dynamics, float $significance): bool
    {
        $cfg = self::config();
        if ($significance < floatval($cfg['meaningful_significance'])) return false;
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $state['meaningful'] = intval($state['meaningful'] ?? 0) + 1;
        $dynamics[self::KEY] = $state;
        return true;
    }

    /** The held offset on $dim right now (dimension points). */
    public static function heldOffset(array $dynamics, string $dim): float
    {
        return floatval($dynamics[self::KEY]['effective'][$dim] ?? 0.0);
    }

    /** The felt line while her first impression still holds (a feeling, no numbers), or null. */
    public static function feltText(string $npcName, string $playerRef, array $dynamics): ?string
    {
        $cfg = self::config();
        $s = $dynamics[self::KEY] ?? null;
        if (empty($cfg['enabled']) || !is_array($s) || !is_array($s['raw'] ?? null)) return null;
        if (self::weight($s, $cfg) < floatval($cfg['felt_min_weight'])) return null;
        $min = floatval($cfg['felt_min_score']);
        $fame = floatval($s['fame'] ?? 0) >= $min;
        $infamy = floatval($s['infamy'] ?? 0) >= $min;
        $key = $fame && $infamy ? 'both' : ($fame ? 'fame' : ($infamy ? 'infamy' : null));
        $text = $key !== null ? ($cfg['felt_text'][$key] ?? null) : null;
        return is_string($text) && $text !== '' ? strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerRef]) : null;
    }

    /** Jev: null before anything was heard, else the numbers. */
    public static function jev(array $dynamics): ?array
    {
        $s = $dynamics[self::KEY] ?? null;
        if (!is_array($s) || !is_array($s['raw'] ?? null)) return null;
        return ['fame' => round(floatval($s['fame'] ?? 0), 3), 'infamy' => round(floatval($s['infamy'] ?? 0), 3),
            'weight' => round(self::weight($s), 3), 'meaningful' => intval($s['meaningful'] ?? 0),
            'offsets' => array_map(fn($v) => round(floatval($v), 2), (array) ($s['effective'] ?? []))];
    }
}
