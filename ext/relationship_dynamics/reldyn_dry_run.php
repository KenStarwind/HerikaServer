<?php
/**
 * Relationship Dynamics — pipeline dry run (roadmap debug-tooling: "a real eval with a dry run of
 * processEvalDeltas showing the before and after"; D:\docs\plans\2026-04-01-chunk5-ui-tooling.md).
 *
 * What one eval item WOULD change for an NPC, without saving anything: the item goes through the
 * same consumer the eval inbox uses (processPendingEvalDeltas: processEvalContractItem for a
 * shared-contract item, processEvalDeltas for a legacy *_delta result) on a copy of her stored
 * state, inside a database transaction that is always rolled back, so the pipeline's own writes
 * (the player mirror's observation, core memory notes, ...) run for real and are then discarded.
 * What the inbox does AFTER the items (commitPlayerAffinity's locked core write, romance
 * promotion, bystander jealousy) is not run: those commit their own transactions. The affinity
 * the commit would push is reported instead.
 *
 * No LLM: the trait-read, diary and eval launchers are stubbed for the run. Guards: the
 * transaction's id and backend are checked after the run; a changed id or connection, or an
 * aborted transaction, is reported as a warning (the result may be incomplete, or something may
 * have been saved).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynDryRun
{
    /** Flattened changes shown at most (paths). */
    const MAX_CHANGES = 400;

    /** Jev state keys compared before / after (dot paths). */
    const HEADLINE = [
        'affinity', 'affinity_tier', 'relationship_type', 'trust', 'comfort', 'respect', 'warmth', 'maturity', 'passion',
        'passion_spike', 'passion_effective', 'jealousy', 'resentment', 'resentment_self', 'self_confidence', 'arousal', 'valence',
        'attachment', 'attachment_anxiety', 'attachment_avoidance', 'open_conflict', 'conflict_repairs', 'walkaway', 'boundary',
        'weather', 'fulfillment.band', 'fulfillment.low', 'concern.level', 'concern.band', 'let_in', 'pullback.active', 'pullback.pressure', 'exclusivity.pull', 'exclusivity.band',
        'attraction.passion_mult', 'attraction.curve', 'attraction.won_over', 'attraction.friendzoned', 'context_tier',
    ];

    /**
     * A sample shared-contract item for $npcName (the dry-run form's starting point).
     */
    public static function sampleItem(string $npcName, float $gamets = 0.0): array
    {
        $item = [
            'v' => RelationshipDynamics::EVAL_CONTRACT_VERSION,
            'source' => RelationshipDynamics::EVAL_CONTRACT_SOURCE,
            'npc' => $npcName,
            'signals' => ['affinity' => 3, 'trust' => 2, 'comfort' => 2, 'respect' => 1, 'passion' => 0, 'maturity' => 0],
            'tags' => ['help'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.4,
            'positive_interaction' => true,
            'summary' => 'The player helped with a chore and kept a promise.',
            'romantic_intent' => 0,
            'charisma' => 'rock',
        ];
        if ($gamets > 0) $item['gamets'] = (int) round($gamets);
        return $item;
    }

    /**
     * Dry-run $item for $npcName.
     * $opts: 'as_new' (default true): an item already applied to the NPC is run as if new (its
     * fingerprint taken off the copy); 'now' (raw gamets): the game time of an item without one,
     * and of the before / after reads.
     *
     * @return array ['ok', 'error', 'npc', 'kind' contract|legacy, 'normalized', 'fingerprint',
     *   'already_applied', 'engine_on', 'totals' (dimension => actual change), 'feelings',
     *   'headline' (path => [before, after], changed ones), 'changes' (path => [before, after]),
     *   'changes_total', 'affinity' ([before, after, delta] core points), 'mirror_observation',
     *   'log' (lines the run logged), 'warnings', 'saved' (always false)]
     */
    public static function run(string $npcName, array $item, array $opts = []): array
    {
        // no LLM and no background job from anything the run reads or does (getDynamics too)
        $launchers = self::stubLaunchers();
        try {
            return self::runStubbed($npcName, $item, $opts);
        } finally {
            self::restoreLaunchers($launchers);
        }
    }

    private static function runStubbed(string $npcName, array $item, array $opts): array
    {
        $npcName = trim($npcName);
        $out = ['ok' => false, 'error' => null, 'npc' => $npcName, 'kind' => null, 'normalized' => null, 'fingerprint' => null,
            'already_applied' => false, 'engine_on' => true, 'totals' => [], 'feelings' => [], 'headline' => [], 'changes' => [],
            'changes_total' => 0, 'affinity' => null, 'mirror_observation' => null, 'log' => [], 'warnings' => [], 'saved' => false];
        $db = $GLOBALS['db'] ?? null;
        if (!$db || !method_exists($db, 'execQuery')) {
            $out['error'] = 'No database connection.';
            return $out;
        }
        if ($npcName === '' || RelDynStorage::resolveNpcId($npcName) === null) {
            $out['error'] = 'Unknown NPC.';
            return $out;
        }
        $out['engine_on'] = !empty(RelationshipDynamics::getConfig()['dimension_engine_enabled']);

        $contract = RelationshipDynamics::isEvalContractItem($item);
        $out['kind'] = $contract ? 'contract' : 'legacy';
        if ($contract) {
            if (!is_string($item['npc'] ?? null) || trim($item['npc']) === '') $item['npc'] = $npcName;
            if (strcasecmp(trim((string) $item['npc']), $npcName) !== 0) {
                $out['error'] = 'The item is addressed to another NPC (' . trim((string) $item['npc']) . ').';
                return $out;
            }
            // an item without a game time happens now (the game time the page read)
            if (!is_numeric($item['gamets'] ?? null) && floatval($opts['now'] ?? 0) > 0) $item['gamets'] = (int) round(floatval($opts['now']));
            $n = RelationshipDynamics::normalizeEvalContractItem($item);
            if ($n === null) {
                $out['error'] = 'The item does not pass the eval contract (v, source, npc, signals, significance); see the log lines.';
                $out['log'] = self::captureLog(fn() => RelationshipDynamics::normalizeEvalContractItem($item))[1];
                return $out;
            }
            $out['normalized'] = $n;
            $out['fingerprint'] = RelationshipDynamics::evalContractFingerprint($item);
        } elseif (array_intersect(array_keys($item), array_keys(RelationshipDynamics::EVAL_DELTA_MAP)) === []) {
            $out['error'] = 'Neither a contract item (v, signals) nor a legacy eval result (*_delta keys).';
            return $out;
        }

        $now = floatval($opts['now'] ?? 0) > 0 ? floatval($opts['now']) : RelationshipDynamics::currentGamets();
        $before = RelationshipDynamics::getDynamics($npcName);
        $applied = is_array($before['_eval_applied'] ?? null) ? array_values($before['_eval_applied']) : [];
        if ($out['fingerprint'] !== null && in_array($out['fingerprint'], $applied, true)) {
            $out['already_applied'] = true;
            if (!array_key_exists('as_new', $opts) || !empty($opts['as_new'])) {
                $before['_eval_applied'] = array_values(array_diff($applied, [$out['fingerprint']]));
                $out['warnings'][] = 'This item was already applied to this NPC; it is run as if new.';
            }
        }

        // ---- the run, inside a transaction that is always rolled back
        $savedGlobals = [];
        foreach (array_keys($GLOBALS) as $k) if (is_string($k) && str_starts_with($k, 'RELDYN_')) $savedGlobals[$k] = $GLOBALS[$k];
        $copy = $before;
        $feelings = [];
        $totals = [];
        $observation = null;
        $error = null;
        $began = $db->execQuery('BEGIN');
        if ($began === false) {
            $out['error'] = 'Could not open a transaction; nothing was run.';
            return $out;
        }
        $tx0 = self::txId($db);
        try {
            [, $log] = self::captureLog(function () use ($npcName, $item, $contract, &$copy, &$feelings, &$totals) {
                $totals = $contract
                    ? RelationshipDynamics::processEvalContractItem($npcName, $item, $copy, $feelings)
                    : RelationshipDynamics::processEvalDeltas($npcName, $item, $copy);
            });
            $out['log'] = $log;
            if ($out['fingerprint'] !== null) $observation = self::mirrorObservation($db, $out['fingerprint']);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("pipeline dry run for {$npcName} (rolled back)", $e);
            $error = get_class($e) . ': ' . $e->getMessage();
        } finally {
            $tx1 = self::txId($db);
            $db->execQuery('ROLLBACK');
            foreach (array_keys($GLOBALS) as $k) {
                if (is_string($k) && str_starts_with($k, 'RELDYN_') && !array_key_exists($k, $savedGlobals)) unset($GLOBALS[$k]);
            }
            foreach ($savedGlobals as $k => $v) $GLOBALS[$k] = $v;
        }
        if ($tx0 === null) {
            $out['warnings'][] = 'Could not read the transaction id before the run.';
        } elseif ($tx1 === null) {
            $out['warnings'][] = 'A statement failed during the run (the transaction was aborted): what follows it may be incomplete. Nothing was saved.';
        } elseif ($tx1 !== $tx0) {
            $out['warnings'][] = 'The run left its transaction (another transaction or connection took over): something may have been saved. Check the server log.';
        }
        if ($error !== null) {
            $out['error'] = 'The pipeline threw: ' . $error;
            return $out;
        }

        // ---- what it would change
        $out['ok'] = true;
        $out['totals'] = array_map(fn($v) => round(floatval($v), 4), (array) $totals);
        $out['feelings'] = (array) $feelings;
        $out['mirror_observation'] = $observation;
        $strip = function (array $d): array {
            unset($d[RelationshipDynamics::LOAD_TOKEN_KEY]);
            return $d;
        };
        $flatBefore = self::flatten($strip($before));
        $flatAfter = self::flatten($strip($copy));
        $changes = [];
        foreach (array_unique(array_merge(array_keys($flatBefore), array_keys($flatAfter))) as $path) {
            $b = $flatBefore[$path] ?? null;
            $a = $flatAfter[$path] ?? null;
            if ($b !== $a) $changes[$path] = [$b, $a];
        }
        ksort($changes, SORT_STRING);
        $out['changes_total'] = count($changes);
        $out['changes'] = array_slice($changes, 0, self::MAX_CHANGES, true);
        $affBefore = is_numeric($before['_aff_mirror_x'] ?? null) ? RelationshipDynamics::getCoreAffinity($before) : null;
        $affAfter = is_numeric($copy['_aff_mirror_x'] ?? null) ? RelationshipDynamics::getCoreAffinity($copy) : null;
        $out['affinity'] = ['before' => $affBefore !== null ? round($affBefore, 3) : null, 'after' => $affAfter !== null ? round($affAfter, 3) : null,
            'delta' => $affBefore !== null && $affAfter !== null ? round($affAfter - $affBefore, 3) : null];
        try {
            $jb = RelDynJev::state($npcName, $before, $now);
            $ja = RelDynJev::state($npcName, $copy, $now);
            foreach (self::HEADLINE as $path) {
                $b = self::dig($jb, $path);
                $a = self::dig($ja, $path);
                $out['headline'][$path] = [$b, $a];
            }
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("pipeline dry run summary for {$npcName}", $e);
            $out['warnings'][] = 'The before / after summary could not be read: ' . $e->getMessage();
        }
        return $out;
    }

    /** Current transaction id (txid_current), or null when unreadable (no transaction, aborted). */
    private static function txId($db): ?string
    {
        try {
            $row = $db->fetchOne('SELECT txid_current()::text AS x, pg_backend_pid()::text AS p');
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('pipeline dry run transaction id', $e);
            return null;
        }
        return is_array($row) && isset($row['x'], $row['p']) ? $row['x'] . '@' . $row['p'] : null;
    }

    /** The player mirror observation the run recorded (read inside the transaction), or null. */
    private static function mirrorObservation($db, string $fingerprint): ?array
    {
        try {
            $row = $db->fetchOne('SELECT value FROM core_player WHERE id = $1', [RelDynMirror::ROW_ID]);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('pipeline dry run mirror observation', $e);
            return null;
        }
        $s = is_string($row['value'] ?? null) ? json_decode($row['value'], true) : null;
        foreach ((array) ($s['obs'] ?? []) as $o) {
            if (is_array($o) && ($o['fp'] ?? null) === $fingerprint) return $o;
        }
        return null;
    }

    /** Run $fn with error_log captured: [result, lines]. */
    private static function captureLog(callable $fn): array
    {
        $file = tempnam(sys_get_temp_dir(), 'rddry');
        $prev = ini_get('error_log');
        $set = $file !== false && ini_set('error_log', $file) !== false;
        try {
            $result = $fn();
        } finally {
            if ($set) ini_set('error_log', $prev === false ? '' : (string) $prev);
        }
        $lines = [];
        if ($file !== false) {
            foreach (preg_split('/\R/', (string) @file_get_contents($file)) ?: [] as $l) {
                $l = trim(preg_replace('/^\[[^\]]*\]\s*/', '', $l));
                if ($l !== '') $lines[] = mb_substr($l, 0, 2000);
            }
            @unlink($file);
        }
        return [$result, array_slice($lines, 0, 300)];
    }

    /** No LLM and no background job from a dry run: the launchers become no-ops for it. */
    private static function stubLaunchers(): array
    {
        $saved = [];
        $noop = function (...$args) { return null; };
        foreach ([['RelDynTraitRead', 'launcher'], ['RelDynTraitRead', 'llm'], ['RelDynEval', 'launcher'],
                     ['RelDynDiary', 'launcher'], ['RelDynDiary', 'llm']] as [$class, $prop]) {
            if (!class_exists($class) || !property_exists($class, $prop)) continue;
            $saved[] = [$class, $prop, $class::$$prop];
            $class::$$prop = $noop;
        }
        return $saved;
    }

    private static function restoreLaunchers(array $saved): void
    {
        foreach ($saved as [$class, $prop, $value]) $class::$$prop = $value;
    }

    /** Dot-path => scalar (JSON for lists and empty arrays) of a nested array. */
    public static function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $path = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $out += self::flatten($v, $path);
            } elseif (is_array($v)) {
                $out[$path] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_float($v)) {
                $out[$path] = round($v, 6);
            } else {
                $out[$path] = $v;
            }
        }
        return $out;
    }

    private static function dig(array $a, string $path)
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) return null;
            $a = $a[$k];
        }
        return $a;
    }
}
