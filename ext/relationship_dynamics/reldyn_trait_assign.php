<?php
/**
 * RelDyn personality traits, phase 2: assignment (D:\docs\reldyn-personality-traits-design.md
 * §4.1, §4.3, §4.4, §4.5; decisions 2026-09-23 §16).
 *
 * Precedence, per trait (highest wins):
 *   1. the editor's per-trait override (profile_overrides.trait_vector);
 *   2. a preset override: the editor's preset (profile_overrides.temperament, or a label written
 *      on the NPC by the editor / Sharmat / an arc), config npc_overrides (a preset name, or a
 *      hand-set trait_vector: Ashe), an incoming Sharmat speech-style label;
 *   3. the bio read, blended over the prior by its confidence;
 *   4. the prior: base + voice + class + faction + skills + race (race minor, ruling #7).
 * MARAS is not a source (retired; no 'maras' key is written on 3.4.1).
 *
 * Prior (§4.4): 0.5 per trait, possessiveness Po_base = 0.15 + 0.35 (1 - C_prior); the summed
 * non-bio offsets are capped at +-0.20 per trait. Race is a pull of RACE_WEIGHT (0.10) toward
 * the retired race vote's preset (at most +-0.05 on any trait).
 * Combination (§4.3): value = prior + min(1, 0.8 conf) (read - prior), so a strong read moves
 * 80% of the way and no evidence leaves the prior; maturity_start blends the same way over the
 * A9 fallback model 17 + 26 D + 30 Rs of the blended vector.
 *
 * Pure: every input is passed in; resolve() reads nothing.
 * Units: traits 0..1, offsets in trait units, maturity_start 0..100.
 */

final class RelDynTraitAssign
{
    /** Cap on the summed non-bio offsets per trait (§4.4). */
    const PRIOR_CAP = 0.20;

    /** A read with conf 1 moves this far from the prior to the read value (§4.3). */
    const READ_WEIGHT = 0.8;

    /** Race: a pull of this weight toward the retired race vote's preset (ruling #7: minor). */
    const RACE_WEIGHT = 0.10;

    /** Voice families (§4.4 table), offsets in trait units. Key = voice family id. */
    const VOICE_OFFSETS = [
        'EvenToned'      => ['D' => 0.05],
        'Nord'           => ['E' => 0.05, 'C' => 0.05, 'Rs' => 0.05],
        'Commoner'       => ['G' => -0.05, 'W' => 0.05],
        'Commander'      => ['G' => 0.05, 'E' => -0.05, 'C' => 0.15, 'Pd' => 0.05, 'Rs' => 0.05, 'D' => 0.10, 'Pr' => 0.10],
        'Soldier'        => ['G' => 0.05, 'E' => -0.10, 'C' => 0.05, 'Rs' => 0.05, 'L' => -0.05, 'D' => 0.15, 'Pr' => 0.05],
        'Brute'          => ['E' => 0.05, 'C' => 0.10, 'Pd' => 0.05, 'L' => 0.05, 'W' => -0.05, 'D' => -0.10, 'Po' => 0.05],
        'Orc'            => ['G' => 0.05, 'C' => 0.10, 'Pd' => 0.05, 'Rs' => 0.05],
        'Condescending'  => ['G' => 0.10, 'C' => 0.05, 'Pd' => 0.15, 'Rs' => -0.05, 'W' => -0.15, 'Po' => 0.05, 'Pr' => -0.05],
        'ElfHaughty'     => ['G' => 0.10, 'E' => -0.05, 'C' => 0.05, 'Pd' => 0.10, 'W' => -0.10, 'D' => 0.05],
        'YoungEager'     => ['G' => -0.10, 'E' => 0.15, 'C' => -0.05, 'L' => 0.05, 'W' => 0.10, 'D' => -0.05],
        'SlyCynical'     => ['G' => 0.10, 'E' => 0.05, 'C' => 0.05, 'W' => -0.05, 'D' => -0.05],
        'DarkElfCynical' => ['G' => 0.15, 'W' => -0.10],
        'DarkElf'        => ['G' => 0.05],
        'Coward'         => ['G' => 0.05, 'E' => 0.05, 'C' => -0.15, 'Rs' => -0.10, 'L' => 0.05, 'Pr' => -0.05],
        'Shrill'         => ['E' => 0.10, 'Rs' => -0.05, 'L' => 0.10, 'W' => -0.05, 'D' => -0.10, 'Po' => 0.05],
        'Sultry'         => ['G' => -0.05, 'E' => 0.10, 'C' => 0.10, 'W' => 0.05],
        'Drunk'          => ['G' => -0.05, 'E' => 0.10, 'Rs' => -0.05, 'L' => 0.10, 'W' => 0.05, 'D' => -0.15],
        'OldKindly'      => ['G' => -0.10, 'Rs' => 0.05, 'W' => 0.15, 'Pr' => 0.10],
        'OldGrumpy'      => ['G' => 0.10, 'Rs' => 0.05, 'L' => -0.10, 'W' => -0.10, 'D' => 0.05],
        'Warlock'        => ['G' => 0.10, 'C' => 0.05, 'W' => -0.10],
        'Bandit'         => ['C' => 0.05, 'L' => 0.05, 'W' => -0.10, 'D' => -0.10],
    ];

