<?php
/**
 * Relationship Dynamics — vocal style: how much an NPC talks in bed, and how they sound.
 *
 * Ken, 2026-10-01 (decisions §24, Sharmat scene awareness: "Vocalization is per NPC: silence_chance by speaking style and by
 * temperament (Bold vocal, Romantic whispered, Guarded minimal, Anxious reassurance-seeking)"). Direction, not a spec: so this
 * is one reading of the NPC, dynamic and scaled by who they are, with no permanent gate and nobody immune to a feeling:
 *
 *   RelDynVocal::decide($npcName, $dynamics)  ->  style, silence_chance, pace, a line about how the NPC sounds
 *
 * THE STYLE. Which of five registers the NPC's character leans toward, from the trait vector and the attachment corners (not
 * the temperament label, which is only a preset of the same traits):
 *   vocal      bold and expressive: loud, plainspoken, says what feels good
 *   whispered  warm and tender: soft, close, murmured words and endearments
 *   minimal    guarded and restrained: breath, a few words at most, and they land because they are rare
 *   seeking    anxious: looks to the player for reassurance, asks whether it is good, whether they are still wanted
 *   sharp      toxic corner, proud and reactive: demanding, biting, hard to please
 *   open       no register leans hard enough: no line, the NPC sounds like the NPC
 *
 * THE SILENCE CHANCE (0..1, never 0 and never 1). The chance that a given scene beat gets no spoken line at all (the game's own
 * moans and breaths still play: they are OStim's, not the model's). A base, moved by who the NPC is (guard and restraint
 * quiet; expressiveness and confidence loud; an avoidant corner quiet, an anxious one talks more), eased by how close the
 * bond is (the passion and how far the player has been let in: a guarded NPC is eased less), and made quieter by what
 * weighs on the NPC (the consent factors: a quarrel, the ick, withdrawal, pulling back, fear; being talked into it, or
 * being unsure). Bounded, and Sharmat never lets the quiet run unbroken: a beat after a long silence always speaks.
 *
 * THE PACE. A multiplier on Sharmat's scene voice speed: a whisperer a touch slower, an anxious one a touch quicker.
 *
 * PUBLISHED to core_npc_master.plugin_extended_data.reldyn.vocal (publish(): when the style changed or the chance moved
 * by more than a dead band; 'gamets' stamps when) for Sharmat, which reads it (reldyn_vocal_policy.php, a local hook that does
 * nothing without RelDyn). RelDyn never writes Sharmat's store.
 *
 * Units: traits, intensities, closeness and the chance 0..1 (unitless); dimension points 0..100; pace a multiplier near 1.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynVocal
{
    const STORAGE_KEY = 'vocal';
    const VERSION = 1;

    const STYLES = ['vocal', 'whispered', 'minimal', 'seeking', 'sharp', 'open'];

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'vocal' (nested tables merge per entry). Serene's starting values for Ken's §24 ruling (the MDD
     * gives none); tune after playtest.
     */
    public static function configDefaults(): array
    {
        return [
            // Off: RelDyn publishes no vocal style and every NPC talks as the speak style and the settings say.
            'enabled' => true,

            // --- the silence chance: base + trait slopes (on trait - 0.5) + the corners - the closeness relief + what weighs ---
            'silence' => [
                'base' => 0.40,
                // slopes on (trait - 0.5): guard G and restraint D quiet; expressiveness E and confidence C loud
                'traits' => ['G' => 0.45, 'D' => 0.30, 'E' => -0.40, 'C' => -0.25],
                // attachment corners (weights 0..1 over secure | anxious | avoidant | toxic, blended at the NPC's axes)
                'corner' => ['secure' => 0.0, 'anxious' => -0.15, 'avoidant' => 0.20, 'toxic' => 0.05],
                // closeness (0..1): passion and let-in readings (points between from and full), weighted; relief is how much
                // a full closeness takes off, less for a guarded NPC: relief x (1 - guard_hold x G)
                'closeness' => [
                    'passion' => ['from' => 10.0, 'full' => 70.0], 'let_in' => ['from' => 25.0, 'full' => 70.0],
                    'passion_weight' => 0.6, 'relief' => 0.20, 'guard_hold' => 0.4,
                ],
                // what weighs (RelDynConsent factors, soft-or'ed 0..1) adds this much at full; the last consent stance adds
                // its own: a yes given in to, a yes with doubts
                'weigh' => 0.30, 'appeasing' => 0.25, 'hesitant' => 0.08,
                'min' => 0.05, 'max' => 0.85,
            ],

            // --- the style: scores = sum slope x (trait - 0.5) + corner weight x bonus; the top score wins when it
            // clears 'open_below', else 'open'. Trait codes: G guard, E expressiveness, C confidence, Pd pride, L reactivity,
            // W warmth, D restraint, Po possessiveness. ---
            'styles' => [
                'vocal'     => ['traits' => ['E' => 0.6, 'C' => 1.0, 'G' => -0.6, 'D' => -0.4], 'corner' => []],
                'whispered' => ['traits' => ['W' => 0.9, 'E' => 0.5, 'C' => -0.4, 'Pd' => -0.3, 'G' => -0.3], 'corner' => []],
                'minimal'   => ['traits' => ['G' => 1.0, 'D' => 0.7, 'E' => -0.8], 'corner' => ['avoidant' => 0.25]],
                'seeking'   => ['traits' => ['C' => -0.8, 'Po' => 0.3, 'E' => 0.2], 'corner' => ['anxious' => 0.35, 'toxic' => 0.15]],
                'sharp'     => ['traits' => ['Pd' => 0.3, 'L' => 0.3, 'W' => -0.3], 'corner' => ['toxic' => 0.60]],
            ],
            'open_below' => 0.10,

            // --- the pace multiplier by style ---
            'pace' => ['vocal' => 1.0, 'whispered' => 0.92, 'minimal' => 0.95, 'seeking' => 1.05, 'sharp' => 1.03, 'open' => 1.0],

            // --- publish: the chance is republished only when it moved by more than this (or the style changed) ---
            'dead_band' => 0.03,

            // --- felt text: how the NPC sounds in bed. {NAME} the NPC, {PLAYER} the player; no pronoun for the NPC (they
            // are every gender). 'open' says nothing. ---
            'felt_text' => [
                'vocal'     => "{NAME} is open about it in bed: loud, plainspoken, happy to say what feels good and what is wanted, and to hear it back.",
                'whispered' => "{NAME} is soft in bed: low, close, murmured words and endearments for {PLAYER}, nothing loud.",
                'minimal'   => "{NAME} says little in bed: breath, a sound, a few words at most, and what is said lands because it is rare. Commentary is not {NAME}'s way.",
                'seeking'   => "{NAME} is anxious in bed and looks to {PLAYER} for reassurance: is it good, is {PLAYER} still here, is {NAME} still wanted. Words come in small, searching pieces.",
                'sharp'     => "{NAME} is sharp in bed: demanding and biting, hard to please, saying what is wanted as an order or turning a tender moment into a contest.",
                'open'      => null,
            ],
        ];
    }

    private const MERGED_TABLES = ['silence', 'styles', 'pace'];

    /** The vocal settings: stored config per setting, its nested tables merged per entry ('felt_text' per style). */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('vocal');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        foreach (['traits', 'corner', 'closeness'] as $s) {
            $cfg['silence'][$s] = array_replace((array) $defaults['silence'][$s], is_array($stored['silence'][$s] ?? null) ? $stored['silence'][$s] : []);
        }
        foreach (['passion', 'let_in'] as $s) {
            $cfg['silence']['closeness'][$s] = array_replace((array) $defaults['silence']['closeness'][$s], is_array($stored['silence']['closeness'][$s] ?? null) ? $stored['silence']['closeness'][$s] : []);
        }
        foreach (array_keys((array) $defaults['styles']) as $style) {
            $cfg['styles'][$style] = array_replace((array) $defaults['styles'][$style], is_array($stored['styles'][$style] ?? null) ? $stored['styles'][$style] : []);
        }
        $cfg['felt_text'] = array_replace($defaults['felt_text'], is_array($stored['felt_text'] ?? null) ? $stored['felt_text'] : []);
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private static function between(float $v, float $from, float $full): float
    {
        return $full > $from ? self::clamp01(($v - $from) / ($full - $from)) : ($v >= $full ? 1.0 : 0.0);
    }

    // =====================================================================
    // THE STYLE (pure)
    // =====================================================================

    /** The score of each style for these traits and attachment corner weights (secure | anxious | avoidant | toxic, 0..1). */
    public static function styleScores(array $traits, array $corners, ?array $cfg = null): array
    {
        $styles = (array) (($cfg ?? self::config())['styles']);
        $out = [];
        foreach ($styles as $style => $row) {
            $s = 0.0;
            foreach ((array) ($row['traits'] ?? []) as $code => $slope) {
                $s += floatval($slope) * (floatval($traits[$code] ?? 0.5) - 0.5);
            }
            foreach ((array) ($row['corner'] ?? []) as $corner => $bonus) {
                $s += floatval($bonus) * floatval($corners[$corner] ?? 0.0);
            }
            $out[(string) $style] = round($s, 4);
        }
        return $out;
    }

    /** The register the NPC leans toward: the top style when it clears 'open_below', else 'open'. */
    public static function styleOf(array $scores, ?array $cfg = null): string
    {
        if ($scores === []) return 'open';
        arsort($scores);
        $top = array_key_first($scores);
        return $scores[$top] >= floatval((($cfg ?? self::config())['open_below'] ?? 0.10)) ? (string) $top : 'open';
    }

    // =====================================================================
    // THE SILENCE CHANCE (pure)
    // =====================================================================

    /** How close the bond is, 0..1: the passion and how far the player has been let in. */
    public static function closeness(array $dynamics, ?array $cfg = null): float
    {
        $c = (array) ((($cfg ?? self::config())['silence'])['closeness']);
        $passion = self::between(RelationshipDynamics::getEffectivePassion($dynamics), floatval($c['passion']['from']), floatval($c['passion']['full']));
        $letIn = self::between(RelDynPullback::letIn($dynamics), floatval($c['let_in']['from']), floatval($c['let_in']['full']));
        $w = self::clamp01(floatval($c['passion_weight']));
        return self::clamp01($w * $passion + (1.0 - $w) * $letIn);
    }

    /**
     * The chance that a scene beat of this NPC goes unspoken, with its parts (for the editor and the tests).
     *
     * @return array{chance: float, parts: array<string, float>}
     */
    public static function silence(array $dynamics, array $traits, array $corners, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $s = (array) $cfg['silence'];
        $parts = ['base' => floatval($s['base'])];
        $t = 0.0;
        foreach ((array) $s['traits'] as $code => $slope) $t += floatval($slope) * (floatval($traits[$code] ?? 0.5) - 0.5);
        $parts['traits'] = $t;
        $c = 0.0;
        foreach ((array) $s['corner'] as $corner => $v) $c += floatval($v) * floatval($corners[$corner] ?? 0.0);
        $parts['corner'] = $c;
        $cl = (array) $s['closeness'];
        $close = self::closeness($dynamics, $cfg);
        $parts['closeness'] = -floatval($cl['relief']) * $close * max(0.0, 1.0 - floatval($cl['guard_hold']) * floatval($traits['G'] ?? 0.5));
        $states = RelDynConsent::states($dynamics, $traits);
        $weigh = RelDynConsent::weight($states, $traits, $dynamics)['weight'];
        $parts['weighs'] = floatval($s['weigh']) * floatval($weigh);
        $stance = is_array($dynamics[RelDynConsent::KEY] ?? null) ? (string) ($dynamics[RelDynConsent::KEY]['stance'] ?? '') : '';
        $parts['stance'] = $stance === 'appeasing' ? floatval($s['appeasing']) : ($stance === 'hesitant' ? floatval($s['hesitant']) : 0.0);
        $chance = array_sum($parts);
        $chance = max(floatval($s['min']), min(floatval($s['max']), $chance));
        return ['chance' => round($chance, 4), 'parts' => array_map(fn($v) => round($v, 4), $parts)];
    }

    // =====================================================================
    // THE READING
    // =====================================================================

    /**
     * The NPC's vocal style, now. Pure on the state ($dynamics) and the config.
     *
     * @return array{v: int, style: string, silence_chance: float, pace: float, scores: array, parts: array, felt: ?string}
     */
    public static function decide(string $npcName, array $dynamics, ?array $cfg = null, string $playerName = 'the player'): array
    {
        $cfg = $cfg ?? self::config();
        $traits = RelDynConcern::traitsOf($dynamics);
        $corners = RelationshipDynamics::attachmentWeights($dynamics);
        $scores = self::styleScores($traits, $corners, $cfg);
        $style = self::styleOf($scores, $cfg);
        $silence = self::silence($dynamics, $traits, $corners, $cfg);
        $pace = floatval(((array) $cfg['pace'])[$style] ?? 1.0);
        return [
            'v' => self::VERSION, 'style' => $style, 'silence_chance' => $silence['chance'], 'pace' => round($pace, 3),
            'scores' => $scores, 'parts' => $silence['parts'], 'felt' => self::feltText($npcName, $playerName, $style, $cfg),
        ];
    }

    /** The feeling in words (the NPC's name, no pronoun, no digits); null for the open register. */
    public static function feltText(string $npcName, string $playerName, string $style, ?array $cfg = null): ?string
    {
        $text = (($cfg ?? self::config())['felt_text'][$style] ?? null);
        return is_string($text) && $text !== ''
            ? RelDynPronouns::fill(str_replace(['{NAME}', '{PLAYER}'], [$npcName, $playerName], $text), $npcName)
            : null;
    }

    // =====================================================================
    // PUBLISH (the prerequest hook)
    // =====================================================================

    /** What Sharmat reads. */
    public static function payload(array $reading): array
    {
        return [
            'v' => self::VERSION, 'enabled' => true, 'style' => (string) $reading['style'],
            'silence_chance' => round(floatval($reading['silence_chance']), 2), 'pace' => round(floatval($reading['pace']), 2),
            'felt' => $reading['felt'],
        ];
    }

    /**
     * Decide and publish to plugin_extended_data.reldyn.vocal when the style changed or the chance moved by more than the
     * dead band (the published chance is the stored one rounded to a hundredth: a request does not write for noise);
     * 'gamets' stamps when. With the switch off it publishes {enabled: false} once (Sharmat reads that as no vocal style).
     * Never writes Sharmat's store. Returns the reading, or null when it was switched off.
     */
    public static function publish(string $npcName, array $dynamics, string $playerName = 'the player'): ?array
    {
        $npcId = RelDynStorage::resolveNpcId($npcName);
        $cfg = self::config();
        $stored = $npcId === null ? null : (RelDynStorage::getAll($npcId)[self::STORAGE_KEY] ?? null);
        if (empty($cfg['enabled'])) {
            if ($npcId !== null && (!is_array($stored) || !empty($stored['enabled']))) {
                RelDynStorage::setKey($npcId, self::STORAGE_KEY, ['v' => self::VERSION, 'enabled' => false, 'gamets' => RelationshipDynamics::currentGamets()]);
            }
            return null;
        }
        $reading = self::decide($npcName, $dynamics, $cfg, $playerName);
        if ($npcId === null) return $reading;
        $payload = self::payload($reading);
        if (is_array($stored) && !empty($stored['enabled'])) {
            $cmp = $stored;
            unset($cmp['gamets']);
            $band = floatval($cfg['dead_band']);
            $same = ($stored['style'] ?? null) === $payload['style']
                && ($stored['felt'] ?? null) === $payload['felt']
                && abs(floatval($stored['silence_chance'] ?? -1) - $payload['silence_chance']) <= $band
                && abs(floatval($stored['pace'] ?? -1) - $payload['pace']) <= 0.005;
            if ($cmp == $payload || $same) return $reading;
        }
        $payload['gamets'] = RelationshipDynamics::currentGamets();
        if (!RelDynStorage::setKey($npcId, self::STORAGE_KEY, $payload)) {
            error_log("[RelDyn-VOCAL] ERROR {$npcName}: publishing the vocal style failed");
        } elseif (!is_array($stored) || ($stored['style'] ?? null) !== $payload['style']) {
            error_log("[RelDyn-VOCAL] {$npcName}: " . ($stored['style'] ?? 'none') . " -> {$payload['style']} (quiet on {$payload['silence_chance']} of beats)");
        }
        return $reading;
    }

    /**
     * Publish again after the state moved (the end of postrequest): Sharmat reads the published style in its own prerequest,
     * which sorts before RelDyn's. Never throws.
     */
    public static function refresh(string $npcName, array $dynamics): void
    {
        try {
            self::publish($npcName, $dynamics, (string) ($GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'the player'));
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('vocal refresh', $e);
        }
    }
}
