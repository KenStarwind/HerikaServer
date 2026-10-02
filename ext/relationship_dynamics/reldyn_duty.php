<?php
/**
 * Relationship Dynamics — the duty affinity channel (roadmap duty-affinity-channel; pipeline Addendum 9,
 * Ken 2026-10-01 §24: "duty affinity", add when we can).
 *
 * "Lydia protects you because it's the job, not because of love." A housecarl, a follower or a sworn
 * protector carries a SECOND regard for the player beside affinity and passion: duty affinity, 0..100, earned
 * by serving, not by being liked. It is its own channel and stays one:
 *
 *   - it never feeds desire: not sex_disposal (getEffectiveDisposition), not passion, not the attraction
 *     matrix, not the consent decision, not romance promotion. Nothing in those paths reads it, and the
 *     tests hold them to that;
 *   - it speaks in its own <duty_context> block (contextBlock), apart from <subtext> and the emotional lines;
 *   - it decays slower than affinity and passion (decay.half_life_game_days, longer again while the NPC is
 *     still bound, longer again for a dutiful NPC): loyalty lingers after the service ends.
 *
 * WHO IS BOUND (roleFor): the strongest of the NPC's duty roles, each with a strength 0..1:
 *     housecarl  a member of a housecarl faction (core_npc_master.extended_data.factions)         1.0
 *     sworn      core's Player.type 'sworn' (the dark lane's sworn type reads this channel; this channel
 *                does not depend on it: defined independently, so 'sworn' can read value() / level())   1.0
 *     follower   in the player's party now, or in the follower faction                              0.8
 *   A hand-set role (the editor's Duty role field, _duty_role_override: housecarl / follower / sworn, or
 *   none) outranks the derivation. Duty is not romance and not a hard gate: every bound NPC earns it, and
 *   who the NPC is only sets how fast.
 *
 * WHAT EARNS IT (all diminishing: x (1 - value / 100), so the last stretch is slow):
 *     service  time in the player's service: per game hour since the NPC's previous turn, bounded by
 *              service.max_step_game_hours (a long gap earns one step, not a week)
 *     combat   fighting beside the player (reldyn_combat.php route): the enemy's threat class and shared
 *              danger scale it; a fight only witnessed earns combat.witness_share of it
 *     quest    a journal quest naming the NPC moved (onQuestEvent): a stage; the stage that finishes the
 *              quest (quest.complete_stage_min, 200 by default: Bethesda's usual finishing stage) earns more
 *     care     the player looked after the NPC (an eval item tagged help or rescue): a lord who looks after
 *              their own is served better
 *   and what wears it down: the player treating the NPC badly (an exchange that cost the NPC affinity beyond
 *   mistreat.min_loss) takes mistreat.per_affinity_point per core point, softened for a dutiful NPC but
 *   never to nothing.
 *
 * WHO THE NPC IS: the dutiful trait (D, 0..1) scales every gain and the decay: gain x (1 + dutiful_gain x
 * (2 D - 1)), within disposition.floor. An impulsive NPC still earns duty, slower and holds it less
 * firmly. Nobody is exempt and nobody is a wall.
 *
 * Units: duty value 0..100 (points); credit amounts in points before the multipliers; time on the game
 * calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY); core affinity -100..100.
 * State: $dynamics['_duty_affinity'] (KEY).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynDuty
{
    const KEY = '_duty_affinity';
    /** dynamics key: a hand-set role (editor): housecarl | follower | sworn | none. */
    const ROLE_OVERRIDE_KEY = '_duty_role_override';
    const ROLES = ['housecarl', 'sworn', 'follower'];
    const SOURCES = ['service', 'combat', 'quest', 'care', 'mistreat'];
    const KEEP_QUESTS = 24;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'duty' (nested tables merge per entry). Serene's starting values for Ken's §24 ruling. */
    public static function configDefaults(): array
    {
        return [
            // Off: no duty accumulates and no <duty_context> is written; the value already held is kept
            'enabled' => true,

            // --- who is bound ---
            'roles' => [
                'strength' => ['housecarl' => 1.0, 'sworn' => 1.0, 'follower' => 0.8],
                // core faction editor-id substrings (letters and digits, case ignored) => role; a member (rank >= 0) counts
                'factions' => [
                    ['match' => ['housecarl'], 'role' => 'housecarl'],
                    ['match' => ['currentfollower'], 'role' => 'follower'],
                ],
                // core Player.type => role
                'core_types' => ['sworn' => 'sworn'],
                // in the player's current party
                'in_party' => 'follower',
            ],

            // --- what earns it (points before who the NPC is and the diminishing) ---
            'service' => ['per_game_hour' => 0.25, 'max_step_game_hours' => 6.0],
            'combat' => [
                'base' => 2.0,
                'danger_mult' => 1.5,          // the NPC's live HP under the shared-danger threshold
                'witness_share' => 0.25,       // a fight only seen
                'threat_mult' => ['weak' => 0.4, 'regular' => 1.0, 'mighty' => 2.0],   // by the enemy's class (reldyn_combat.php threatClass), others 1
            ],
            'quest' => ['stage' => 1.0, 'complete' => 4.0, 'complete_stage_min' => 200],
            'care' => ['per_item' => 1.5, 'tags' => ['help', 'rescue'], 'min_significance' => 0.3],

            // --- what wears it down: the player treating the NPC badly ---
            // core affinity points an exchange cost the NPC beyond min_loss, x per_affinity_point; a dutiful NPC (D = 1) takes
            // (1 - dutiful_relief) of it, an impulsive one more, never below dutiful_floor of it
            'mistreat' => ['min_loss' => 10.0, 'per_affinity_point' => 0.15, 'dutiful_relief' => 0.5, 'dutiful_floor' => 0.4],

            // --- who the NPC is: the dutiful trait (D) ---
            'disposition' => ['dutiful_gain' => 0.6, 'floor' => 0.4, 'ceiling' => 1.6],

            // --- it decays slower than affinity and passion ---
            // half-life while nothing earns it, from decay.grace_game_days after the last credit; x (1 / bound_relief) while the NPC is
            // still bound; x (1 + dutiful_relief x (2 D - 1)) for who the NPC is (at least dutiful_floor)
            'decay' => ['half_life_game_days' => 180.0, 'grace_game_days' => 2.0, 'bound_relief' => 0.25, 'dutiful_relief' => 0.5, 'dutiful_floor' => 0.5],

            // --- the <duty_context> block ---
            // shown while bound at or above felt.from; after the service ends, while the duty held is at least felt.lingers_from
            'felt' => ['from' => 8.0, 'lingers_from' => 20.0, 'bands' => ['devoted' => 40.0, 'sworn' => 70.0],
                       'warm_from' => 30.0, 'cool_below' => 10.0],
            // {NAME} the NPC, {PLAYER} the player, the NPC's own pronoun vars. Duty in the NPC's words; no digits; no gendered pronoun.
            'text' => [
                'role' => [
                    'housecarl' => '{NAME} is sworn to the household of {PLAYER}.',
                    'follower' => "{NAME} travels in {PLAYER}'s service.",
                    'sworn' => '{NAME} is bound to {PLAYER} by oath.',
                    'former' => '{NAME} once served {PLAYER}, and the sense of duty has not faded.',
                ],
                'band' => [
                    'steady' => '{They} treat{S} the work as a job to be done properly: {THEY} show{S} up, keep{S} {THEIR} word and stand{S} where {THEY_ARE} needed.',
                    'devoted' => '{Their} sense of duty runs deep: {THEY} put{S} {THEIR} own comfort second, step{S} between {PLAYER} and danger without being asked, and take{S} pride in the post.',
                    'sworn' => '{They} would hold the line for {PLAYER} to the end; a broken promise would cost {THEM} more than a wound.',
                    'former' => '{They} still answer{S} when called, and {THEIR} word to {PLAYER} has not lapsed.',
                ],
                'regard' => [
                    'warm' => 'It is duty and fondness together now, and {THEY} no longer tell{S} them apart.',
                    'plain' => 'It is duty and respect, not a matter of feeling.',
                    'cool' => 'It is duty, not affection: {THEY} serve{S} faithfully without warmth, and the duty stands whatever {THEY} feel{S}.',
                    'hostile' => '{THEY} resent{S} {PLAYER} and keep{S} the oath anyway, because it is an oath.',
                ],
            ],
        ];
    }

    /** The section as RelDyn reads it: the stored row laid over the defaults key by key, one level down too. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('duty');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['roles', 'service', 'combat', 'quest', 'care', 'mistreat', 'disposition', 'decay', 'felt', 'text'] as $k) {
            $cfg[$k] = is_array($stored[$k] ?? null) ? array_replace($defaults[$k], $stored[$k]) : $defaults[$k];
        }
        foreach (['role', 'band', 'regard'] as $k) {
            $cfg['text'][$k] = is_array($stored['text'][$k] ?? null) ? array_replace($defaults['text'][$k], $stored['text'][$k]) : $defaults['text'][$k];
        }
        foreach (['strength'] as $k) {
            $cfg['roles'][$k] = is_array($stored['roles'][$k] ?? null) ? array_replace($defaults['roles'][$k], $stored['roles'][$k]) : $defaults['roles'][$k];
        }
        $cfg['combat']['threat_mult'] = is_array($stored['combat']['threat_mult'] ?? null)
            ? array_replace($defaults['combat']['threat_mult'], $stored['combat']['threat_mult']) : $defaults['combat']['threat_mult'];
        $cfg['felt']['bands'] = is_array($stored['felt']['bands'] ?? null) ? array_replace($defaults['felt']['bands'], $stored['felt']['bands']) : $defaults['felt']['bands'];
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    // =====================================================================
    // THE VALUE (the API the sworn type reads)
    // =====================================================================

    /** The duty affinity held, 0..100 (as stored; the decay runs in advance()). 0 for an NPC who never served. */
    public static function value(array $dynamics): float
    {
        $s = $dynamics[self::KEY] ?? null;
        return is_array($s) && is_numeric($s['value'] ?? null) ? self::clamp(floatval($s['value']), 0.0, 100.0) : 0.0;
    }

    /** The duty affinity as 0..1. */
    public static function level(array $dynamics): float
    {
        return self::value($dynamics) / 100.0;
    }

    /** The role the NPC served in last (even if no longer bound), or null. */
    public static function lastRole(array $dynamics): ?string
    {
        $r = $dynamics[self::KEY]['role'] ?? null;
        return is_string($r) && in_array($r, self::ROLES, true) ? $r : null;
    }

    /** Is the NPC bound as of the last advance (a role now, not a former one)? */
    public static function isBound(array $dynamics): bool
    {
        return !empty($dynamics[self::KEY]['bound']);
    }

    /**
     * Who the NPC is: the multiplier on gains and the decay's lean, from the dutiful trait (D, 0..1; an impulsive NPC low,
     * a dutiful one high). 1 at the middle (a vector that never read D is unremarkable), within disposition.floor..ceiling.
     */
    public static function disposition(array $dynamics, ?array $cfg = null): float
    {
        $d = ($cfg ?? self::config())['disposition'];
        $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $dutiful = is_array($vector) && is_numeric($vector['D'] ?? null) ? self::clamp(floatval($vector['D']), 0.0, 1.0) : 0.5;
        return self::clamp(1.0 + floatval($d['dutiful_gain']) * (2.0 * $dutiful - 1.0), floatval($d['floor']), floatval($d['ceiling']));
    }

    // =====================================================================
    // WHO IS BOUND
    // =====================================================================

    private static function matchKey($value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    /**
     * The NPC's duty role right now: ['role', 'strength' 0..1, 'source'] or null (not bound). The editor's hand-set role
     * first; else the strongest of: a housecarl faction, core's 'sworn' type, the player's party or the follower faction.
     */
    public static function roleFor(string $npcName, array $dynamics, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $strength = (array) $cfg['roles']['strength'];
        $override = $dynamics[self::ROLE_OVERRIDE_KEY] ?? null;
        if (is_string($override) && $override !== '') {
            $override = strtolower(trim($override));
            if ($override === 'none') return null;
            if (in_array($override, self::ROLES, true)) {
                return ['role' => $override, 'strength' => self::clamp(floatval($strength[$override] ?? 1.0), 0.0, 1.0), 'source' => 'editor'];
            }
        }
        $facts = RelationshipDynamics::powerGapFacts($npcName, $dynamics);
        $found = [];
        $take = function (string $role, string $why) use (&$found, $strength): void {
            if (!in_array($role, self::ROLES, true)) return;
            $s = self::clamp(floatval($strength[$role] ?? 0.0), 0.0, 1.0);
            if ($s > 0 && (!isset($found['strength']) || $s > $found['strength'])) $found = ['role' => $role, 'strength' => $s, 'source' => $why];
        };
        if (!empty($facts['in_party'])) $take((string) $cfg['roles']['in_party'], 'party');
        $type = strtolower(trim((string) ($facts['core_type'] ?? '')));
        $byType = array_change_key_case((array) $cfg['roles']['core_types'], CASE_LOWER);
        if ($type !== '' && isset($byType[$type])) $take((string) $byType[$type], "type:{$type}");
        foreach ((array) ($facts['factions'] ?? []) as $faction) {
            if (!is_array($faction) || intval($faction['rank'] ?? 0) < 0) continue;
            $key = self::matchKey($faction['name'] ?? '');
            foreach ((array) $cfg['roles']['factions'] as $rule) {
                foreach ((array) ($rule['match'] ?? []) as $needle) {
                    $n = self::matchKey($needle);
                    if ($key !== '' && $n !== '' && str_contains($key, $n)) {
                        $take((string) ($rule['role'] ?? ''), 'faction:' . ($faction['name'] ?? ''));
                        continue 3;
                    }
                }
            }
        }
        return $found === [] ? null : $found;
    }

    // =====================================================================
    // THE STATE
    // =====================================================================

    private static function state(array $dynamics): array
    {
        $s = $dynamics[self::KEY] ?? null;
        $s = is_array($s) ? $s : [];
        return $s + ['value' => 0.0, 'gamets' => null, 'last_credit' => null, 'role' => null, 'bound' => false, 'since' => null,
            'sources' => array_fill_keys(self::SOURCES, 0.0), 'quests' => [], 'completed' => []];
    }

    /** Add $points (signed, before who the NPC is) from $source; returns the points actually moved. Gains diminish toward 100. */
    private static function credit(array &$dynamics, string $source, float $points, float $now, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $s = self::state($dynamics);
        $before = self::clamp(floatval($s['value']), 0.0, 100.0);
        if ($points >= 0) {
            $moved = $points * self::disposition($dynamics, $cfg) * (1.0 - $before / 100.0);
        } else {
            $d = (array) $cfg['mistreat'];
            $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
            $dutiful = is_array($vector) && is_numeric($vector['D'] ?? null) ? self::clamp(floatval($vector['D']), 0.0, 1.0) : 0.5;
            $mult = max(floatval($d['dutiful_floor']), 1.0 - floatval($d['dutiful_relief']) * (2.0 * $dutiful - 1.0));
            $moved = $points * $mult;
        }
        $after = self::clamp($before + $moved, 0.0, 100.0);
        $moved = $after - $before;
        if (abs($moved) < 1e-9) return 0.0;
        $s['value'] = round($after, 4);
        $s['sources'][$source] = round(floatval($s['sources'][$source] ?? 0.0) + $moved, 4);
        if ($moved > 0 && $now > 0) $s['last_credit'] = $now;
        $dynamics[self::KEY] = $s;
        return $moved;
    }

    /**
     * Prerequest: the decay since the NPC's previous turn, then the service of this one. Returns what moved
     * ['role' => ?array, 'service' => points, 'decay' => points, 'value' => 0..100].
     */
    public static function advance(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['role' => null, 'service' => 0.0, 'decay' => 0.0, 'value' => self::value($dynamics)];
        $cfg = self::config();
        if (!self::enabled() || $now <= 0) return $out;
        $s = self::state($dynamics);
        $role = self::roleFor($npcName, $dynamics, $cfg);
        $out['role'] = $role;
        $prev = is_numeric($s['gamets'] ?? null) ? floatval($s['gamets']) : null;

        // The decay: from grace after the last credit, over the time since the previous advance; slower while bound, for a dutiful NPC
        if ($prev !== null && $s['value'] > 0 && $now > $prev) {
            $d = (array) $cfg['decay'];
            $lastCredit = is_numeric($s['last_credit'] ?? null) ? floatval($s['last_credit']) : $prev;
            $from = max($prev, $lastCredit + floatval($d['grace_game_days']) * RelationshipDynamics::GAMETS_PER_DAY);
            $days = max(0.0, $now - $from) / RelationshipDynamics::GAMETS_PER_DAY;
            if ($days > 0) {
                $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
                $dutiful = is_array($vector) && is_numeric($vector['D'] ?? null) ? self::clamp(floatval($vector['D']), 0.0, 1.0) : 0.5;
                $half = max(1.0, floatval($d['half_life_game_days']))
                    * max(floatval($d['dutiful_floor']), 1.0 + floatval($d['dutiful_relief']) * (2.0 * $dutiful - 1.0))
                    / ($role !== null ? max(0.05, floatval($d['bound_relief'])) : 1.0);
                $after = floatval($s['value']) * pow(0.5, $days / $half);
                $out['decay'] = round($after - floatval($s['value']), 4);
                $s['value'] = round($after, 4);
            }
        }
        // Who the NPC serves now; the last role is remembered when the service ends
        if ($role !== null) {
            if (empty($s['bound']) || ($s['role'] ?? null) !== $role['role']) $s['since'] = $s['since'] ?? $now;
            $s['role'] = $role['role'];
            $s['bound'] = true;
        } else {
            $s['bound'] = false;
        }
        $dynamics[self::KEY] = $s;

        // The service of this turn: the time since the previous one (bounded), at the role's strength
        if ($role !== null && $prev !== null && $now > $prev) {
            $hours = min(floatval($cfg['service']['max_step_game_hours']), ($now - $prev) / (RelationshipDynamics::GAMETS_PER_DAY / 24.0));
            $out['service'] = round(self::credit($dynamics, 'service', floatval($cfg['service']['per_game_hour']) * $hours * $role['strength'], $now, $cfg), 4);
        } elseif ($role !== null && $prev === null) {
            // the first turn in service starts the clock and the grace
            $dynamics[self::KEY]['last_credit'] = $now;
        }
        $dynamics[self::KEY]['gamets'] = $now;
        $out['value'] = self::value($dynamics);
        return $out;
    }

    /**
     * A fight: the NPC fought beside the player ($fought) or only saw it. Only a bound NPC earns. $threat is
     * reldyn_combat.php's class of the enemy ('weak', 'regular', 'mighty'), $danger the shared-danger flag (or a callable giving it, read
     * only when the NPC is bound and fought). Returns points moved.
     */
    public static function onFight(string $npcName, array &$dynamics, bool $fought, ?string $threat, $danger, float $at): float
    {
        if (!self::enabled()) return 0.0;
        $cfg = self::config();
        $role = self::roleFor($npcName, $dynamics, $cfg);
        if ($role === null) return 0.0;
        $c = (array) $cfg['combat'];
        $points = floatval($c['base']) * $role['strength']
            * floatval(((array) $c['threat_mult'])[(string) $threat] ?? 1.0)
            * (($fought && (is_callable($danger) ? (bool) $danger() : (bool) $danger)) ? floatval($c['danger_mult']) : 1.0)
            * ($fought ? 1.0 : floatval($c['witness_share']));
        $moved = self::credit($dynamics, 'combat', $points, $at > 0 ? $at : RelationshipDynamics::currentGamets(), $cfg);
        if ($moved > 0) {
            $s = self::state($dynamics);
            $s['role'] = $role['role'];
            $s['bound'] = true;
            $dynamics[self::KEY] = $s;
            RelationshipDynamics::log(sprintf('[DUTY] %s %s a fight beside the player: +%.2f duty (%s)', $npcName, $fought ? 'fought' : 'saw', $moved, $role['role']));
        }
        return $moved;
    }

    /**
     * A journal quest that names the NPC moved to $stage. Each quest stage counts once; the stage that finishes the quest
     * (quest.complete_stage_min and up) earns the completion once per quest. Only a bound NPC earns. Returns points moved.
     */
    public static function onQuest(string $npcName, array &$dynamics, string $questId, int $stage, float $at): float
    {
        if (!self::enabled() || trim($questId) === '') return 0.0;
        $cfg = self::config();
        $role = self::roleFor($npcName, $dynamics, $cfg);
        if ($role === null) return 0.0;
        $q = (array) $cfg['quest'];
        $s = self::state($dynamics);
        $key = strtolower(trim($questId)) . ':' . $stage;
        $quests = array_values((array) $s['quests']);
        $completed = array_values((array) $s['completed']);
        $points = 0.0;
        if (!in_array($key, $quests, true)) {
            $quests[] = $key;
            $points += floatval($q['stage']);
        }
        $id = strtolower(trim($questId));
        if ($stage >= intval($q['complete_stage_min']) && !in_array($id, $completed, true)) {
            $completed[] = $id;
            $points += floatval($q['complete']);
        }
        if ($points <= 0) return 0.0;
        $dynamics[self::KEY] = ['quests' => array_slice($quests, -self::KEEP_QUESTS), 'completed' => array_slice($completed, -self::KEEP_QUESTS)] + $s;
        $moved = self::credit($dynamics, 'quest', $points * $role['strength'], $at > 0 ? $at : RelationshipDynamics::currentGamets(), $cfg);
        $dynamics[self::KEY]['role'] = $role['role'];
        $dynamics[self::KEY]['bound'] = true;
        RelationshipDynamics::log(sprintf('[DUTY] %s: quest %s stage %d, +%.2f duty (%s)', $npcName, $questId, $stage, $moved, $role['role']));
        return $moved;
    }

    /**
     * An applied eval item of the NPC: the player looked after them (a tag in care.tags at care.min_significance), or treated
     * them badly (the exchange cost them affinity beyond mistreat.min_loss). $totals is processEvalContractItem's actual changes
     * (affinity in mirror units, x2 = core points). Only a bound NPC earns or loses. Returns points moved.
     */
    public static function onEvalItem(string $npcName, array $n, array $totals, array &$dynamics, float $at): float
    {
        if (!self::enabled()) return 0.0;
        $cfg = self::config();
        $tags = array_map('strtolower', array_map('strval', (array) ($n['tags'] ?? [])));
        $care = (array) $cfg['care'];
        $cares = array_intersect($tags, array_map('strtolower', (array) $care['tags'])) !== []
            && floatval($n['significance'] ?? 0.0) >= floatval($care['min_significance']);
        $loss = -floatval($totals['affinity'] ?? 0.0) * 2.0;   // core points the exchange cost, positive
        $mistreat = (array) $cfg['mistreat'];
        $mistreated = $loss > floatval($mistreat['min_loss']);
        if (!$cares && !$mistreated) return 0.0;
        $role = self::roleFor($npcName, $dynamics, $cfg);
        if ($role === null) return 0.0;
        $moved = 0.0;
        $now = $at > 0 ? $at : RelationshipDynamics::currentGamets();
        if ($cares) {
            $moved += self::credit($dynamics, 'care', floatval($care['per_item']) * $role['strength'], $now, $cfg);
        }
        if ($mistreated) {
            $moved += self::credit($dynamics, 'mistreat', -($loss - floatval($mistreat['min_loss'])) * floatval($mistreat['per_affinity_point']), $now, $cfg);
        }
        if (abs($moved) > 0) {
            $dynamics[self::KEY]['role'] = $role['role'];
            $dynamics[self::KEY]['bound'] = true;
            RelationshipDynamics::log(sprintf('[DUTY] %s: the exchange moved duty %+.2f (%s)', $npcName, $moved, $role['role']));
        }
        return $moved;
    }

    // =====================================================================
    // <duty_context>
    // =====================================================================

    /** The band of a duty value: steady | devoted | sworn, or null below felt.from. */
    public static function band(float $value, ?array $cfg = null): ?string
    {
        $f = ($cfg ?? self::config())['felt'];
        if ($value < floatval($f['from'])) return null;
        $b = (array) $f['bands'];
        return $value >= floatval($b['sworn']) ? 'sworn' : ($value >= floatval($b['devoted']) ? 'devoted' : 'steady');
    }

    /** How the NPC feels about the player apart from duty: warm | plain | cool | hostile, from core affinity. */
    public static function regard(array $dynamics, ?array $cfg = null): string
    {
        $f = ($cfg ?? self::config())['felt'];
        $aff = RelationshipDynamics::getCoreAffinity($dynamics);
        if ($aff < 0) return 'hostile';
        if ($aff >= floatval($f['warm_from'])) return 'warm';
        return $aff < floatval($f['cool_below']) ? 'cool' : 'plain';
    }

    /**
     * The duty in the NPC's words (no digits, no gendered pronoun), or null: not bound and nothing lingering, or below the
     * felt thresholds. Pronoun vars are NOT yet resolved (RelDynPronouns::fill does that for the NPC).
     */
    public static function feltText(string $npcName, string $playerRef, array $dynamics, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        if (!self::enabled()) return null;
        $value = self::value($dynamics);
        $bound = self::isBound($dynamics);
        $f = (array) $cfg['felt'];
        $role = self::lastRole($dynamics);
        if ($role === null) return null;
        if ($bound ? $value < floatval($f['from']) : $value < floatval($f['lingers_from'])) return null;
        $t = (array) $cfg['text'];
        $band = $bound ? self::band($value, $cfg) : 'former';
        if ($band === null) return null;
        $roleText = (string) (($t['role'] ?? [])[$bound ? $role : 'former'] ?? '');
        $bandText = (string) (($t['band'] ?? [])[$band] ?? '');
        $regardText = $bound ? (string) (($t['regard'] ?? [])[self::regard($dynamics, $cfg)] ?? '') : '';
        $text = trim(implode(' ', array_filter([$roleText, $bandText, $regardText], fn($x) => trim($x) !== '')));
        if ($text === '') return null;
        return strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerRef]);
    }

    /** The <duty_context> block for the NPC (pronouns resolved), or null. Its own block, apart from <subtext>. */
    public static function contextBlock(string $npcName, string $playerRef, array $dynamics): ?string
    {
        $text = self::feltText($npcName, $playerRef, $dynamics);
        if ($text === null) return null;
        return "<duty_context>\n" . RelDynPronouns::fill($text, $npcName) . "\n</duty_context>";
    }

    // =====================================================================
    // JEV
    // =====================================================================

    /** The state for Jev and the editor: value, band, role, whether bound, the sources, regard. */
    public static function jev(array $dynamics): array
    {
        $cfg = self::config();
        $s = self::state($dynamics);
        $value = self::value($dynamics);
        return [
            'enabled' => self::enabled(),
            'value' => round($value, 2),
            'band' => self::band($value, $cfg),
            'role' => self::lastRole($dynamics),
            'bound' => self::isBound($dynamics),
            'override' => is_string($dynamics[self::ROLE_OVERRIDE_KEY] ?? null) ? (string) $dynamics[self::ROLE_OVERRIDE_KEY] : null,
            'sources' => array_map(fn($v) => round(floatval($v), 2), array_intersect_key((array) $s['sources'], array_flip(self::SOURCES))),
            'regard' => self::regard($dynamics, $cfg),
            'disposition' => round(self::disposition($dynamics, $cfg), 3),
        ];
    }
}