    /** Voice type (after sk_ and the gender prefix) => family. Race-only, creature and unique voices: none. */
    const VOICE_FAMILIES = [
        'eventoned' => 'EvenToned', 'eventonedaccented' => 'EvenToned',
        'nord' => 'Nord', 'nordcommander' => 'Nord',
        'commoner' => 'Commoner', 'commoneraccented' => 'Commoner',
        'commander' => 'Commander',
        'soldier' => 'Soldier', 'guard' => 'Soldier',
        'brute' => 'Brute', 'orc' => 'Orc',
        'condescending' => 'Condescending', 'elfhaughty' => 'ElfHaughty',
        'youngeager' => 'YoungEager', 'slycynical' => 'SlyCynical',
        'darkelfcynical' => 'DarkElfCynical', 'darkelf' => 'DarkElf', 'darkelfcommoner' => 'DarkElf', 'dunmer' => 'DarkElf',
        'coward' => 'Coward', 'shrill' => 'Shrill', 'sultry' => 'Sultry', 'drunk' => 'Drunk',
        'oldkindly' => 'OldKindly', 'oldgrumpy' => 'OldGrumpy', 'warlock' => 'Warlock', 'bandit' => 'Bandit',
    ];

    /** Typos in the shipped npc_templates_v2 voice ids (§4.4). */
    const VOICE_ALIASES = ['hajiit' => 'khajiit', 'arognian' => 'argonian', 'youngeage' => 'youngeager'];

    /** Class archetype (profileArchetypes' class) => offsets (§4.4). */
    const CLASS_OFFSETS = [
        'Warrior'   => ['C' => 0.05, 'E' => 0.05],
        'Barbarian' => ['C' => 0.05, 'E' => 0.05],
        'Ranger'    => ['C' => 0.05, 'W' => -0.05],
        'Mage'      => ['G' => 0.05, 'D' => 0.05],
        'Thief'     => ['E' => 0.05, 'D' => -0.05],
        'Noble'     => ['Pd' => 0.10, 'W' => -0.05],
        'Merchant'  => ['W' => 0.05, 'Pd' => -0.05],
        'Healer'    => ['W' => 0.10, 'Pr' => 0.05],
        'Guard'     => ['D' => 0.10, 'Pr' => 0.05],
        'Bard'      => ['E' => 0.10, 'D' => -0.10],
        'Assassin'  => ['G' => 0.10, 'W' => -0.10, 'D' => 0.05],
    ];

    /** Faction groups: faction editor-id stems (letters and digits), exclusions, offsets (§4.4). Each group counts once. */
    const FACTION_OFFSETS = [
        'Companions'      => [['companions'], [], ['C' => 0.05, 'Pr' => 0.05]],
        'Housecarl'       => [['housecarl'], [], ['D' => 0.10, 'Pr' => 0.10]],
        'Guard'           => [['guard'], ['dawnguard', 'housecarl'], ['D' => 0.05]],
        'ThievesGuild'    => [['thievesguild'], [], ['G' => 0.05, 'D' => -0.05]],
        'DarkBrotherhood' => [['darkbrotherhood'], [], ['G' => 0.10, 'W' => -0.10]],
        'Vigilants'       => [['vigilant'], [], ['Pr' => 0.05, 'D' => 0.05]],
    ];

