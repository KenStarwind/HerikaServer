<?php
/**
 * Relationship Dynamics — creature moodifications (vampires and werewolves).
 *
 * Design: feedback_creature_moodifications (Ken's "moodifications"), roadmap
 * creature-moodifications, decisions 2026-09-23 §7:
 *   vampire  night: self_confidence +10, arousal +10, comfort +5, coord_m toward predatory
 *            day:   comfort -10, valence -15, maturity -3 (sun weakness erodes composure)
 *   werewolf full moon: an arousal spike, valence thrill (Bold) or fear (Guarded), maturity -10
 *            (beast blood overrides), coord_m aggressive, coord_f suppressed; halved on other
 *            nights; beast blood passive: a constant low arousal lift, maturity a little lower;
 *            after transforming back: shame (resentment_self), a comfort crash, maturity recovery
 *            (the beast's override lets go: what the full moon / night row took off maturity is given
 *            back, exactly, while she is in her own skin; the passive beast blood is not).
 * These are temporary offsets, like the physical-state bridges ("same pipeline, different
 * trigger"): the offsets of the NPC's current creature state are applied once through applyDelta,
 * held while the state holds and taken back exactly when it changes (reverseAppliedDeltas).
 * The April build added the rows x0.1 on every prerequest, so a chatty evening moved a vampire
 * further than a quiet one; the state now depends on the game clock only.
 *
 * Detection (CHIM 3.4.1 core data only, no MinAI), first match wins:
 *   $dynamics['creature_type']           the per-NPC override ('none' = not a creature)
 *   detection.npc_overrides              named lore creatures whose game data does not say so
 *                                        (Serana and Valerica are vampires by script)
 *   core_npc_master.race                 editor id: *RaceVampire, the beast races
 *   extended_data.factions               faction editor ids (rank >= 0: a member), exact names
 *                                        from Skyrim.esm / Dawnguard.esm / Dragonborn.esm: Aela,
 *                                        Farkas, Vilkas and Skjor are in CompanionsCircle, Kodlak in
 *                                        CompanionsCirclePlusKodlak (the Circle's beast blood)
 *   metadata.transformation_state        the plugin's form report (werewolf / vampire_lord), or a
 *                                        form the watch saw (observeForms)
 *   conf_opts CurrentParty isVampire     the plugin's Vampire keyword (0x000A82BB) on a follower
 * Thralls, hunters and "potential vampire" factions are not listed, so they never match.
 *
 * Moon (Skyrim's real cycle, not a guess): Masser and Secunda share one phase. The Skyrim
 * climate (Skyrim.esm CLMT SkyrimClimate, TNAM moon phase length) gives 3 days per phase, 8
 * phases (CommonLibSSE RE::Moon::Phases: full, waning gibbous, waning quarter, waning crescent,
 * new, waxing crescent, waxing quarter, waxing gibbous), a 24-day cycle. The phase is a function
 * of GameDaysPassed: the Creation Kit's GetCurrentMoonphase reads ((GameDaysPassed + 0.5) as Int)
 * % 24, full on 22, 23 and 0 (the phase turns at midday). CHIM's gamets is GameDaysPassed x 1e7
 * (AIAgent Plugin/Misc.cpp GetGameTimeStamp), so no calendar offset is needed; day_offset and
 * anchor_days stay in config in case a mod shifts the cycle.
 *
 * The vampire's day (Ken, 2026-10-01 §21): an inverted sleep schedule. From about dawn (6:00) to dusk
 * (18:00) a vampire is cranky wherever they are (the day row; vampire_sun_outdoors_only is the switch for
 * "only in the sun", off); from dusk to dawn they are ascendant (the night row). Two things make the day
 * worse: not feeding (blood thirst, below) and direct sunlight (outdoors, in the day, under a clear sky;
 * the 'vampire' table's sun row). The werewolf's night (20:00-5:00) is its own window and unchanged.
 *
 * Blood thirst: it grows per game day without feeding (vampire.thirst.per_game_day of a full thirst) and a
 * feeding signal sates it. Core reports no feeding, so the signals are a CONFIG LIST (vampire.thirst.signals):
 * each names the eventlog types, a text to match ({NAME} = the vampire) and the hours it counts in. The
 * default proxy is a night (18:00-6:00) in which the vampire fought and killed: a 'death' row "<NAME> has
 * defeated ...". A feeding mod that logs its own event is wired in by adding a signal, no code. First
 * sight of a vampire anchors them as fed (history is not replayed). Unfed adds the vampire.rows.unfed row
 * scaled by the thirst (half as much by night, unfed_night_scale). A Companions member cured of
 * the blood ("Purity") is still a werewolf by faction: the editor's creature_type 'none' is the answer
 * and needs no code (the editor's Creature field says so).
 *
 * State: $dynamics['_creature'] = ['type', 'source', 'state' (row key or null), 'key' (applied
 * signature), 'applied' (dim => actual applied points), 'form' (the last non-normal form seen:
 * ['state', 'since', 'last'] in raw gamets), 'shame_gamets' (raw gamets of the last shame),
 * 'thirst' (a vampire's ['fed_at', 'seen'] in raw gamets), 'thirst_level' (0..1 as last held), 'sun' (bool)].
 * Units: dimension points (0..100, valence -100..100); time on the game calendar (raw gamets).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynCreatures
{
    const STATE_KEY = '_creature';
    const VAMPIRE = 'vampire';
    const WEREWOLF = 'werewolf';
    const TYPES = [self::VAMPIRE, self::WEREWOLF];

    /** conf_opts row of the form watch (observeForms): lower-case npc name => the form seen. */
    const FORM_WATCH_ROW_ID = 'relationship_dynamics_creature_forms';

    /** Engine phase order (CommonLibSSE RE::Moon::Phases, 0 = full). */
    const MOON_PHASES = ['full', 'waning_gibbous', 'waning_quarter', 'waning_crescent', 'new',
        'waxing_crescent', 'waxing_quarter', 'waxing_gibbous'];

    /** Core forms (lib/core/transformation_state.php) => the creature they show. */
    const FORM_CREATURE = ['werewolf' => self::WEREWOLF, 'vampire_lord' => self::VAMPIRE];

    /** Per-request cache of detect() (core row + party reads), keyed by the request scope. */
    private static array $cache = [];
    private static ?string $cacheToken = null;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config key 'creatures' (a stored config replaces whole settings). Row values
     * are dimension points; the design's values where it gives them (feedback_creature_moodifications),
     * Serene's starting values where it names only a direction (marked "starting value").
     */
    public static function configDefaults(): array
    {
        return [
            'detection' => [
                // core_npc_master.race, lower-case letters/digits, substring
                'race' => [
                    self::VAMPIRE  => ['racevampire', 'vampirebeast', 'vampirelord'],
                    self::WEREWOLF => ['werewolf', 'werebear'],
                ],
                // extended_data.factions[].name (editor ids), exact, case-insensitive, rank >= 0
                'factions' => [
                    self::VAMPIRE  => ['VampireFaction', 'DLC1VampireFaction', 'DA03VampireFaction'],
                    self::WEREWOLF => ['WerewolfFaction', 'CompanionsCircle', 'CompanionsCirclePlusKodlak',
                                       'DLC2dunFrostmoonWerewolvesFaction', 'DLC2TribalWerebearFaction'],
                ],
                // lower-case npc_name => type: lore creatures their records do not mark
                // (DLC1Serana: NordRace, DLC1SeranaFaction; DLC1Valerica: DLC1ValericaFaction)
                'npc_overrides' => ['serana' => self::VAMPIRE, 'valerica' => self::VAMPIRE],
                'transformation' => true,      // metadata.transformation_state / the form watch
                'party_vampire_keyword' => true,
            ],
            'moon' => [
                'phase_days' => 3,       // Skyrim.esm SkyrimClimate TNAM moon phase length
                'day_offset' => 0.5,     // the phase turns at midday (GetCurrentMoonphase + 0.5)
                'anchor_days' => 2,      // full on cycle days 22, 23, 0
            ],
            // Night for the creature rows (game hours; RelationshipDynamics::isGameNight's 20-5
            // window, SkyrimClimate sunset ends 20:30 and sunrise begins 5:30)
            'night_start_hour' => 20.0,
            'night_end_hour' => 5.0,
            'rows' => [
                'vampire_night'  => ['self_confidence' => 10.0, 'arousal' => 10.0, 'comfort' => 5.0,
                                     'coord_m' => 5.0],          // coord_m: starting value (design: "toward predatory")
                'vampire_day'    => ['comfort' => -10.0, 'valence' => -15.0, 'maturity' => -3.0],
                // arousal / coord_m / coord_f / |valence|: starting values (the April row); maturity the design's
                'werewolf_moon'  => ['arousal' => 15.0, 'valence' => -10.0, 'maturity' => -10.0,
                                     'coord_m' => 10.0, 'coord_f' => -5.0],
                // beast blood passive (starting values; design: "constant low-level arousal
                // elevation, maturity baseline slightly lower")
                'werewolf_day'   => ['arousal' => 3.0, 'maturity' => -2.0],
            ],
            // A werewolf's other nights: the moon row x this ("halved on other nights")
            'werewolf_night_scale' => 0.5,
            // The full moon pulls while it is up (night); a full-moon day is the passive row
            'full_moon_needs_night' => true,
            // The werewolf moon row's valence: 'fight_fear' = thrill (+) when the NPC's own fall
            // reads as fight (RelDynTraits::bleedout net > 0), fear (-) otherwise, the row's size;
            // 'row' = the row's sign for everyone. No trait vector: the row's sign.
            'werewolf_valence' => 'fight_fear',
            // Vampire day row only in the sun (outdoors). false = by day wherever they are (the
            // design's wording, Ken 2026-10-01 §21: "wherever they are").
            'vampire_sun_outdoors_only' => false,
            // The vampire's inverted schedule, thirst and sunlight (Ken, 2026-10-01 §21). dawn_hour..dusk_hour is the
            // day (the cranky hours); the rest is the night row. rows (dimension points): 'sun' is added in direct
            // sunlight (outdoors, day, clear sky), 'unfed' x the thirst (0..1) while thirsty, x unfed_night_scale by
            // night. thirst.per_game_day: the thirst gained per game day without feeding (0.25 = ravenous after four);
            // step: the thirst is held in steps of this (so the held offsets do not move every turn); signals: the
            // feeding signals (module doc), the default proxy is a night kill. Starting values.
            'vampire' => [
                'dawn_hour' => 6.0,
                'dusk_hour' => 18.0,
                'rows' => [
                    'sun'   => ['comfort' => -8.0, 'valence' => -10.0, 'maturity' => -3.0],
                    'unfed' => ['comfort' => -6.0, 'valence' => -8.0, 'maturity' => -3.0, 'arousal' => 6.0],
                ],
                'unfed_night_scale' => 0.5,
                'thirst' => [
                    'enabled' => true,
                    'per_game_day' => 0.25,
                    'step' => 0.05,
                    'felt_from' => 0.25,        // the thirst at which it reads in felt text
                    'signals' => [
                        ['name' => 'night_kill', 'event_types' => ['death'], 'match' => '{NAME} has defeated',
                         'start_hour' => 18.0, 'end_hour' => 6.0],
                    ],
                ],
            ],
            // Back from beast form: a one-off spike through applyDelta (resentment_self, comfort;
            // starting values, design: "resentment_self spike, comfort crash"). 'scale': 'flat' =
            // the same for every werewolf (the design), 'fear_share' = x fear / (fight + fear).
            'post_transform' => [
                'rows' => [self::WEREWOLF => ['resentment_self' => 8.0, 'comfort' => -10.0]],
                // Maturity recovery (design: "post-transformation: ... maturity recovery"): the fraction 0..1 of the
                // maturity (any dimension) the full-moon / night row holds off her that is given back, exactly (no
                // physics: it is the same offset ending), when she is back in her own skin. 1.0 = all of it. The
                // passive beast blood (the day row) is her baseline and stays. Starting value.
                'recovery' => [self::WEREWOLF => ['maturity' => 1.0]],
                'scale' => 'flat',
                'felt_game_hours' => 12.0,   // the shame reads in felt text this long (game calendar)
            ],
            // Felt steering (feelings, never numbers). {NAME} = the NPC, {PLAYER} = the player.
            'felt_text' => [
                'vampire_night'     => "{NAME}'s vampiric nature is ascendant: sharper, hungrier, the mask of humanity thinner",
                'vampire_day'       => "the day weighs on {NAME}: light like grit behind the eyes, patience thin, composure costing effort",
                // added to the vampire's line while it holds
                'vampire_sun'       => "the open sun is worse, every bright minute a burn: {NAME} flinches from the light and wants shade",
                'vampire_unfed_day' => "it has been too long since {NAME} fed, and the hunger gnaws under the weariness",
                'vampire_unfed_night' => "{NAME} has gone too long without feeding, and the night's edge is hunger: restless, thin-tempered",
                'werewolf_moon_fear' => "the full moon pulls at {NAME}'s beast blood: fighting for control, everything raw and immediate",
                'werewolf_moon_thrill' => "the full moon sings in {NAME}'s beast blood: every sense wide open, restless, hungry for the hunt",
                'werewolf_night'    => "{NAME}'s beast blood stirs in the dark: sharper senses, shorter patience",
                'werewolf_shame'    => "{NAME} is raw after the change: quiet, slow to meet anyone's eyes, sick at what the beast may have done",
            ],
        ];
    }

    /** The creature settings: stored config per setting, defaults for the rest. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('creatures');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        // post_transform is laid over its defaults key by key: a table stored whole before a key existed
        // (the maturity recovery) still has it
        if (is_array($stored['post_transform'] ?? null)) $cfg['post_transform'] = array_replace($defaults['post_transform'], $stored['post_transform']);
        // The vampire table the same way, a level down for its rows and thirst (a table saved before a key existed keeps the defaults for it)
        if (is_array($stored['vampire'] ?? null)) {
            $v = array_replace($defaults['vampire'], $stored['vampire']);
            foreach (['rows', 'thirst'] as $sub) {
                if (is_array($stored['vampire'][$sub] ?? null)) $v[$sub] = array_replace($defaults['vampire'][$sub], $stored['vampire'][$sub]);
            }
            $cfg['vampire'] = $v;
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(RelationshipDynamics::configValue('creature_moodifications_enabled'));
    }

    // =====================================================================
    // MOON AND NIGHT (pure)
    // =====================================================================

    /**
     * Skyrim's moon phase at raw gamets (module doc), or null when the game clock is unknown.
     *
     * @return array|null ['index' => 0..7 (0 = full), 'name' => MOON_PHASES[index], 'full' => bool,
     *                    'cycle_day' => 0..cycle-1]
     */
    public static function moonPhase(float $gamets, ?array $cfg = null): ?array
    {
        if ($gamets <= 0) return null;
        $moon = (array) (($cfg ?? self::config())['moon'] ?? []);
        $phaseDays = max(1, intval($moon['phase_days'] ?? 3));
        $cycle = $phaseDays * count(self::MOON_PHASES);
        $day = (int) floor($gamets / RelationshipDynamics::GAMETS_PER_DAY + floatval($moon['day_offset'] ?? 0.5));
        $slot = (($day + intval($moon['anchor_days'] ?? 2)) % $cycle + $cycle) % $cycle;
        $index = intdiv($slot, $phaseDays);
        return ['index' => $index, 'name' => self::MOON_PHASES[$index], 'full' => $index === 0,
                'cycle_day' => (($day % $cycle) + $cycle) % $cycle];
    }

    /** Night for the creature rows at raw gamets (config night_start_hour..night_end_hour); false when unknown. */
    public static function isNight(float $gamets, ?array $cfg = null): bool
    {
        $hour = RelationshipDynamics::gameHourOfDay($gamets);
        if ($hour === null) return false;
        $cfg = $cfg ?? self::config();
        $start = floatval($cfg['night_start_hour'] ?? 20.0);
        $end = floatval($cfg['night_end_hour'] ?? 5.0);
        return $start > $end ? ($hour >= $start || $hour < $end) : ($hour >= $start && $hour < $end);
    }

    /** Is $hour inside [$from, $to) on a 24-hour clock that may wrap past midnight (18 .. 6)? */
    public static function hourInWindow(float $hour, float $from, float $to): bool
    {
        $hour = fmod(fmod($hour, 24.0) + 24.0, 24.0);
        return $from <= $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }

    /** The vampire settings (config 'vampire', every key present). */
    public static function vampireConfig(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        return array_replace(self::configDefaults()['vampire'], (array) ($cfg['vampire'] ?? []));
    }

    /**
     * Is it a vampire's day (the cranky hours: vampire.dawn_hour up to vampire.dusk_hour, 6:00 to 18:00) at
     * raw gamets? False when the clock is unknown. The inverted sleep schedule: day is when they ought to sleep.
     */
    public static function vampireDay(float $gamets, ?array $cfg = null): bool
    {
        $hour = RelationshipDynamics::gameHourOfDay($gamets);
        if ($hour === null) return false;
        $v = self::vampireConfig($cfg);
        return self::hourInWindow($hour, floatval($v['dawn_hour']), floatval($v['dusk_hour']));
    }

    // =====================================================================
    // BLOOD THIRST (vampires)
    // =====================================================================

    /**
     * The thirst 0..1 of a vampire at raw gamets: per_game_day of a full thirst for every game day since the
     * last feeding, held in steps (vampire.thirst.step). $thirst = the state's ['fed_at', 'seen']; none (never
     * seen) or thirst switched off = 0. Pure.
     */
    public static function thirstLevel(?array $thirst, float $gamets, ?array $cfg = null): float
    {
        $t = (array) (self::vampireConfig($cfg)['thirst'] ?? []);
        if (empty($t['enabled']) || !is_array($thirst) || !isset($thirst['fed_at']) || $gamets <= 0) return 0.0;
        $days = max(0.0, ($gamets - floatval($thirst['fed_at'])) / RelationshipDynamics::GAMETS_PER_DAY);
        $level = max(0.0, min(1.0, $days * max(0.0, floatval($t['per_game_day'] ?? 0.25))));
        $step = floatval($t['step'] ?? 0.05);
        return $step > 0 ? round(floor($level / $step + 1e-9) * $step, 4) : round($level, 4);
    }

    /**
     * Raw gamets of the feeding signals that fired for $npc in ($since, $until], newest first (module doc: the
     * config list vampire.thirst.signals). A signal names eventlog types, a text to match ({NAME} = the NPC; a
     * text without it matches as written) and optional start_hour / end_hour (the game hours it counts in).
     * A database failure is logged and reads as no signal.
     *
     * @return float[]
     */
    public static function feedingTimes(string $npc, float $since, float $until, ?array $cfg = null): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $until <= $since || trim($npc) === '') return [];
        $signals = (array) ((array) (self::vampireConfig($cfg)['thirst'] ?? []))['signals'] ?? [];
        $times = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) continue;
            $types = array_values(array_filter(array_map(fn($t) => preg_replace('/[^A-Za-z0-9_]/', '', (string) $t), (array) ($signal['event_types'] ?? []))));
            $match = trim((string) ($signal['match'] ?? ''));
            if ($types === [] || $match === '') continue;
            $pattern = str_replace('{NAME}', trim($npc), $match);
            $like = $db->escape(RelationshipDynamics::escapeLike($pattern));
            $typeList = "'" . implode("', '", $types) . "'";
            try {
                $rows = $db->fetchAll("SELECT gamets FROM eventlog WHERE type IN ({$typeList}) AND data LIKE '%{$like}%' ESCAPE '\\'"
                    . ' AND gamets > ' . intval($since) . ' AND gamets <= ' . intval($until) . ' ORDER BY gamets DESC LIMIT 100');
            } catch (\Throwable $e) {
                RelationshipDynamics::logError('vampire feeding signal ' . (string) ($signal['name'] ?? ''), $e);
                continue;
            }
            foreach ((array) $rows as $r) {
                $g = floatval($r['gamets'] ?? 0);
                if ($g <= 0) continue;
                if (isset($signal['start_hour'], $signal['end_hour'])) {
                    $hour = RelationshipDynamics::gameHourOfDay($g);
                    if ($hour === null || !self::hourInWindow($hour, floatval($signal['start_hour']), floatval($signal['end_hour']))) continue;
                }
                $times[] = $g;
            }
        }
        rsort($times);
        return $times;
    }

    /**
     * The vampire's thirst state after $gamets: first sight anchors them as fed (history is not replayed); a
     * feeding signal since the last look moves fed_at to its time; a clock that went back (a loaded save)
     * pulls both marks back with it. Returns ['fed_at', 'seen'] in raw gamets.
     */
    public static function observeFeeding(string $npc, ?array $thirst, float $gamets, ?array $cfg = null): array
    {
        if ($gamets <= 0) return is_array($thirst) ? $thirst : ['fed_at' => 0.0, 'seen' => 0.0];
        if (!is_array($thirst) || !isset($thirst['fed_at'], $thirst['seen'])) return ['fed_at' => $gamets, 'seen' => $gamets];
        $fed = floatval($thirst['fed_at']);
        $seen = floatval($thirst['seen']);
        if ($gamets < $seen) return ['fed_at' => min($fed, $gamets), 'seen' => $gamets];
        $times = self::feedingTimes($npc, $seen, $gamets, $cfg);
        if ($times !== []) $fed = max($fed, $times[0]);
        return ['fed_at' => $fed, 'seen' => $gamets];
    }

    // =====================================================================
    // DETECTION
    // =====================================================================

    /** Lower-case letters and digits only ("NordRaceVampire" -> "nordracevampire"). */
    private static function key($value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /**
     * Creature type from core row facts (pure): race editor id, faction list ([['name', 'rank']]),
     * the plugin's current form ('werewolf' | 'vampire_lord' | 'normal' | null), the party's
     * Vampire keyword (null = unknown). Returns ['type' => ?string, 'source' => ?string].
     */
    public static function classify(?string $race, array $factions, ?string $form = null, ?bool $partyVampire = null, ?array $cfg = null): array
    {
        $det = (array) (($cfg ?? self::config())['detection'] ?? []);
        $raceKey = self::key($race);
        if ($raceKey !== '') {
            foreach ((array) ($det['race'] ?? []) as $type => $needles) {
                foreach ((array) $needles as $needle) {
                    $needle = self::key($needle);
                    if ($needle !== '' && str_contains($raceKey, $needle)) return ['type' => (string) $type, 'source' => 'race'];
                }
            }
        }
        $member = [];
        foreach ($factions as $f) {
            if (is_array($f) && intval($f['rank'] ?? 0) >= 0) $member[] = self::key($f['name'] ?? '');
            elseif (is_string($f)) $member[] = self::key($f);
        }
        foreach ((array) ($det['factions'] ?? []) as $type => $names) {
            foreach ((array) $names as $name) {
                if (in_array(self::key($name), $member, true)) return ['type' => (string) $type, 'source' => 'faction'];
            }
        }
        if (!empty($det['transformation']) && $form !== null && isset(self::FORM_CREATURE[$form])) {
            return ['type' => self::FORM_CREATURE[$form], 'source' => 'transformation'];
        }
        if (!empty($det['party_vampire_keyword']) && $partyVampire === true) {
            return ['type' => self::VAMPIRE, 'source' => 'party'];
        }
        return ['type' => null, 'source' => null];
    }

    /** The plugin's form report on a core row (metadata.transformation_state), sanitized: ['state', 'gamets'] or null. */
    public static function coreForm(array $row): ?array
    {
        $meta = RelationshipDynamics::decodeProfileJson($row['metadata'] ?? null);
        $raw = $meta['transformation_state'] ?? null;
        if (!is_array($raw)) {
            if (empty($meta['transformation_state_type'])) return null;
            $raw = ['state' => $meta['transformation_state_type']];
        }
        $state = strtolower(trim((string) ($raw['state'] ?? '')));
        if ($state === '') $state = !empty($raw['is_werewolf_form']) ? 'werewolf' : (!empty($raw['is_vampire_lord_form']) ? 'vampire_lord' : 'normal');
        if (!in_array($state, ['normal', 'werewolf', 'vampire_lord'], true)) return null;
        return ['state' => $state, 'gamets' => is_numeric($raw['gamets'] ?? null) ? floatval($raw['gamets']) : 0.0];
    }

    /** The party's Vampire keyword for $npcName (conf_opts CurrentParty via CACHE_PARTY), null when not in the party / unknown. */
    public static function partyVampire(string $npcName): ?bool
    {
        $party = $GLOBALS['CACHE_PARTY'] ?? null;
        if (($party === null || $party === '') && function_exists('DataGetCurrentPartyConf') && !empty($GLOBALS['db'])) {
            try {
                $party = DataGetCurrentPartyConf();
            } catch (\Throwable $e) {
                RelationshipDynamics::logError('creature party read', $e);
                return null;
            }
        }
        $party = is_string($party) ? json_decode($party, true) : $party;
        if (!is_array($party)) return null;
        foreach ($party as $key => $member) {
            $name = is_array($member) ? ($member['name'] ?? $key) : $key;
            if (is_string($name) && strcasecmp(trim($name), trim($npcName)) === 0) {
                $v = is_array($member) ? ($member['isVampire'] ?? null) : null;
                if ($v === null) return null;
                return in_array(strtolower(trim((string) $v)), ['yes', 'true', '1'], true);
            }
        }
        return null;
    }

    /**
     * The NPC's creature type: override, named lore creature, then core data (classify). Core
     * reads are cached for the request scope. Returns ['type' => ?string, 'source' => ?string,
     * 'form' => ?array (coreForm of the row)].
     */
    public static function detect(string $npcName, array $dynamics): array
    {
        $override = $dynamics['creature_type'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            $o = strtolower(trim($override));
            if (in_array($o, self::TYPES, true)) return ['type' => $o, 'source' => 'override', 'form' => null];
            if ($o === 'none') return ['type' => null, 'source' => 'override', 'form' => null];
        }
        $cfg = self::config();
        $named = ((array) ($cfg['detection']['npc_overrides'] ?? []))[strtolower(trim($npcName))] ?? null;

        $token = RelationshipDynamics::requestScopeToken();
        if ($token === null || $token !== self::$cacheToken) {
            self::$cache = [];
            self::$cacheToken = $token;
        }
        $ck = strtolower(trim($npcName));
        if ($token === null || !array_key_exists($ck, self::$cache)) {
            $row = [];
            try {
                $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
            } catch (\Throwable $e) {
                RelationshipDynamics::logError('creature core row read', $e);
            }
            $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
            $entry = ['race' => (string) ($row['race'] ?? ''), 'factions' => (array) ($ext['factions'] ?? []),
                      'form' => $row ? self::coreForm($row) : null, 'party' => self::partyVampire($npcName)];
            if ($token !== null) self::$cache[$ck] = $entry;
        } else {
            $entry = self::$cache[$ck];
        }

        if (is_string($named) && in_array($named, self::TYPES, true)) {
            return ['type' => $named, 'source' => 'name', 'form' => $entry['form']];
        }
        // A form the watch saw (observeForms) counts as the plugin's report too
        $seenForm = $dynamics[self::STATE_KEY]['form']['state'] ?? null;
        $form = $entry['form']['state'] ?? null;
        if (($form === null || $form === 'normal') && is_string($seenForm)) $form = $seenForm;
        return self::classify($entry['race'], $entry['factions'], $form, $entry['party'], $cfg) + ['form' => $entry['form']];
    }

    // =====================================================================
    // THE ROW (pure)
    // =====================================================================

    /**
     * The creature row in effect: which row, and the offsets (dimension => points).
     *
     * @param string|null $type       vampire | werewolf | null
     * @param float       $gamets     raw gamets (game calendar)
     * @param bool|null   $interior   the NPC's place is inside (null = unknown)
     * @param float|null  $fightNet   the NPC's fight - fear (RelDynTraits::bleedout net; null = no vector)
     * @param array|null  $ctx        a vampire's world: ['thirst' => 0..1 (default 0), 'sky_clear' => bool|null (the weather
     *                                reads clear; null = unknown)]. Direct sunlight = their day, outdoors ($interior false), clear sky.
     * @return array ['state' => ?string, 'effects' => [dim => points], 'moon' => ?array, 'night' => bool, 'valence' => 'thrill'|'fear'|null,
     *                'sun' => bool (vampire in direct sunlight), 'thirst' => float (vampire, 0..1 as held)]
     */
    public static function rowFor(?string $type, float $gamets, ?bool $interior, ?float $fightNet, ?array $cfg = null, ?array $ctx = null): array
    {
        $cfg = $cfg ?? self::config();
        $moon = self::moonPhase($gamets, $cfg);
        $night = self::isNight($gamets, $cfg);
        $out = ['state' => null, 'effects' => [], 'moon' => $moon, 'night' => $night, 'valence' => null, 'sun' => false, 'thirst' => 0.0];
        if ($type === null || $gamets <= 0) return $out;
        $rows = (array) ($cfg['rows'] ?? []);
        $scale = 1.0;
        $row = null;
        $vampireDay = false;
        if ($type === self::VAMPIRE) {
            // the vampire's own day (6:00-18:00, inverted sleep schedule), not the werewolf's night
            $vampireDay = self::vampireDay($gamets, $cfg);
            $out['night'] = !$vampireDay;
            if (!$vampireDay) $row = 'vampire_night';
            elseif (!(!empty($cfg['vampire_sun_outdoors_only']) && $interior !== false)) $row = 'vampire_day';
        } elseif ($type === self::WEREWOLF) {
            $full = !empty($moon['full']);
            if ($full && ($night || empty($cfg['full_moon_needs_night']))) {
                $row = 'werewolf_moon';
            } elseif ($night) {
                $row = 'werewolf_moon';
                $scale = floatval($cfg['werewolf_night_scale'] ?? 0.5);
                $out['state'] = 'werewolf_night';
            } else {
                $row = 'werewolf_day';
            }
        }
        if ($row === null) return $out;
        $out['state'] = $out['state'] ?? $row;
        $effects = [];
        if ($type === self::VAMPIRE) {
            // Worse in direct sunlight (outdoors, their day, clear sky) and worse unfed; both add to the row
            $vamp = self::vampireConfig($cfg);
            $vrows = (array) ($vamp['rows'] ?? []);
            $thirst = max(0.0, min(1.0, floatval($ctx['thirst'] ?? 0.0)));
            $out['sun'] = $vampireDay && $interior === false && ($ctx['sky_clear'] ?? null) === true;
            $out['thirst'] = $thirst;
            if ($out['sun']) {
                foreach ((array) ($vrows['sun'] ?? []) as $dim => $v) $effects[$dim] = floatval($effects[$dim] ?? 0.0) + floatval($v);
            }
            if ($thirst > 0.0) {
                $unfed = $thirst * ($vampireDay ? 1.0 : floatval($vamp['unfed_night_scale'] ?? 0.5));
                foreach ((array) ($vrows['unfed'] ?? []) as $dim => $v) $effects[$dim] = floatval($effects[$dim] ?? 0.0) + floatval($v) * $unfed;
            }
        }
        foreach ((array) ($rows[$row] ?? []) as $dim => $v) {
            $v = floatval($v);
            if ($row === 'werewolf_moon' && $dim === 'valence') {
                if (($cfg['werewolf_valence'] ?? 'fight_fear') === 'fight_fear' && $fightNet !== null) {
                    $v = $fightNet > 0 ? abs($v) : -abs($v);
                }
                $out['valence'] = $v > 0 ? 'thrill' : ($v < 0 ? 'fear' : null);
            }
            $effects[$dim] = floatval($effects[$dim] ?? 0.0) + $v * $scale;
        }
        foreach ($effects as $dim => $v) {
            $v = round($v, 2);
            if (abs($v) >= 0.01) $effects[$dim] = $v; else unset($effects[$dim]);
        }
        ksort($effects);
        $out['effects'] = $effects;
        return $out;
    }

    /** The NPC's fight - fear (the bleedout read of its own traits), null without a trait vector. */
    public static function fightNet(array $dynamics): ?float
    {
        $probe = $dynamics;
        $r = RelationshipDynamics::bleedoutResponse($probe, false);
        return empty($r['vector']) ? null : floatval($r['net']);
    }

    /** The whole read for an NPC now: detection + row (+ the place's inside/outside when it matters). */
    public static function current(string $npcName, array $dynamics, ?float $gamets = null): array
    {
        $gamets = $gamets ?? RelationshipDynamics::currentGamets();
        $cfg = self::config();
        $det = self::detect($npcName, $dynamics);
        $interior = null;
        $ctx = null;
        if ($det['type'] === self::VAMPIRE) {
            // The place: inside or out and the sky (direct sunlight = outdoors, their day, clear weather), and the
            // thirst the last feeding signal left (observeFeeding keeps it in the state)
            $place = RelDynFacets::currentPlaceContext($npcName);
            $interior = !empty($place['known']) ? $place['is_interior'] : null;
            $ctx = ['sky_clear' => !empty($place['known']) ? RelDynFacets::skyIsClear((array) $place['weather']) : null,
                    'thirst' => self::thirstLevel($dynamics[self::STATE_KEY]['thirst'] ?? null, $gamets, $cfg)];
        }
        $net = $det['type'] === self::WEREWOLF ? self::fightNet($dynamics) : null;
        return ['type' => $det['type'], 'source' => $det['source'], 'form' => $det['form']]
            + self::rowFor($det['type'], $gamets, $interior, $net, $cfg, $ctx);
    }

    // =====================================================================
    // APPLY (prerequest)
    // =====================================================================

    /**
     * Prerequest: notice a return from beast form (the shame), then hold the offsets of the
     * current creature row. When the row (or its offsets) changed since the last turn, the
     * previous offsets are taken back exactly and the new ones applied through applyDelta.
     * Off, or not a creature: whatever was applied is taken back. Returns the state stored.
     */
    public static function update(string $npcName, array &$dynamics, ?string $temperament, ?float $gamets = null): array
    {
        $gamets = $gamets ?? RelationshipDynamics::currentGamets();
        $state = is_array($dynamics[self::STATE_KEY] ?? null) ? $dynamics[self::STATE_KEY] : [];
        $enabled = self::enabled();
        if ($enabled && self::detect($npcName, $dynamics)['type'] === self::VAMPIRE) {
            // The thirst the feeding signals leave, before the row is read (first sight anchors them as fed)
            $state['thirst'] = self::observeFeeding($npcName, is_array($state['thirst'] ?? null) ? $state['thirst'] : null, $gamets);
            $dynamics[self::STATE_KEY]['thirst'] = $state['thirst'];
        }
        $cur = $enabled ? self::current($npcName, $dynamics, $gamets)
            : ['type' => null, 'source' => null, 'form' => null, 'state' => null, 'effects' => [], 'moon' => null, 'valence' => null];

        if ($enabled) {
            self::observeReturn($npcName, $dynamics, $state, $cur, $gamets, $temperament);
        }

        $key = ($cur['state'] ?? '') . '|' . json_encode($cur['effects']);
        if ($key !== ($state['key'] ?? '|[]')) {
            if (!empty($state['applied']) && is_array($state['applied'])) {
                RelationshipDynamics::reverseAppliedDeltas($dynamics, $state['applied'], 'RelDyn-CREATURE', 'creature ' . ($state['state'] ?? 'row'));
                // taken back: no longer held (heldTemporaryOffset) while the new row is applied
                $dynamics[self::STATE_KEY]['applied'] = [];
            }
            $applied = [];
            foreach ($cur['effects'] as $dim => $delta) {
                if (!isset($dynamics['dimensions'][$dim])) continue;
                $actual = RelationshipDynamics::applyDelta($dim, $dynamics, floatval($delta), $temperament);
                if (abs($actual) > 0.0001) $applied[$dim] = $actual;
            }
            $state['applied'] = $applied;
            $state['key'] = $key;
            if ($cur['state'] !== null) {
                RelationshipDynamics::log("[RelDyn-CREATURE] {$npcName}: {$cur['type']} {$cur['state']} (moon "
                    . ($cur['moon']['name'] ?? 'unknown') . ') ' . json_encode($applied));
            }
        }
        $state['type'] = $cur['type'];
        $state['source'] = $cur['source'];
        $state['state'] = $cur['state'];
        $state['valence'] = $cur['valence'];
        $state['moon'] = $cur['moon']['name'] ?? null;
        $state['sun'] = !empty($cur['sun']);
        $state['thirst_level'] = round(floatval($cur['thirst'] ?? 0.0), 3);
        $dynamics[self::STATE_KEY] = $state;
        return $state;
    }

    /**
     * A return from beast form: a werewolf form was seen (the plugin's report on the core row, or
     * the form watch) and the row now reports the normal form later on the game clock. The
     * post_transform row is applied once through applyDelta (a spike, not an offset), the beast's
     * maturity drop held by the moon / night row is given back exactly (post_transform.recovery;
     * $state['recovered'] until the beast is seen again), and the watch entry is dropped. A form seen
     * now is remembered in $state['form'].
     */
    private static function observeReturn(string $npcName, array &$dynamics, array &$state, array $cur, float $gamets, ?string $temperament): void
    {
        $now = is_array($cur['form']) ? $cur['form'] : null;
        $watch = self::watchEntry($npcName);
        $seen = is_array($state['form'] ?? null) ? $state['form'] : null;
        foreach ([$watch, ($now !== null && $now['state'] !== 'normal') ? ['state' => $now['state'], 'since' => $now['gamets'] ?: $gamets, 'last' => $now['gamets'] ?: $gamets] : null] as $obs) {
            if (!is_array($obs) || !isset(self::FORM_CREATURE[$obs['state'] ?? ''])) continue;
            if ($seen === null || $seen['state'] !== $obs['state']) {
                $seen = ['state' => (string) $obs['state'], 'since' => floatval($obs['since'] ?? $gamets), 'last' => floatval($obs['last'] ?? $gamets)];
            } else {
                $seen['last'] = max(floatval($seen['last']), floatval($obs['last'] ?? 0));
            }
        }
        $state['form'] = $seen;
        if ($seen !== null && !empty($state['recovered'])) {
            // The beast is back after a recovery: the row's full offsets are applied again (the key no longer matches)
            unset($state['recovered']);
            $state['key'] = null;
        }
        if ($seen === null || $now === null || $now['state'] !== 'normal') return;
        $backAt = $now['gamets'] > 0 ? $now['gamets'] : $gamets;
        if ($backAt <= floatval($seen['last'])) return;

        // Back in their own skin
        $type = self::FORM_CREATURE[$seen['state']];
        $cfg = self::config();
        $pt = (array) ($cfg['post_transform'] ?? []);
        $row = (array) (((array) ($pt['rows'] ?? []))[$type] ?? []);
        $scale = 1.0;
        if (($pt['scale'] ?? 'flat') === 'fear_share') {
            $probe = $dynamics;
            $r = RelationshipDynamics::bleedoutResponse($probe, false);
            if (!empty($r['vector'])) {
                $sum = floatval($r['fight']) + floatval($r['fear']);
                $scale = $sum > 0 ? floatval($r['fear']) / $sum : 1.0;
            }
        }
        $applied = [];
        foreach ($row as $dim => $delta) {
            if (!isset($dynamics['dimensions'][$dim])) continue;
            $a = RelationshipDynamics::applyDelta($dim, $dynamics, floatval($delta) * $scale, $temperament);
            if (abs($a) > 0.0001) $applied[$dim] = round($a, 3);
        }
        if ($applied !== []) $state['shame_gamets'] = $backAt;
        // Maturity recovery: the beast's override lets go (only what the moon / night row holds: the passive blood stays)
        $given = [];
        if (in_array($state['state'] ?? null, ['werewolf_moon', 'werewolf_night'], true)) {
            $recovery = (array) (((array) ($pt['recovery'] ?? []))[$type] ?? []);
            foreach ($recovery as $dim => $fraction) {
                $held = floatval($state['applied'][$dim] ?? 0);
                $share = max(0.0, min(1.0, floatval($fraction)));
                if ($held >= 0.0 || $share <= 0.0 || !isset($dynamics['dimensions'][$dim])) continue;
                RelationshipDynamics::reverseAppliedDeltas($dynamics, [$dim => $held * $share], 'RelDyn-CREATURE', "beast {$dim} drop");
                $left = $held * (1.0 - $share);
                if (abs($left) > 0.0001) $state['applied'][$dim] = $left; else unset($state['applied'][$dim]);
                $given[$dim] = round(-$held * $share, 3);
            }
            if ($given !== []) $state['recovered'] = true;
        }
        $state['form'] = null;
        self::dropWatch($npcName);
        RelationshipDynamics::log("[RelDyn-CREATURE] {$npcName}: back from {$seen['state']} form: " . json_encode($applied)
            . ($given !== [] ? ' recovered ' . json_encode($given) : ''));
    }

    // =====================================================================
    // FORM WATCH (core's poll + every turn)
    // =====================================================================

    /**
     * Note every NPC the plugin reports in a beast / vampire lord form right now (core_npc_master
     * metadata.transformation_state), in one conf_opts row, so a form that comes and goes between
     * two turns with the NPC is still noticed on the return. Cheap: one filtered read; a write only
     * when an entry is new or its last-seen time moved. Returns the number of NPCs in a form now.
     */
    public static function observeForms(): int
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || !self::enabled() || empty(self::config()['detection']['transformation'])) return 0;
        try {
            $rows = $db->fetchAll("SELECT npc_name, metadata->'transformation_state' AS ts FROM core_npc_master"
                . " WHERE metadata->'transformation_state'->>'state' IN ('werewolf', 'vampire_lord')");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('creature form watch read', $e);
            return 0;
        }
        if (!is_array($rows) || $rows === []) return 0;
        $now = RelationshipDynamics::currentGamets();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            [$raw, $watch] = self::readWatch();
            $next = $watch;
            foreach ($rows as $r) {
                $ts = json_decode((string) ($r['ts'] ?? ''), true);
                $form = is_array($ts) ? self::coreForm(['metadata' => ['transformation_state' => $ts]]) : null;
                if ($form === null || $form['state'] === 'normal') continue;
                $at = $form['gamets'] > 0 ? $form['gamets'] : $now;
                $k = strtolower(trim((string) $r['npc_name']));
                $e = $next[$k] ?? null;
                if (!is_array($e) || ($e['state'] ?? null) !== $form['state']) {
                    $next[$k] = ['state' => $form['state'], 'since' => $at, 'last' => $at];
                } elseif ($at > floatval($e['last'] ?? 0)) {
                    $next[$k]['last'] = $at;
                }
            }
            if ($next === $watch || self::writeWatch($raw, $next)) return count($rows);
        }
        error_log('[RelDyn] ERROR creature form watch: the conf_opts row kept changing; this beat skipped');
        return count($rows);
    }

    /** [raw value or null, decoded watch] of the form watch row. */
    private static function readWatch(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return [null, []];
        $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::FORM_WATCH_ROW_ID]);
        $raw = is_array($row) && isset($row['value']) ? (string) $row['value'] : null;
        $watch = $raw !== null ? json_decode($raw, true) : [];
        if ($raw !== null && !is_array($watch)) {
            error_log('[RelDyn] ERROR creature form watch: conf_opts ' . self::FORM_WATCH_ROW_ID . ' unreadable; restarting it');
            $watch = [];
        }
        return [$raw, $watch];
    }

    /** Compare-and-set the watch row; false when another request wrote it first. */
    private static function writeWatch(?string $raw, array $next): bool
    {
        $db = $GLOBALS['db'];
        $value = json_encode((object) $next);
        $won = $raw === null
            ? $db->fetchOne('INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO NOTHING RETURNING id', [self::FORM_WATCH_ROW_ID, $value])
            : $db->fetchOne('UPDATE conf_opts SET value = $2 WHERE id = $1 AND value = $3 RETURNING id', [self::FORM_WATCH_ROW_ID, $value, $raw]);
        return isset($won['id']);
    }

    /** The watch entry for $npcName, or null. */
    public static function watchEntry(string $npcName): ?array
    {
        try {
            [, $watch] = self::readWatch();
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('creature form watch entry', $e);
            return null;
        }
        $e = $watch[strtolower(trim($npcName))] ?? null;
        return is_array($e) ? $e : null;
    }

    private static function dropWatch(string $npcName): void
    {
        $k = strtolower(trim($npcName));
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                [$raw, $watch] = self::readWatch();
                if (!array_key_exists($k, $watch)) return;
                unset($watch[$k]);
                if (self::writeWatch($raw, $watch)) return;
            }
            error_log("[RelDyn] ERROR creature form watch: could not drop {$npcName}; the next return reads it again");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('creature form watch drop', $e);
        }
    }

    // =====================================================================
    // FELT TEXT AND JEV
    // =====================================================================

    /**
     * The felt line of the NPC's creature state now (feelings, never numbers), or null: the
     * shame after a change (felt_game_hours) wins over the row's own text.
     */
    public static function feltText(string $npcName, array $dynamics, array $vars, ?float $gamets = null): ?string
    {
        if (!self::enabled()) return null;
        $gamets = $gamets ?? RelationshipDynamics::currentGamets();
        $cfg = self::config();
        $t = (array) ($cfg['felt_text'] ?? []);
        $shameAt = floatval($dynamics[self::STATE_KEY]['shame_gamets'] ?? 0);
        $window = floatval($cfg['post_transform']['felt_game_hours'] ?? 0) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $key = null;
        if ($shameAt > 0 && $gamets >= $shameAt && $gamets - $shameAt <= $window) {
            $key = 'werewolf_shame';
        } else {
            $cur = self::current($npcName, $dynamics, $gamets);
            if ($cur['state'] === 'werewolf_moon') $key = $cur['valence'] === 'thrill' ? 'werewolf_moon_thrill' : 'werewolf_moon_fear';
            elseif (in_array($cur['state'], ['vampire_night', 'vampire_day', 'werewolf_night'], true)) $key = $cur['state'];
        }
        if ($key === null || !isset($t[$key]) || trim((string) $t[$key]) === '') return null;
        $line = strtr((string) $t[$key], $vars);
        // A vampire's day or night is worse in the sun and unfed: the line says so (the stored table may predate these keys)
        if (isset($cur) && in_array($cur['state'] ?? null, ['vampire_day', 'vampire_night'], true)) {
            $defaults = self::configDefaults()['felt_text'];
            $extra = [];
            if (!empty($cur['sun'])) $extra[] = (string) ($t['vampire_sun'] ?? $defaults['vampire_sun']);
            if (floatval($cur['thirst'] ?? 0.0) >= floatval(self::vampireConfig($cfg)['thirst']['felt_from'] ?? 0.25)) {
                $unfedKey = $cur['state'] === 'vampire_day' ? 'vampire_unfed_day' : 'vampire_unfed_night';
                $extra[] = (string) ($t[$unfedKey] ?? $defaults[$unfedKey]);
            }
            foreach ($extra as $e) if (trim($e) !== '') $line .= '; ' . strtr($e, $vars);
        }
        return $line;
    }

    /** Jev's creature block (numbers, not prose): null for a non-creature. */
    public static function jev(array $dynamics): ?array
    {
        $s = $dynamics[self::STATE_KEY] ?? null;
        if (!is_array($s) || empty($s['type'])) return null;
        return ['type' => (string) $s['type'], 'state' => isset($s['state']) ? (string) $s['state'] : null,
                'moon' => isset($s['moon']) ? (string) $s['moon'] : null,
                'offsets' => array_map(fn($v) => round(floatval($v), 2), (array) ($s['applied'] ?? []))];
    }
}
