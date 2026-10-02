<?php
/**
 * Relationship Dynamics — the survival reader (physical-state bridges, the inert rows made live).
 *
 * The AIAgent fork's survival reporter (CHIM Plugin/SurvivalReporter.cpp, branch reldyn-survival)
 * writes one "info_survival" event to core's eventlog (any "info*" request is logged and never
 * spoken itself, but core's history builder, buildHistoricContext, hands every info* row it does not name
 * to the nearby NPCs as a narrator line: hideFromDialogue() below keeps this one out of it, since its
 * numbers are for RelDyn alone and a feeling is never a number): the party's physical conditions read softly from the optional survival mods. Last Seed
 * (hunger, thirst, fatigue), Frostfall (exposure, wetness), Campfire (who built the fire the party
 * is at), Dirt and Blood (the player and each NPC) and Survival Mode CC. This file reads the newest
 * report and turns it into the physical states RelationshipDynamics::detectPhysicalStates hands to
 * the existing rows (PHYSICAL_STATE_MODIFIERS): hungry, warm_fire, well_rested, exhausted, dirty,
 * bloody (and cold / wet from the exposure).
 *
 * Report (version 1), keys absent when a mod is not installed (unknown, never "fine"):
 *   {"v":1, "mods":[...],
 *    "player": {"hunger":0-5, "thirst":0-5, "fatigue":0-5, "exposure":0-6, "wet":0-3, "dirt":0-4,
 *               "blood":0-4, "cc":{"on":bool, "hunger":0-5, "exhaustion":0-5, "cold":0-5}},
 *    "fire":   {"near":bool, "heat":0-3, "dist":units, "builder":"player"|"other"},
 *    "actors": {"<name>": {"follower":bool, "d":units from the player, "hunger", "thirst", "dirty", "bloody"}}}
 * Every need is on the same scale: 0 = none / best, higher = worse (Last Seed's own 0..5).
 *
 * Who feels what:
 *   hungry      the NPC's own hunger or thirst when the mod tracks it for them; otherwise, for a
 *               follower, the party's (the player's level, the CC's hunger stage).
 *   exhausted / well_rested / cold / wet
 *               the party's (the player's fatigue, exposure, wetness), for a follower only: only the
 *               player carries these in the mods, and an NPC who is not travelling with the player has
 *               not shared the road. Rested is Last Seed's "rested" only (the CC's stage 0 is just "no need").
 *   warm_fire   a lit fire within the NPC's reach of the player's side. The warmth (and the passion)
 *               of the row goes to whoever built the fire: a fire the player built is warm_fire (the full
 *               row), any other fire is warm_fire_other (comfort only: nobody in particular to thank).
 *   dirty / bloody
 *               the player's dirt or blood as the NPC sees it (any NPC in the conversation), or the
 *               NPC's own (Dirt and Blood tracks NPCs too).
 * A missing, unreadable or stale report is "unknown": no state, never assumed. The numbers are
 * thresholds on those 0..5 scales (config physical_states.survival).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynSurvival
{
    /** The eventlog type the plugin's reporter writes. */
    const EVENT_TYPE = 'info_survival';

    /** Defaults for config physical_states.survival. */
    public static function configDefaults(): array
    {
        return [
            // A report older than this on the game clock is unknown (the plugin repeats an unchanged one every few real minutes)
            'max_age_game_hours' => 3.0,
            // Last Seed's scale: 0 Well Fed / Quenched / Rested, 1 Satisfied / Refreshed / Sharp, 2 Hungry / Thirsty / Tired,
            // 3 Very ..., 4 Ravenous / Parched / Haggard, 5 Starving / Dehydrated / Exhausted. The CC's stages are the same scale.
            'hungry_level' => 2,
            'thirsty_level' => 2,
            'exhausted_level' => 3,
            'well_rested_level' => 0,
            // Frostfall exposure 0 Warm, 1 Comfortable, 2 Cold ... 6; the CC's cold stage likewise. Wetness 2 = Wet, 3 = Drenched.
            'cold_level' => 2,
            'wet_level' => 2,
            // Dirt and Blood's player levels: Dirt1 is the lightest mark. Dirt3 and Blood2 and up read as dirty / bloody.
            'dirty_level' => 3,
            'bloody_level' => 2,
            // How far from the player (game units) still counts as at the player's fire: Frostfall's own heat range
            'fire_max_distance' => 600.0,
        ];
    }

    /**
     * Keeps the report out of every NPC's dialogue history. buildHistoricContext (lib/data_functions.php) renders each
     * info* eventlog row it does not exclude as a narrator line, and the plugin repeats the report on every change and
     * on a heartbeat: raw JSON of numbers in the nearby NPCs' context, pushing real dialogue out of its window. The
     * query takes an extension's extra predicate from EXT_CONTEXT_SQL_FILTER1 (an AND clause); this appends ours,
     * once, to whatever another extension set. RelDyn reads the report from the eventlog by its own query.
     */
    public static function hideFromDialogue(): void
    {
        $clause = " AND type<>'" . self::EVENT_TYPE . "' ";
        $set = $GLOBALS['EXT_CONTEXT_SQL_FILTER1'] ?? '';
        if (!is_string($set)) $set = '';
        if (strpos($set, $clause) === false) $GLOBALS['EXT_CONTEXT_SQL_FILTER1'] = $set . $clause;
    }

    /** Stored physical_states.survival over the defaults, key by key. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('physical_states');
        $stored = is_array($stored) && is_array($stored['survival'] ?? null) ? $stored['survival'] : [];
        return array_replace($defaults, $stored);
    }

    // =====================================================================
    // THE REPORT
    // =====================================================================

    /**
     * The newest survival report if it is fresh on the game clock, else null (unknown). Core prunes
     * the eventlog past a loaded save, so a rolled-back game never reads a report from its future.
     *
     * @return array|null the decoded report
     */
    public static function latest(?float $now = null): ?array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return null;
        try {
            $row = $db->fetchOne("SELECT data, gamets FROM eventlog WHERE type = '" . self::EVENT_TYPE . "' ORDER BY gamets DESC, ts DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('survival report read', $e);
            return null;
        }
        if (!is_array($row) || empty($row['data'])) return null;
        $report = json_decode((string) $row['data'], true);
        if (!is_array($report) || !isset($report['v'])) {
            error_log('[RelDyn] ERROR survival report: the latest ' . self::EVENT_TYPE . ' row is not a report; treating it as unknown');
            return null;
        }
        $now = $now ?? RelationshipDynamics::currentGamets();
        $at = floatval($row['gamets'] ?? 0);
        $maxAge = max(0.0, floatval(self::config()['max_age_game_hours'])) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        if ($now > 0 && $at > 0 && ($now - $at) > $maxAge) return null;
        return $report;
    }

    // =====================================================================
    // STATES (pure)
    // =====================================================================

    /** An integer level out of a report value, or null when absent / not a number. */
    private static function level($v): ?int
    {
        return is_numeric($v) ? intval(round(floatval($v))) : null;
    }

    /** The largest of the levels that are known; null when none is. */
    private static function worst(?int ...$levels): ?int
    {
        $known = array_filter($levels, fn($l) => $l !== null);
        return $known === [] ? null : max($known);
    }

    /** The NPC's entry in the report's actors table (case-insensitive), or null. */
    public static function actorEntry(array $report, string $npc): ?array
    {
        $want = strtolower(trim($npc));
        foreach ((array) ($report['actors'] ?? []) as $name => $entry) {
            if (is_array($entry) && strtolower(trim((string) $name)) === $want) return $entry;
        }
        return null;
    }

    /**
     * The physical states the report gives $npc now (module doc). Pure: no database, no clock.
     * Order is stable; no report is no state.
     *
     * @return string[] among hungry, exhausted, well_rested, cold, wet, warm_fire, warm_fire_other, dirty, bloody
     */
    public static function statesFor(string $npc, ?array $report, ?array $cfg = null): array
    {
        if (!is_array($report)) return [];
        $cfg = $cfg ?? self::config();
        $player = (array) ($report['player'] ?? []);
        $cc = is_array($player['cc'] ?? null) && !empty($player['cc']['on']) ? $player['cc'] : [];
        $entry = self::actorEntry($report, $npc);
        $follower = $entry !== null && !empty($entry['follower']);
        $states = [];

        // Hunger and thirst: their own where the mod tracks them, the party's for a follower
        $hunger = $entry !== null ? self::level($entry['hunger'] ?? null) : null;
        $thirst = $entry !== null ? self::level($entry['thirst'] ?? null) : null;
        if ($follower) {
            $hunger = $hunger ?? self::worst(self::level($player['hunger'] ?? null), self::level($cc['hunger'] ?? null));
            $thirst = $thirst ?? self::level($player['thirst'] ?? null);
        }
        if (($hunger !== null && $hunger >= intval($cfg['hungry_level'])) || ($thirst !== null && $thirst >= intval($cfg['thirsty_level']))) {
            $states[] = 'hungry';
        }

        // Rest, cold and wet: the party's, for a follower
        if ($follower) {
            $fatigue = self::level($player['fatigue'] ?? null);
            $tired = self::worst($fatigue, self::level($cc['exhaustion'] ?? null));
            if ($tired !== null && $tired >= intval($cfg['exhausted_level'])) {
                $states[] = 'exhausted';
            } elseif ($fatigue !== null && $fatigue <= intval($cfg['well_rested_level']) && ($tired === null || $tired <= intval($cfg['well_rested_level']))) {
                $states[] = 'well_rested';
            }
            $cold = self::worst(self::level($player['exposure'] ?? null), self::level($cc['cold'] ?? null));
            if ($cold !== null && $cold >= intval($cfg['cold_level'])) $states[] = 'cold';
            $wet = self::level($player['wet'] ?? null);
            if ($wet !== null && $wet >= intval($cfg['wet_level'])) $states[] = 'wet';
        }

        // A fire: lit and within reach, warm toward whoever built it
        $fire = is_array($report['fire'] ?? null) ? $report['fire'] : null;
        if ($fire !== null && !empty($fire['near']) && $entry !== null) {
            $d = is_numeric($entry['d'] ?? null) ? floatval($entry['d']) : null;
            if ($d !== null && $d <= floatval($cfg['fire_max_distance'])) {
                $states[] = ($fire['builder'] ?? '') === 'player' ? 'warm_fire' : 'warm_fire_other';
            }
        }

        // Dirt and blood: the player's as the NPC sees them, or the NPC's own
        $dirt = self::level($player['dirt'] ?? null);
        $blood = self::level($player['blood'] ?? null);
        if (($dirt !== null && $dirt >= intval($cfg['dirty_level'])) || ($entry !== null && !empty($entry['dirty']))) $states[] = 'dirty';
        if (($blood !== null && $blood >= intval($cfg['bloody_level'])) || ($entry !== null && !empty($entry['bloody']))) $states[] = 'bloody';
        return $states;
    }
}