    /** Top skill (metadata.skills, the NPC's highest, >= SKILL_MIN_LEVEL, unique) => offsets (§4.4). */
    const SKILL_OFFSETS = [
        'restoration' => ['Pr' => 0.05],
        'speech'      => ['E' => 0.05, 'G' => -0.05],
        'sneak'       => ['G' => 0.05],
        'block'       => ['Pr' => 0.05],
        'heavyarmor'  => ['Pr' => 0.05],
    ];
    const SKILL_MIN_LEVEL = 25;

    /** The retired race vote's table (race stem => preset), now a minor pull (ruling #7). */
    const RACE_PRESETS = [
        ['darkelf', 'Guarded'], ['dunmer', 'Guarded'], ['highelf', 'Proud'], ['altmer', 'Proud'],
        ['woodelf', 'Playful'], ['bosmer', 'Playful'], ['nord', 'Bold'], ['orc', 'Proud'], ['orsimer', 'Proud'],
        ['redguard', 'Independent'], ['imperial', 'Humble'], ['breton', 'Guarded'], ['khajiit', 'Playful'], ['argonian', 'Stoic'],
    ];

    // =========================================================================
    // PRIORS
    // =========================================================================

    private static function key(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)) ?? '';
    }

    /** Voice family of a voice id ("sk_femalecommander" -> Commander), or null. */
    public static function voiceFamily(?string $voiceId): ?string
    {
        $v = self::key($voiceId);
        if ($v === '') return null;
        if (strncmp($v, 'sk', 2) === 0) $v = substr($v, 2);
        if (strncmp($v, 'female', 6) === 0) $v = substr($v, 6);
        elseif (strncmp($v, 'male', 4) === 0) $v = substr($v, 4);
        else return null;   // unique voices (sk_serana) carry no gender prefix: no prior
        $v = self::VOICE_ALIASES[$v] ?? $v;
        return self::VOICE_FAMILIES[$v] ?? null;
    }

    /** Faction groups present in a list of faction editor ids (rank >= 0 already filtered). */
    public static function factionGroups(array $factionNames): array
    {
        $out = [];
        foreach ($factionNames as $name) {
            $k = self::key($name);
            if ($k === '') continue;
            foreach (self::FACTION_OFFSETS as $group => [$stems, $excl, $_]) {
                if (isset($out[$group])) continue;
                foreach ($excl as $x) if (strpos($k, $x) !== false) continue 2;
                foreach ($stems as $s) if (strpos($k, $s) !== false) { $out[$group] = true; break; }
            }
        }
        return array_keys($out);
    }

    /** The NPC's top skill (lower-case name) when unique and >= SKILL_MIN_LEVEL, else null. */
    public static function topSkill(array $skills): ?string
    {
        $best = null;
        $top = -1.0;
        $tie = false;
        foreach ($skills as $name => $level) {
            if (!is_numeric($level)) continue;
            $l = floatval($level);
            if ($l > $top) { $top = $l; $best = self::key($name); $tie = false; }
            elseif ($l == $top) { $tie = true; }
        }
        return ($best !== null && !$tie && $top >= self::SKILL_MIN_LEVEL) ? $best : null;
    }

    /** The retired race vote's preset for a race (core_npc_master.race, e.g. "NordRace"), or null. */
    public static function racePreset(?string $race): ?string
    {
        $k = self::key($race);
        if ($k === '') return null;
        foreach (self::RACE_PRESETS as [$stem, $preset]) {
            if (strpos($k, $stem) !== false) return $preset;
        }
        return null;
    }

    /**
     * The prior vector (§4.4). $in: 'voice' (voice id), 'class' (class archetype), 'factions'
     * (editor ids), 'skills' (name => level), 'race'. Returns ['x' => code => 0..1,
     * 'offsets' => source => [code => offset], 'signals' => string[]].
     */
    public static function prior(array $in): array
    {
        $offsets = [];
        $signals = [];
        $fam = self::voiceFamily($in['voice'] ?? null);
        if ($fam !== null) { $offsets["voice:{$fam}"] = self::VOICE_OFFSETS[$fam]; }
        $class = $in['class'] ?? null;
        if (is_string($class) && isset(self::CLASS_OFFSETS[$class])) $offsets["class:{$class}"] = self::CLASS_OFFSETS[$class];
        foreach (self::factionGroups((array) ($in['factions'] ?? [])) as $g) $offsets["faction:{$g}"] = self::FACTION_OFFSETS[$g][2];
        $skill = self::topSkill((array) ($in['skills'] ?? []));
        if ($skill !== null && isset(self::SKILL_OFFSETS[$skill])) $offsets["skill:{$skill}"] = self::SKILL_OFFSETS[$skill];
        $race = self::racePreset($in['race'] ?? null);
        if ($race !== null) {
            $p = RelDynTraits::points()[$race];
            $row = [];
            foreach (RelDynTraits::TRAITS as $code => $_) {
                $d = round(self::RACE_WEIGHT * ($p[$code] - 0.5), 4);
                if (abs($d) > 1e-9) $row[$code] = $d;
            }
            $offsets["race:{$race}"] = $row;
        }

        $sum = array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.0);
        foreach ($offsets as $src => $row) {
            $signals[] = $src;
            foreach ($row as $code => $d) $sum[$code] += floatval($d);
        }
        $x = [];
        foreach ($sum as $code => $s) $sum[$code] = max(-self::PRIOR_CAP, min(self::PRIOR_CAP, $s));
        foreach (RelDynTraits::TRAITS as $code => $_) {
            if ($code === 'Po') continue;
            $x[$code] = max(0.0, min(1.0, 0.5 + $sum[$code]));
        }
        $poBase = 0.15 + 0.35 * (1.0 - $x['C']);
        $x['Po'] = max(0.0, min(1.0, $poBase + $sum['Po']));
        $ordered = [];
        foreach (RelDynTraits::TRAITS as $code => $_) $ordered[$code] = $x[$code];
        return ['x' => $ordered, 'offsets' => $offsets, 'signals' => $signals, 'sum' => $sum];
    }

    /** A9 fallback model: maturity_start from restraint and resilience (§2.2). */
    public static function maturityModel(array $x): float
    {
        return 17.0 + 26.0 * floatval($x['D'] ?? 0.5) + 30.0 * floatval($x['Rs'] ?? 0.5);
    }

    /**
     * Prior blended with a validated read (RelDynTraitRead::parse result, or null).
     * Returns ['x' => code => 0..1 + maturity_start, 'src' => storage name => [...]].
     */
    public static function combine(array $prior, ?array $read): array
    {
        $x = [];
        $src = [];
        foreach (RelDynTraits::TRAITS as $code => $name) {
            $p = floatval($prior[$code]);
            $r = $read['traits'][$name] ?? null;
            $conf = is_array($r) ? max(0.0, min(1.0, floatval($r['conf'] ?? 0))) : 0.0;
            $w = min(1.0, $conf * self::READ_WEIGHT);
            $v = is_array($r) ? $p + $w * (floatval($r['value']) - $p) : $p;
            $x[$code] = max(0.0, min(1.0, $v));
            $src[$name] = ['source' => $w > 0 ? 'bio' : 'prior', 'prior' => round($p, 4)]
                + ($w > 0 ? ['read' => floatval($r['value']), 'conf' => $conf, 'field' => $r['field'] ?? null, 'evidence' => $r['evidence'] ?? null] : []);
        }
        $pm = self::maturityModel($x);
        $m = $read['maturity_start'] ?? null;
        $mc = is_array($m) ? max(0.0, min(1.0, floatval($m['conf'] ?? 0))) : 0.0;
        $mw = min(1.0, $mc * self::READ_WEIGHT);
        $x['maturity_start'] = max(0.0, min(100.0, $mw > 0 ? $pm + $mw * (floatval($m['value']) - $pm) : $pm));
        $src['maturity_start'] = ['source' => $mw > 0 ? 'bio' : 'model', 'prior' => round($pm, 2)]
            + ($mw > 0 ? ['read' => floatval($m['value']), 'conf' => $mc, 'field' => $m['field'] ?? null, 'evidence' => $m['evidence'] ?? null] : []);
        return ['x' => $x, 'src' => $src];
    }

    // =========================================================================
    // RESOLVE (precedence §4.1)
    // =========================================================================

    /**
     * The NPC's vector. $in:
     *   'trait_override'  profile_overrides.trait_vector (validated partial map), or null
     *   'preset'          preset override label (editor / stored label / Sharmat), or null
     *   'preset_source'   'override' | 'stored' | 'sharmat' | 'preset' (config)
     *   'hand_set'        config npc_overrides trait_vector (storage names, may hold maturity_start), or null
     *   'maturity_start'  config npc_overrides maturity_start (0..100), or null
     *   'prior_in'        prior() inputs
     *   'read'            validated read result or null; 'read_state' its status; 'template_key', 'src_hash'
     *   'screened'        true for a skip-listed NPC (no evidence is ever stored)
     * Returns ['x', 'src', 'label', 'label_source', 'nearest' => [name, distance], 'prior' => prior()].
     */
    public static function resolve(array $in): array
    {
        $prior = self::prior((array) ($in['prior_in'] ?? []));
        $read = is_array($in['read'] ?? null) ? $in['read'] : null;
        $blend = self::combine($prior['x'], $read);
        $x = $blend['x'];
        $src = $blend['src'];
        $label = null;
        $labelSource = $read !== null ? 'read' : 'prior';

        $hand = is_array($in['hand_set'] ?? null) ? RelDynTraits::validOverride(array_diff_key($in['hand_set'], ['maturity_start' => 1])) : null;
        $preset = RelDynTraits::isPreset($in['preset'] ?? null) ? $in['preset'] : null;
        if ($preset !== null) {
            // an editor / stored / Sharmat preset, or a config preset name: the preset point
            $p = RelDynTraits::points()[$preset];
            foreach (RelDynTraits::TRAITS as $code => $name) {
                $x[$code] = $p[$code];
                $src[$name] = ['source' => 'preset', 'preset' => $preset];
            }
            $x['maturity_start'] = $p['maturity_start'];
            $src['maturity_start'] = ['source' => 'preset', 'preset' => $preset];
            $label = $preset;
            $labelSource = (string) ($in['preset_source'] ?? 'preset');
        } elseif ($hand !== null) {
            foreach ($hand as $name => $v) {
                $code = array_search($name, RelDynTraits::TRAITS, true);
                $x[$code] = $v;
                $src[$name] = ['source' => 'preset', 'preset' => 'hand-set'];
            }
            // the model maturity of the hand-set vector unless the conclusion names one
            $x['maturity_start'] = self::maturityModel($x);
            $src['maturity_start'] = ['source' => 'model'];
            $labelSource = 'hand-set';
        }
        if (is_numeric($in['maturity_start'] ?? null) && ($preset === null || ($in['preset_source'] ?? '') === 'preset')) {
            $x['maturity_start'] = max(0.0, min(100.0, floatval($in['maturity_start'])));
            $src['maturity_start'] = ['source' => 'preset', 'preset' => 'npc_overrides'];
        }
        $over = is_array($in['trait_override'] ?? null) ? RelDynTraits::validOverride($in['trait_override']) : null;
        foreach ((array) $over as $name => $v) {
            $code = array_search($name, RelDynTraits::TRAITS, true);
            $x[$code] = $v;
            $src[$name] = ['source' => 'override'];
        }
        if (!empty($in['screened'])) {
            foreach ($src as $name => $s) unset($src[$name]['evidence'], $src[$name]['field']);
        }
        $nearest = RelDynTraits::nearestPreset($x);
        if ($label === null) $label = $nearest['name'];
        return ['x' => $x, 'src' => $src, 'label' => $label, 'label_source' => $labelSource, 'nearest' => $nearest, 'prior' => $prior];
    }

    /** Egocentric / insecure tags from pride and confidence (C1: exact at the presets). */
    public static function tagsOf(array $x): array
    {
        $tags = [];
        if (RelDynTraits::egocentric(floatval($x['Pd'])) >= 0.5) $tags[] = 'egocentric';
        if (RelDynTraits::insecure(floatval($x['C'])) >= 0.5) $tags[] = 'insecure';
        return $tags;
    }
}
