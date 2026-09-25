<?php
/**
 * Relationship Dynamics — self-reflection that rides on core's diary (roadmap diary-trigger,
 * diary-reflection-eval; decisions 2026-09-23 §7; memory feedback_diary_system; dimension design
 * draft "Dimensional Self-Reflection" and Dimension 11).
 *
 * CHIM 3.4.1 writes the diary (lib/dynamic_update_util.php generateFollowerDiary): on the
 * player's 'diary' request, and as AUTO_DIARY on sleep ('goodnight') and wait ('waitstart') for
 * the NPCs nearby with auto diary on (profile metadata AUTO_DIARY_ENABLED / AUTO_DIARY_WAIT_ENABLED,
 * per-NPC override extended_data.auto_diary_enabled), rate-limited by core's DIARY_COOLDOWN. One
 * entry is one diarylog row whose people column is the NPC. RelDyn never writes a second diary:
 *
 *   1. Moments. checkDiaryTrigger's gates (config, play-time cooldown, no active consumable,
 *      maturity, interaction gap, something worth writing about) mark what happened since the
 *      last mark: significance, crisis, an open conflict, a boundary, a change of bond, grief,
 *      attachment, new emotions, the attraction tier (detectDiaryContentTriggers).
 *      markDiaryCompleted keeps them (keepMoments, _diary_moments, at most max_moments) until the
 *      NPC's next diary entry is read; baseline drift runs there (roadmap baseline-drift).
 *   2. Reading core's diary (prerequest, onPrerequest): the NPC's diarylog rows after the last one
 *      read (_diary_core_rowid; core's own match, lower(trim(people))). While a consumable is
 *      active they wait for the sober self. Every read ends with a snapshot of the trajectory
 *      dimensions: the state at this diary entry. With moments waiting and the snapshot of the
 *      previous entry, the NPC reflects, at the depth her own maturity allows (x without the
 *      held temporary offsets: a full moon or a drink is not who she is):
 *        at or below shallow_at  shallow: nothing scorable, the moments are spent;
 *        'baseline' mode         the verdict from the snapshots, pure math (baselineVerdict);
 *        'trajectory' mode       one job for the eval worker: one LLM call reads her last
 *                                diary entries (entries) and returns {trajectory, strength},
 *                                validated (parse); applied on her next prerequest. A job that
 *                                dies falls back to the baseline verdict taken when it was queued.
 *   3. The verdict (applyReflection): verdict_deltas through applyDelta (growing maturity +1,
 *      stagnating resentment_self +2, spiraling maturity -1, x the strength's scale); a reflection
 *      whose maturity scored positive takes growth_relief off resentment_self as is ("sustained
 *      positive self-eval from diary"); self-confidence, never eval-scored, follows its own
 *      evidence (RelationshipDynamics::deriveConfidenceInput) since the previous entry.
 *
 * In front of the LLM: feelings, never numbers (the moments as she would feel them, her state in
 * band words, her depth in words) and her own diary text.
 * Units: gamets = raw game-calendar gamets; rowids = diarylog rowids; dimension points 0..100,
 * affinity in core points (-100..100), as its drift samples (driftSampleValue).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynDiary
{
    /** Trajectory-mode jobs: one row per queued reflection, done or dead rows until applied. */
    const TABLE = 'reldyn_diary_reflections';
    /** (RelDynEval::LOCK_CLASS, LOCK_DIARY): the single drainer of the diary reflection jobs. */
    const LOCK_DIARY = 3;
    const VERDICTS = ['growing', 'stagnating', 'spiraling'];

    const SYSTEM_PROMPT = "You read the private diary of a character in a role-playing game and judge the direction of the writer's inner life across these entries.\n"
        . "- growing: the writer sees themselves more clearly, owns their part, takes steps, makes peace.\n"
        . "- stagnating: the writer circles the same hurt or habit and nothing changes.\n"
        . "- spiraling: the writer is getting worse: more bitter, more lost, more self-destructive.\n"
        . "Judge the writer, not the events or the other people. The strength is strong only when the direction is unmistakable across the entries, otherwise light.\n"
        . "Reply with one JSON object and nothing else: {\"trajectory\": \"growing\" | \"stagnating\" | \"spiraling\", \"strength\": \"light\" | \"strong\"}";

    /** The strength words the call answers with => strength (1 light, 2 unmistakable). */
    const STRENGTH_WORDS = ['light' => 1, 'strong' => 2];

    /** Test hooks: the LLM (callable(array $messages, array $params): string|array|null) and the worker launcher. */
    public static $llm = null;
    public static $launcher = null;

    // =====================================================================
    // CONFIG
    // =====================================================================

    public static function configDefaults(): array
    {
        return [
            // Depth by her own maturity (dimension draft: "~20. Below that, the diary is shallow ...
            // Above 20 ... pattern recognition. Above 40 ... genuine self-examination"). Maturity points.
            'shallow_at' => 20.0,
            'examination_above' => 40.0,
            // Baseline mode: the dimensions compared with the snapshot of the previous diary entry and
            // the direction that is growth (1: higher is better, -1: lower is better). Not maturity,
            // resentment_self or self_confidence: those are what the reflection moves.
            'trajectory_dimensions' => ['affinity' => 1, 'trust' => 1, 'comfort' => 1, 'respect' => 1, 'warmth' => 1, 'resentment' => -1],
            // A dimension moved when it changed by more than this (dimension points; affinity in core points).
            'moved_above' => 3.0,
            // What each verdict does (memory feedback_diary_system): dimension => points through applyDelta.
            'verdict_deltas' => [
                'growing'    => ['maturity' => 1.0],
                'stagnating' => ['resentment_self' => 2.0],
                'spiraling'  => ['maturity' => -1.0],
            ],
            // Strength (1 light, 2 unmistakable) => multiplier of the verdict's points. Baseline mode is 1.
            'strength_scale' => ['1' => 1.0, '2' => 2.0],
            // The strongest verdict her depth can reach (pattern: "noticing patterns but cannot say why").
            'max_strength' => ['pattern' => 1, 'examination' => 2],
            // resentment_self points taken off, as is, per reflection whose maturity scored positive
            // (dimension draft: cleared by "sustained positive self-eval from diary (+1 per entry)").
            'growth_relief' => 1.0,
            // Self-confidence (Dimension 11, not eval-scored): the reflection moves it this many points
            // (x the strength's scale) the way its evidence (deriveConfidenceInput) went since the
            // previous entry, when that evidence moved more than the deadband (confidence input points).
            'self_confidence_step' => 1.0,
            'self_confidence_deadband' => 1.0,
            'max_moments' => 10,       // meaningful moments kept waiting for a diary entry
            'snapshots_kept' => 5,     // one per diary entry read
            // Trajectory mode: one LLM call per reflection, in the eval worker.
            'entries' => 5,            // her last diary entries the LLM reads (the design: 3-5)
            'entry_max_chars' => 1500, // characters kept of each entry (the start; core's own clip is 1800)
            'connector_id' => 0,       // core_llm_connector id; 0 = the eval connector, else RELLLM_CONNECTOR
            'max_tokens' => 120,
            'temperature' => 0.2,
            'max_attempts' => 3,       // failed calls before the job is dead (then the baseline verdict)
            'jobs_per_run' => 5,
            'autostart_worker' => true,
            // Her depth in words. {NAME} = the NPC.
            'depth_text' => [
                'pattern'     => '{NAME} is only beginning to notice their own patterns and cannot yet say why they happen.',
                'examination' => '{NAME} is capable of honest self-examination.',
            ],
            // The moments (trigger kinds) as the writer would feel them. {PLAYER} = the player.
            'moment_text' => [
                'sustained_delta'     => 'the way things have been with {PLAYER} for days now, not a passing mood',
                'crisis_indicators'   => 'feeling cornered and sore, several things going wrong at once',
                'di_count_changed'    => 'a moment when everything nearly fell apart',
                'grief_phase_changed' => 'grief changing shape',
                'attachment_shifted'  => 'a change in how safe closeness feels',
                'new_emotions'        => 'feelings that were not there before',
                'intimacy_critical'   => 'a closeness that has all but gone',
                'tier_changed'        => 'a change in how drawn to {PLAYER} they are',
                'defining_moment'     => 'something with {PLAYER} that mattered a great deal',
                'conflict_opened'     => 'a quarrel with {PLAYER} that is not settled',
                'boundary'            => 'having to draw a line with {PLAYER}',
                'bond_changed'        => 'what they are to {PLAYER} has changed',
            ],
        ];
    }

    /** Config 'diary_reflection' over its defaults; the text tables and caps merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('diary_reflection');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['strength_scale', 'max_strength', 'depth_text', 'moment_text'] as $section) {
            $cfg[$section] = array_replace($defaults[$section], is_array($stored[$section] ?? null) ? $stored[$section] : []);
        }
        return $cfg;
    }

    /** 'baseline' (math, the default) or 'trajectory' (one LLM call): config diary_reflection_mode. */
    public static function mode(): string
    {
        return RelationshipDynamics::configValue('diary_reflection_mode') === 'trajectory' ? 'trajectory' : 'baseline';
    }

    /** Reflection runs with the autonomous diary on (its moments) and the dimension engine (its deltas). */
    public static function enabled(): bool
    {
        return !empty(RelationshipDynamics::configValue('autonomous_diary_enabled'))
            && !empty(RelationshipDynamics::configValue('dimension_engine_enabled'));
    }

    // =====================================================================
    // PURE: depth, sobriety, moments, snapshots, the baseline verdict
    // =====================================================================

    /** Her own maturity: x without the held temporary offsets (moon, injury, place), 50 when unset. */
    public static function ownMaturity(array $dynamics): float
    {
        return RelationshipDynamics::driftSampleValue($dynamics, 'maturity') ?? 50.0;
    }

    /** 'shallow' | 'pattern' | 'examination' for a maturity (points). */
    public static function depth(float $maturity, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        if ($maturity <= floatval($cfg['shallow_at'])) return 'shallow';
        return $maturity > floatval($cfg['examination_above']) ? 'examination' : 'pattern';
    }

    /** A consumable's immediate effects are still on her (RelationshipDynamics::consumeItem, _active_consumables). */
    public static function intoxicated(array $dynamics): bool
    {
        return !empty($dynamics['_active_consumables']);
    }

    /** Keep marked triggers as one moment at $gamets until a diary entry is read (at most max_moments). */
    public static function keepMoments(array &$dynamics, array $triggers, float $gamets, ?array $cfg = null): void
    {
        $triggers = array_values(array_filter(array_map('strval', $triggers), fn(string $t) => $t !== ''));
        if ($triggers === []) return;
        $cfg = $cfg ?? self::config();
        $list = is_array($dynamics['_diary_moments'] ?? null) ? array_values($dynamics['_diary_moments']) : [];
        $list[] = ['gamets' => $gamets, 'triggers' => $triggers];
        $dynamics['_diary_moments'] = array_slice($list, -max(1, intval($cfg['max_moments'])));
    }

    /** The trigger kinds of $moments (the code before ':'), first seen first, each once. */
    public static function momentKinds(array $moments): array
    {
        $kinds = [];
        foreach ($moments as $m) {
            foreach ((array) (is_array($m) ? ($m['triggers'] ?? []) : []) as $t) {
                $kind = explode(':', (string) $t, 2)[0];
                if ($kind !== '' && !in_array($kind, $kinds, true)) $kinds[] = $kind;
            }
        }
        return $kinds;
    }

    /** The moments as the writer would feel them (moment_text; kinds without a text are left out). */
    public static function momentPhrases(array $moments, string $player, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $out = [];
        foreach (self::momentKinds($moments) as $kind) {
            $text = $cfg['moment_text'][$kind] ?? null;
            if (is_string($text) && $text !== '') $out[] = strtr($text, ['{PLAYER}' => $player]);
        }
        return $out;
    }

    /** The trajectory dimensions now (drift sample units) and the self-confidence evidence. */
    public static function snapshot(array $dynamics, float $gamets, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $values = [];
        foreach (array_keys((array) $cfg['trajectory_dimensions']) as $dim) {
            $v = RelationshipDynamics::driftSampleValue($dynamics, (string) $dim);
            if ($v !== null) $values[(string) $dim] = round($v, 4);
        }
        return ['gamets' => $gamets, 'values' => $values,
                'confidence_input' => floatval(RelationshipDynamics::deriveConfidenceInput($dynamics))];
    }

    public static function storeSnapshot(array &$dynamics, float $gamets, ?array $cfg = null): void
    {
        $cfg = $cfg ?? self::config();
        $list = self::snapshots($dynamics);
        $list[] = self::snapshot($dynamics, $gamets, $cfg);
        $dynamics['_diary_snapshots'] = array_slice($list, -max(1, intval($cfg['snapshots_kept'])));
    }

    /** Snapshots taken at diary entries (the April ones, stamped with the wall clock, are not read). */
    public static function snapshots(array $dynamics): array
    {
        return array_values(array_filter((array) ($dynamics['_diary_snapshots'] ?? []),
            fn($s) => is_array($s) && array_key_exists('gamets', $s) && is_array($s['values'] ?? null)));
    }

    public static function lastSnapshot(array $dynamics): ?array
    {
        $list = self::snapshots($dynamics);
        return $list === [] ? null : end($list);
    }

    /**
     * Each trajectory dimension against $snapshot, in its growth direction: growth / regression
     * (moved more than moved_above, signed points) or stagnation.
     *
     * @return array{growth: array<string,float>, stagnation: list<string>, regression: array<string,float>}
     */
    public static function compare(array $dynamics, array $snapshot, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $moved = floatval($cfg['moved_above']);
        $out = ['growth' => [], 'stagnation' => [], 'regression' => []];
        foreach ((array) $cfg['trajectory_dimensions'] as $dim => $direction) {
            $dim = (string) $dim;
            $then = $snapshot['values'][$dim] ?? null;
            $now = RelationshipDynamics::driftSampleValue($dynamics, $dim);
            if (!is_numeric($then) || $now === null) continue;
            $d = round(($now - floatval($then)) * (floatval($direction) < 0 ? -1.0 : 1.0), 4);
            if ($d > $moved) $out['growth'][$dim] = $d;
            elseif ($d < -$moved) $out['regression'][$dim] = $d;
            else $out['stagnation'][] = $dim;
        }
        return $out;
    }

    /** More grew than fell: growing; more fell: spiraling; otherwise (nothing moved, or both alike): stagnating. */
    public static function baselineVerdict(array $comparison): string
    {
        $g = count($comparison['growth'] ?? []);
        $r = count($comparison['regression'] ?? []);
        if ($g > $r) return 'growing';
        if ($r > $g) return 'spiraling';
        return 'stagnating';
    }

    /**
     * Apply a verdict: its deltas through applyDelta (x the strength's scale), the growth relief on
     * resentment_self, self-confidence by its evidence since $confidenceBefore (null: no earlier
     * entry to compare with). Returns dimension => points actually applied.
     */
    public static function applyReflection(string $npcName, array &$dynamics, string $verdict, int $strength, ?float $confidenceBefore, string $source, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (!in_array($verdict, self::VERDICTS, true)) {
            throw new InvalidArgumentException("RelDynDiary: unknown verdict '{$verdict}'");
        }
        $scale = floatval($cfg['strength_scale'][(string) $strength] ?? 1.0);
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
        $applied = [];
        foreach ((array) ($cfg['verdict_deltas'][$verdict] ?? []) as $dim => $points) {
            $dim = (string) $dim;
            $applied[$dim] = ($applied[$dim] ?? 0.0)
                + RelationshipDynamics::applyDelta($dim, $dynamics, floatval($points) * $scale, $temperament);
        }
        if (($applied['maturity'] ?? 0.0) > 0.0 && floatval($cfg['growth_relief']) > 0.0) {
            $relief = RelDynResentment::relieveSelf($dynamics, floatval($cfg['growth_relief']));
            if ($relief > 0.0) $applied['resentment_self'] = ($applied['resentment_self'] ?? 0.0) - $relief;
        }
        if ($confidenceBefore !== null && floatval($cfg['self_confidence_step']) > 0.0) {
            $evidence = floatval(RelationshipDynamics::deriveConfidenceInput($dynamics)) - $confidenceBefore;
            if (abs($evidence) > floatval($cfg['self_confidence_deadband'])) {
                $applied['self_confidence'] = RelationshipDynamics::applyDelta('self_confidence', $dynamics,
                    ($evidence > 0 ? 1.0 : -1.0) * floatval($cfg['self_confidence_step']) * $scale, $temperament);
            }
        }
        $gamets = RelationshipDynamics::currentGamets();
        $dynamics['_last_diary_reflection'] = $gamets;   // game calendar, a record (never a duration)
        $dynamics['_diary_last_verdict'] = ['verdict' => $verdict, 'strength' => $strength, 'source' => $source, 'gamets' => $gamets];
        RelationshipDynamics::log("[RelDyn-DIARY] {$npcName} reflects ({$source}): {$verdict} x{$strength} -> " . json_encode(array_map(fn($v) => round($v, 3), $applied)));
        return $applied;
    }

    // =====================================================================
    // PREREQUEST: read core's diary, reflect or queue the reflection
    // =====================================================================

    /**
     * The NPC's prerequest: apply finished trajectory jobs, then read her new diary entries
     * (unless a consumable is still on her) and reflect on the moments waiting.
     * Returns ['entries' => rows read, 'applied_jobs' => n, 'depth' => ?string, 'verdict' => ?string,
     * 'applied' => dimension => points, 'queued' => ?job id].
     */
    public static function onPrerequest(string $npcName, array &$dynamics): array
    {
        $out = ['entries' => 0, 'applied_jobs' => 0, 'depth' => null, 'verdict' => null, 'applied' => [], 'queued' => null];
        if (!self::enabled()) return $out;
        $cfg = self::config();
        $out['applied_jobs'] = self::applyFinishedJobs($npcName, $dynamics, $cfg);
        if (self::intoxicated($dynamics)) {
            return $out;   // her entries wait for the sober self (the drunken-night design)
        }
        $rows = self::newEntries($npcName, intval($dynamics['_diary_core_rowid'] ?? 0));
        if ($rows === []) return $out;
        $newest = intval(end($rows)['rowid']);
        $out['entries'] = count($rows);

        $previous = self::lastSnapshot($dynamics);
        $moments = is_array($dynamics['_diary_moments'] ?? null) ? array_values($dynamics['_diary_moments']) : [];
        if ($moments !== [] && $previous !== null) {
            $out['depth'] = self::depth(self::ownMaturity($dynamics), $cfg);
            $verdict = self::baselineVerdict(self::compare($dynamics, $previous, $cfg));
            $confidenceBefore = is_numeric($previous['confidence_input'] ?? null) ? floatval($previous['confidence_input']) : null;
            if ($out['depth'] === 'shallow') {
                RelationshipDynamics::log("[RelDyn-DIARY] {$npcName}: a shallow diary (maturity " . round(self::ownMaturity($dynamics), 1) . "), nothing to take stock of");
                $dynamics['_diary_moments'] = [];
            } elseif (self::mode() === 'trajectory') {
                if (self::jobWaiting($npcName)) {
                    RelationshipDynamics::log("[RelDyn-DIARY] {$npcName}: a reflection is still with the worker; the moments wait for the next entry");
                } else {
                    $out['queued'] = self::enqueue($npcName, [
                        'npc' => $npcName,
                        'player' => trim((string) ($GLOBALS['PLAYER_NAME'] ?? 'Player')),
                        'newest_rowid' => $newest,
                        'moments' => $moments,
                        'depth' => $out['depth'],
                        'fallback_verdict' => $verdict,
                        'confidence_before' => $confidenceBefore,
                        'gamets' => RelationshipDynamics::currentGamets(),
                    ]);
                    $dynamics['_diary_moments'] = [];
                    self::launchWorker();
                }
            } else {
                $out['verdict'] = $verdict;
                $out['applied'] = self::applyReflection($npcName, $dynamics, $verdict, 1, $confidenceBefore, 'baseline', $cfg);
                $dynamics['_diary_moments'] = [];
            }
        }
        self::storeSnapshot($dynamics, RelationshipDynamics::currentGamets(), $cfg);
        $dynamics['_diary_core_rowid'] = $newest;
        return $out;
    }

    // =====================================================================
    // CORE'S DIARY (diarylog, read only)
    // =====================================================================

    private static function db()
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) throw new RuntimeException('RelDynDiary: no database connection');
        return $db;
    }

    private static function present(string $table): bool
    {
        $row = self::db()->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [$table]);
        return in_array($row['present'] ?? null, ['t', true], true);
    }

    /** Rows of a json_agg query ('rows' column), or an exception when the query failed. */
    private static function jsonRows(string $sql, array $params, string $what): array
    {
        $row = self::db()->fetchOne($sql, $params);
        if (!is_string($row['rows'] ?? null)) {
            throw new RuntimeException("RelDynDiary: {$what} failed");
        }
        $rows = json_decode($row['rows'], true);
        return is_array($rows) ? $rows : [];
    }

    /** Her diarylog rows after $afterRowid, oldest first: [rowid, gamets, content]. [] without a diarylog. */
    public static function newEntries(string $npcName, int $afterRowid): array
    {
        if (!self::present('diarylog')) return [];
        return self::jsonRows(
            "SELECT coalesce(json_agg(r ORDER BY r.rowid), '[]'::json)::text AS rows FROM (
                 SELECT rowid, gamets, content FROM diarylog
                 WHERE lower(trim(people)) = lower(trim(\$1)) AND rowid > \$2::bigint) r",
            [$npcName, (string) $afterRowid], "reading the diary of {$npcName}");
    }

    /** Her last $n diary entries up to $newestRowid, oldest first: [rowid, content]. */
    public static function recentEntries(string $npcName, int $newestRowid, int $n): array
    {
        return self::jsonRows(
            "SELECT coalesce(json_agg(r ORDER BY r.rowid), '[]'::json)::text AS rows FROM (
                 SELECT rowid, content FROM diarylog
                 WHERE lower(trim(people)) = lower(trim(\$1)) AND rowid <= \$2::bigint
                 ORDER BY rowid DESC LIMIT \$3::int) r",
            [$npcName, (string) $newestRowid, (string) max(1, $n)], "reading the recent diary of {$npcName}");
    }

    /** Is this diary entry still there (a save load deletes the entries after the loaded time)? */
    public static function entryExists(int $rowid): bool
    {
        $row = self::db()->fetchOne('SELECT 1 AS present FROM diarylog WHERE rowid = $1::bigint', [(string) $rowid]);
        return isset($row['present']);
    }

    // =====================================================================
    // TRAJECTORY MODE: the job, the worker's one LLM call, its validation
    // =====================================================================

    public static function ensureTable(): void
    {
        if (self::present(self::TABLE)) return;
        self::db()->fetchOne('CREATE TABLE IF NOT EXISTS ' . self::TABLE . " (
            id bigserial PRIMARY KEY,
            npc_name text NOT NULL,
            job jsonb NOT NULL,
            status text NOT NULL DEFAULT 'pending',
            attempts integer NOT NULL DEFAULT 0,
            result jsonb,
            last_error text)");
        if (!self::present(self::TABLE)) {
            throw new RuntimeException('RelDynDiary: could not create ' . self::TABLE);
        }
    }

    /** A job of hers not applied yet (pending, done or dead). */
    public static function jobWaiting(string $npcName): bool
    {
        if (!self::present(self::TABLE)) return false;
        $row = self::db()->fetchOne('SELECT 1 AS waiting FROM ' . self::TABLE . ' WHERE lower(npc_name) = lower($1) LIMIT 1', [$npcName]);
        return isset($row['waiting']);
    }

    public static function enqueue(string $npcName, array $job): int
    {
        self::ensureTable();
        $row = self::db()->fetchOne('INSERT INTO ' . self::TABLE . ' (npc_name, job) VALUES ($1, $2::jsonb) RETURNING id',
            [$npcName, json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if (!isset($row['id'])) {
            throw new RuntimeException("RelDynDiary: queueing the reflection of {$npcName} failed");
        }
        RelationshipDynamics::log("[RelDyn-DIARY] {$npcName}: reflection queued for the worker (job {$row['id']})");
        return intval($row['id']);
    }

    /** Ask for a worker (the eval worker drains the diary reflections after its own jobs). */
    public static function launchWorker(): bool
    {
        if (self::$launcher !== null) { (self::$launcher)(); return true; }
        if (empty(self::config()['autostart_worker'])) return false;
        if (getenv('PHPUNIT_TEST')) return false;
        if (!class_exists('RelDynEval')) require_once __DIR__ . '/eval_producer.php';
        return RelDynEval::launchWorker();
    }

    public static function connectorId(array $cfg): int
    {
        $own = intval($cfg['connector_id'] ?? 0);
        if ($own > 0) return $own;
        if (class_exists('RelDynEval')) {
            $e = RelDynEval::connectorId(RelDynEval::config());
            if ($e > 0) return $e;
        }
        return intval($GLOBALS['RELLLM_CONNECTOR'] ?? 0);
    }

    /** Default LLM: the reflection connector through core's LLMConnector (as RelDynEval::defaultLlm). */
    public static function defaultLlm(): callable
    {
        return static function (array $messages, array $params) {
            $id = self::connectorId(self::config());
            if ($id <= 0) throw new RuntimeException('no diary reflection connector (diary_reflection.connector_id / eval connector / RELLLM_CONNECTOR)');
            require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';
            $lc = new LLMConnector();
            $conn = $lc->readOne($id);
            if (!is_array($conn) || empty($conn['driver'])) throw new RuntimeException("diary reflection connector {$id} not found");
            $driver = $lc->getConnector($conn);
            $saved = $GLOBALS['CONNECTOR'] ?? null;
            try {
                $lc->setOldGlobals($conn);
                return $driver->fast_request($messages, $params, 'reldyn_diary');
            } finally {
                if ($saved !== null) $GLOBALS['CONNECTOR'] = $saved;
            }
        };
    }

    /**
     * The one call's messages: her depth in words, the moments as felt, her state in band words
     * ($stateLines), her diary entries oldest first (each clipped to entry_max_chars).
     *
     * @param array $entries list of ['content' => string]
     * @return list<array{role:string,content:string}>
     */
    public static function buildMessages(string $npc, string $player, array $entries, array $stateLines, array $moments, string $depth, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $max = max(100, intval($cfg['entry_max_chars']));
        $parts = ["Writer: {$npc}"];
        $depthText = $cfg['depth_text'][$depth] ?? null;
        if (is_string($depthText) && $depthText !== '') $parts[] = strtr($depthText, ['{NAME}' => $npc]);
        $phrases = self::momentPhrases($moments, $player, $cfg);
        if ($phrases !== []) $parts[] = 'Since they last took stock: ' . implode('; ', $phrases) . '.';
        if ($stateLines !== []) $parts[] = "How they are now:\n- " . implode("\n- ", array_map('strval', $stateLines));
        $texts = [];
        foreach ($entries as $e) {
            $t = trim((string) ($e['content'] ?? ''));
            if ($t === '') continue;
            $texts[] = mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max)) . ' ...' : $t;
        }
        $parts[] = "Their diary, oldest entry first:\n\n" . implode("\n\n---\n\n", $texts);
        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => implode("\n\n", $parts)],
        ];
    }

    /**
     * Validate the LLM's reply into the reflection signal ['trajectory' => one of VERDICTS,
     * 'strength' => 1|2 (STRENGTH_WORDS), capped by max_strength for $depth], or null with $reason.
     * One JSON object (a code fence around it is allowed); strength absent = light; anything else
     * is refused.
     */
    public static function parse(string $raw, string $depth, ?string &$reason = null, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $s = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $s, $m)) $s = $m[1];
        $data = json_decode($s, true);
        if (!is_array($data) || array_is_list($data)) { $reason = 'not a JSON object'; return null; }
        $t = $data['trajectory'] ?? null;
        $t = is_string($t) ? strtolower(trim($t)) : null;
        if (!in_array($t, self::VERDICTS, true)) { $reason = 'trajectory is not growing, stagnating or spiraling'; return null; }
        $word = $data['strength'] ?? 'light';
        $strength = is_string($word) ? (self::STRENGTH_WORDS[strtolower(trim($word))] ?? null) : null;
        if ($strength === null) { $reason = 'strength is not light or strong'; return null; }
        $cap = max(1, intval($cfg['max_strength'][$depth] ?? 1));
        return ['trajectory' => $t, 'strength' => min($strength, $cap)];
    }

    /**
     * Drain pending jobs (eval worker, after its own jobs): own advisory lock, jobs_per_run calls,
     * attempts +1 per failure and 'dead' at max_attempts. Off (the autonomous diary or the
     * engine): returns at once and the rows STAY. $pause (default: a pending Playthrough Save
     * switch) is checked before every call.
     */
    public static function drain(?callable $llm = null, ?callable $pause = null): array
    {
        $stats = ['processed' => 0, 'done' => 0, 'dropped' => 0, 'failed' => 0, 'dead' => 0, 'locked' => false, 'off' => false, 'paused' => false];
        if (!self::enabled() || !RelationshipDynamics::isEnabled()) { $stats['off'] = true; return $stats; }
        if (!self::present(self::TABLE)) return $stats;
        if (!class_exists('RelDynEval')) require_once __DIR__ . '/eval_producer.php';
        $pause = $pause ?? [RelDynEval::class, 'playthroughSwitchPending'];
        $cfg = self::config();
        $db = self::db();
        $lock = $db->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [RelDynEval::LOCK_CLASS, self::LOCK_DIARY]);
        if (!in_array($lock['got'] ?? null, ['t', true], true)) { $stats['locked'] = true; return $stats; }
        try {
            $llm = $llm ?? self::$llm ?? self::defaultLlm();
            $tried = [];
            while ($stats['processed'] < max(1, intval($cfg['jobs_per_run']))) {
                if ($pause()) {
                    error_log('[RelDyn-DIARY] reflections stop: a Playthrough Save switch is pending; the rest wait for the next worker');
                    $stats['paused'] = true;
                    break;
                }
                $row = $db->fetchOne('SELECT id, npc_name, job::text AS job, attempts FROM ' . self::TABLE . "
                     WHERE status = 'pending' AND NOT (id = ANY(\$1::bigint[])) ORDER BY id LIMIT 1",
                    ['{' . implode(',', array_map('intval', $tried)) . '}']);
                if (!isset($row['id'])) break;
                $tried[] = intval($row['id']);
                $stats['processed']++;
                $stats[self::processRow($row, $llm, $cfg)]++;
            }
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [RelDynEval::LOCK_CLASS, self::LOCK_DIARY]);
        }
        return $stats;
    }

    /** @return string 'done' | 'dropped' | 'failed' | 'dead' */
    private static function processRow(array $row, callable $llm, array $cfg): string
    {
        $db = self::db();
        $id = intval($row['id']);
        $npc = (string) $row['npc_name'];
        $job = json_decode((string) $row['job'], true);
        $error = null;
        $result = null;
        try {
            if (!is_array($job) || !is_numeric($job['newest_rowid'] ?? null)) {
                throw new UnexpectedValueException('unreadable job');
            }
            if (!self::entryExists(intval($job['newest_rowid']))) {
                $db->fetchOne('DELETE FROM ' . self::TABLE . ' WHERE id = $1', [$id]);
                error_log("[RelDyn-DIARY] reflection job {$id} of {$npc} dropped: a save load discarded its diary entry");
                return 'dropped';
            }
            $entries = self::recentEntries($npc, intval($job['newest_rowid']), intval($cfg['entries']));
            $player = is_string($job['player'] ?? null) && $job['player'] !== '' ? $job['player'] : 'Player';
            $depth = (string) ($job['depth'] ?? 'pattern');
            $messages = self::buildMessages($npc, $player, $entries, self::stateLines($npc),
                (array) ($job['moments'] ?? []), $depth, $cfg);
            $out = $llm($messages, ['MAX_TOKENS' => intval($cfg['max_tokens']), 'temperature' => floatval($cfg['temperature'])]);
            $text = is_array($out) ? ($out['text'] ?? null) : $out;
            if (!is_string($text) || trim($text) === '') {
                $error = 'empty response';
            } else {
                $reason = null;
                $result = self::parse($text, $depth, $reason, $cfg);
                if ($result === null) $error = 'malformed: ' . $reason . ' :: ' . substr(trim($text), 0, 160);
            }
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            error_log("[RelDyn-DIARY] reflection job {$id} of {$npc} threw: " . substr($error, 0, 200));
        }
        if ($error === null) {
            $db->fetchOne('UPDATE ' . self::TABLE . " SET status = 'done', attempts = attempts + 1, result = \$2::jsonb, last_error = NULL WHERE id = \$1",
                [$id, json_encode($result)]);
            RelationshipDynamics::log("[RelDyn-DIARY] reflection job {$id} of {$npc}: " . json_encode($result));
            return 'done';
        }
        $dead = intval($row['attempts'] ?? 0) + 1 >= max(1, intval($cfg['max_attempts']));
        $db->fetchOne('UPDATE ' . self::TABLE . ' SET status = $2, attempts = attempts + 1, last_error = $3 WHERE id = $1',
            [$id, $dead ? 'dead' : 'pending', substr($error, 0, 300)]);
        error_log("[RelDyn-DIARY] ERROR reflection job {$id} of {$npc}" . ($dead ? ' (dead: the baseline verdict applies)' : '') . ': ' . substr($error, 0, 200));
        return $dead ? 'dead' : 'failed';
    }

    /** Her state in band words for the call (the eval's state summary, and how she sees herself). */
    public static function stateLines(string $npcName): array
    {
        if (!class_exists('RelDynEval')) require_once __DIR__ . '/eval_producer.php';
        RelationshipDynamics::beginRequest();
        try {
            $d = RelationshipDynamics::getDynamics($npcName);
            $lines = RelDynEval::stateSummary($npcName, $d);
            $self = [];
            foreach (['resentment_self' => 'Shame and self-blame', 'self_confidence' => 'Self-assurance'] as $dim => $label) {
                $x = $d['dimensions'][$dim]['x'] ?? null;
                $b = is_numeric($x) ? RelationshipDynamics::getDimensionBand($dim, floatval($x)) : null;
                if ($b) $self[] = "{$label}: {$b['label']}" . ($b['keywords'] !== '' ? " ({$b['keywords']})" : '');
            }
            return array_merge($lines, $self);
        } finally {
            RelationshipDynamics::endRequest();
        }
    }

    /**
     * On her prerequest: apply her finished jobs in order, each claimed by deleting it (applied
     * once). 'done' applies the LLM's verdict; 'dead' the baseline verdict taken when queued. A
     * job whose diary entry a save load discarded is dropped. Returns how many were applied.
     */
    public static function applyFinishedJobs(string $npcName, array &$dynamics, ?array $cfg = null): int
    {
        if (!self::present(self::TABLE)) return 0;
        $cfg = $cfg ?? self::config();
        $rows = self::jsonRows(
            "SELECT coalesce(json_agg(r ORDER BY r.id), '[]'::json)::text AS rows FROM (
                 SELECT id, status, job, result, attempts FROM " . self::TABLE . "
                 WHERE lower(npc_name) = lower(\$1) AND status IN ('done', 'dead')) r",
            [$npcName], "reading the finished reflections of {$npcName}");
        $n = 0;
        foreach ($rows as $r) {
            $claimed = self::db()->fetchOne('DELETE FROM ' . self::TABLE . ' WHERE id = $1 AND status = $2 RETURNING id', [intval($r['id']), (string) $r['status']]);
            if (!isset($claimed['id'])) continue;   // another request applied it
            $job = is_array($r['job'] ?? null) ? $r['job'] : [];
            if (!is_numeric($job['newest_rowid'] ?? null) || !self::entryExists(intval($job['newest_rowid']))) {
                error_log("[RelDyn-DIARY] reflection of {$npcName} (job {$r['id']}) dropped: a save load discarded its diary entry");
                continue;
            }
            $res = is_array($r['result'] ?? null) ? $r['result'] : [];
            $done = $r['status'] === 'done' && in_array($res['trajectory'] ?? null, self::VERDICTS, true)
                && in_array($res['strength'] ?? null, [1, 2], true);
            if (!$done) {
                if ($r['status'] === 'done') error_log("[RelDyn-DIARY] ERROR reflection of {$npcName} (job {$r['id']}): stored result unreadable, the baseline verdict applies");
                $verdict = in_array($job['fallback_verdict'] ?? null, self::VERDICTS, true) ? $job['fallback_verdict'] : 'stagnating';
                $strength = 1;
                $source = 'baseline_fallback';
            } else {
                $verdict = $res['trajectory'];
                $strength = intval($res['strength']);
                $source = 'trajectory';
            }
            $before = is_numeric($job['confidence_before'] ?? null) ? floatval($job['confidence_before']) : null;
            self::applyReflection($npcName, $dynamics, $verdict, $strength, $before, $source, $cfg);
            $n++;
        }
        return $n;
    }
}
