<?php
/**
 * Relationship Dynamics — own eval producer (assessment 2026-09-23 §E Phase 2, option A)
 *
 * Flow (no CHIM core edits):
 *   1. postrequest.php calls RelDynEval::onPostrequest() after an NPC replied. It decides
 *      whether this exchange is evaluated (never the Narrator; radiant / NPC-to-NPC only when
 *      the player is addressed; config chance and cooldown), appends one job to RelDyn's own
 *      table reldyn_eval_queue and starts a one-shot worker process. Nothing blocks.
 *   2. eval_worker.php (CLI) runs RelDynEval::runWorker(): one drainer at a time (advisory
 *      lock), jobs in id order. Per job it reads FRESH state (dynamics, config, connector),
 *      builds the eval prompt from a real eventlog window (prechat excluded, speakers parsed),
 *      calls the eval LLM, validates the JSON strictly into the shared eval contract and hands
 *      the item to the eval inbox in the same statement that deletes the job
 *      (RelDynStorage::appendItemConsumingRow). The next request of that NPC consumes the inbox.
 *   3. Failures: an LLM/transport error or a failed inbox write keeps the job (attempts+1,
 *      dead-lettered as status 'dead' after max_attempts, never deleted). Malformed LLM output
 *      is logged and dropped (not applied). A job whose exchange a save load rolled back
 *      (its anchor eventlog row is gone) is dropped, like core's queue clear on load.
 *
 * Shared eval contract (v1), produced here, consumed by the engine lanes:
 *   {v, npc, npc_id, gamets, source:"reldyn_eval",
 *    signals:{affinity,trust,comfort,respect,passion -30..30, maturity -10..10} (raw, ints;
 *            affinity in CORE affinity points, -100..100 scale, before RelDyn physics),
 *    tags:[subset of TAGS], grievance:{flag,kind,severity 0..3},
 *    jealousy:{flag,rival,intensity 0..3}, significance 0..1, positive_interaction, summary}
 *
 * Classification: tags are the one interaction classification source. TAG_LOVE_LANGUAGE /
 * LOVE_LANGUAGE_TAGS translate between tags and RelDyn's love-language constants, and
 * positive_interaction is derived from signals + tags + grievance by code, never by the LLM.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynEval
{
    const CONTRACT_VERSION = 1;
    const SOURCE = 'reldyn_eval';
    const QUEUE_TABLE = 'reldyn_eval_queue';
    const NARRATOR = 'The Narrator';

    /** plugin storage key (RelDynStorage 'reldyn' namespace) holding the producer's cooldown mark */
    const KEY_PRODUCER = 'eval_producer';

    /**
     * pg advisory lock in the TWO-int4 key space, which does not overlap the bigint key space
     * core's relationship_system uses (1001000000 + npc id). (LOCK_CLASS, LOCK_DRAINER) = the
     * single-drainer lock of the eval worker.
     */
    const LOCK_CLASS = 1380218437;   // int4 constant, 'RDvE'
    const LOCK_DRAINER = 1;

    /** Contract signal => max |raw value| (contract units: core affinity points / dimension points). */
    const SIGNAL_LIMITS = [
        'affinity' => 30,
        'trust'    => 30,
        'comfort'  => 30,
        'respect'  => 30,
        'passion'  => 30,
        'maturity' => 10,
    ];

    /** Signals whose sum decides positive_interaction (maturity is the NPC's own handling, not about the player). */
    const POSITIVE_SIGNALS = ['affinity', 'trust', 'comfort', 'respect', 'passion'];

    /** grievance.severity and jealousy.intensity upper bound (0..3 scale). */
    const LEVEL_MAX = 3;

    /** significance (0..1) when the LLM omits it: a normal exchange, MDD 15.1 "normal ±10" of ±30. */
    const DEFAULT_SIGNIFICANCE = 0.33;

    /** Max characters kept of summary (logs) and grievance kind. */
    const SUMMARY_MAX_CHARS = 200;
    const KIND_MAX_CHARS = 40;

    /** Source tags (decisions 2026-09-23 §1 plus reassurance and apology) with the definitions the LLM sees. */
    const TAG_DEFINITIONS = [
        'gift'             => 'the player gave them something of value',
        'praise'           => 'the player complimented, thanked or admired them',
        'help'             => 'the player did something practical for them or their goals',
        'rescue'           => 'the player saved or protected them from danger',
        'quality_time'     => 'the player gave them attention: real conversation, shared moments',
        'touch'            => 'non-sexual physical affection (hug, hand, kiss on the cheek)',
        'intimacy'         => 'romantic or sexual closeness',
        'insult'           => 'the player mocked, belittled or was rude to them',
        'criticism'        => 'the player judged or corrected them (fair or not)',
        'neglect'          => 'the player ignored, dismissed or forgot them',
        'jealousy_trigger' => 'the player gave attention or affection to a rival in front of them',
        'command'          => 'the player ordered them around instead of asking',
        'betrayal'         => 'the player broke a promise or acted against them',
        'lie'              => 'the player lied to them or was caught deceiving',
        'competence'       => 'the player showed skill or capability',
        'reassurance'      => 'the player calmed a fear or doubt they had',
        'apology'          => 'the player apologised or made amends',
    ];

    /** Tags that mark an exchange as not positive, whatever the signals say. */
    const NEGATIVE_TAGS = ['insult', 'criticism', 'neglect', 'jealousy_trigger', 'betrayal', 'lie'];

    /** Tag => love language it feeds (RelationshipDynamics::LL_* values). Unlisted tags feed none. */
    const TAG_LOVE_LANGUAGE = [
        'gift'         => 'gifts',
        'praise'       => 'words_of_affirmation',
        'reassurance'  => 'words_of_affirmation',
        'apology'      => 'words_of_affirmation',
        'quality_time' => 'quality_time',
        'touch'        => 'physical_touch',
        'intimacy'     => 'physical_touch',
        'help'         => 'acts_of_service',
        'rescue'       => 'acts_of_service',
    ];

    /** Love language => the tag a local classifyInteraction() result stands for. */
    const LOVE_LANGUAGE_TAGS = [
        'gifts'                => 'gift',
        'words_of_affirmation' => 'praise',
        'quality_time'         => 'quality_time',
        'physical_touch'       => 'touch',
        'acts_of_service'      => 'help',
    ];

    /** Request types where the player spoke to the NPC. */
    const PLAYER_INPUT_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];

    /** eventlog types read into the conversation window ('prechat' is excluded on purpose). */
    const WINDOW_ROW_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'chat'];

    /** Visible chat delivery states (lib chimGetVisibleChatDeliveryStates()); NULL = legacy row. */
    const VISIBLE_CHAT_STATES = ['emitted', 'spoken'];

    /** Test seam: callable(): void replacing the worker process launch. */
    public static $launcher = null;

    // =========================================================================
    // CONFIG
    // =========================================================================

    public static function defaultConfig(): array
    {
        return [
            'enabled'          => true,
            'chance'           => 1.0,   // probability (0..1) an eligible exchange is evaluated (core's is 50%)
            'cooldown_gamets'  => 0,     // raw gamets between two queued evals of one NPC; 0 = every exchange
            'connector_id'     => 0,     // core_llm_connector id; 0 = core RELLLM_CONNECTOR
            'window_lines'     => 12,    // dialogue lines (player + NPC) the eval sees
            'scan_rows'        => 200,   // dialogue rows read back from eventlog to find them
            'max_attempts'     => 3,     // LLM/transport failures before a job is dead-lettered
            'max_tokens'       => 768,   // completion tokens (April core patch raised 512 -> 768)
            'jobs_per_run'     => 25,    // jobs one worker process handles before it exits
            'autostart_worker' => true,  // start the worker process after queueing
        ];
    }

    /** RelDyn config row key 'eval_producer' merged over defaultConfig(); read fresh on every call. */
    public static function config(): array
    {
        $stored = RelationshipDynamics::getConfig()['eval_producer'] ?? [];
        return array_merge(self::defaultConfig(), is_array($stored) ? $stored : []);
    }

    public static function connectorId(array $cfg): int
    {
        $own = intval($cfg['connector_id'] ?? 0);
        return $own > 0 ? $own : intval($GLOBALS['RELLLM_CONNECTOR'] ?? 0);
    }

    // =========================================================================
    // PRODUCER (postrequest)
    // =========================================================================

    /**
     * Why this exchange is not evaluated, or null when it is.
     *
     * @param string|null $listener who the NPC's reply was addressed to (SCRIPTLINE_LISTENER*)
     */
    public static function skipReason(string $npcName, array $gameRequest, ?string $listener, string $playerName, array $cfg): ?string
    {
        if (empty($cfg['enabled'])) {
            return 'disabled';
        }
        $npc = trim($npcName);
        if ($npc === '' || strcasecmp($npc, self::NARRATOR) === 0) {
            return 'narrator';
        }
        if (self::sameName($npc, $playerName)) {
            return 'player';
        }
        $type = strtolower((string) ($gameRequest[0] ?? ''));
        if ($type === '' || $type === 'narrator_inputtext') {
            return 'narrator';
        }
        if (in_array($type, self::PLAYER_INPUT_TYPES, true)) {
            return null;   // the player spoke to this NPC
        }
        // NPC-initiated (radiant, rechat, instruction, ...): only when the reply addressed the player
        return self::isPlayerAddressed($listener, $playerName) ? null : 'player_not_addressed';
    }

    public static function isPlayerAddressed(?string $listener, string $playerName): bool
    {
        if ($listener === null || trim($listener) === '') {
            return false;
        }
        foreach (preg_split('/\s*(?:,|&|\band\b)\s*/iu', trim($listener)) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && (self::sameName($part, $playerName) || strcasecmp($part, 'Player') === 0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Queue this exchange for evaluation (called from postrequest.php). Cheap: a few indexed
     * statements, then a detached worker process. Returns the job id, or null when skipped.
     */
    public static function onPostrequest(string $npcName, array $gameRequest): ?int
    {
        try {
            $cfg = self::config();
            $listener = $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] ?? null;
            if (!is_string($listener) || trim($listener) === '') {
                $listener = is_string($GLOBALS['SCRIPTLINE_LISTENER'] ?? null) ? $GLOBALS['SCRIPTLINE_LISTENER'] : null;
            }
            $playerName = self::playerName();

            $reason = self::skipReason($npcName, $gameRequest, $listener, $playerName, $cfg);
            if ($reason !== null) {
                RelationshipDynamics::log("EVAL skip {$npcName}: {$reason}");
                return null;
            }
            if (self::connectorId($cfg) <= 0) {
                RelationshipDynamics::log("EVAL skip {$npcName}: no eval connector (eval_producer.connector_id / RELLLM_CONNECTOR)");
                return null;
            }
            $npcId = RelDynStorage::resolveNpcId($npcName);
            if ($npcId === null) {
                RelationshipDynamics::log("EVAL skip {$npcName}: not in core_npc_master");
                return null;
            }

            $gamets = floatval($gameRequest[2] ?? 0);   // raw game-calendar gamets of this request
            $cooldown = floatval($cfg['cooldown_gamets'] ?? 0);   // raw gamets
            if ($cooldown > 0) {
                $last = RelDynStorage::getAll($npcId)[self::KEY_PRODUCER]['last_enqueued_gamets'] ?? null;
                // A clock that went back (save load) never blocks.
                if (is_numeric($last) && $gamets >= floatval($last) && $gamets - floatval($last) < $cooldown) {
                    RelationshipDynamics::log("EVAL skip {$npcName}: cooldown");
                    return null;
                }
            }
            $chance = max(0.0, min(1.0, floatval($cfg['chance'] ?? 1.0)));   // probability 0..1
            if ($chance < 1.0 && random_int(0, 999999) / 1000000 >= $chance) {
                RelationshipDynamics::log("EVAL skip {$npcName}: chance");
                return null;
            }

            $producer = RelDynStorage::getAll($npcId)[self::KEY_PRODUCER] ?? [];
            // The exchange = this request's eventlog rows: nothing logged after its game time
            // (another request of the same NPC may already be logged when this hook runs).
            $anchor = self::exchangeAnchorRowid($gamets);
            // Rows up to the previous job's anchor were scored by that job (a follow-up line
            // without a new player line must not score the player line before it again).
            $prevAnchor = $producer['last_anchor_rowid'] ?? null;
            $scoredThrough = (is_numeric($prevAnchor) && $anchor !== null && intval($prevAnchor) < $anchor) ? intval($prevAnchor) : null;
            $job = [
                'v'            => self::CONTRACT_VERSION,
                'npc'          => $npcName,
                'npc_id'       => $npcId,
                'player_name'  => $playerName,
                'gamets'       => $gamets,
                'request_type' => (string) ($gameRequest[0] ?? ''),
                'listener'     => $listener,
                'anchor_rowid' => $anchor,
                'scored_through_rowid' => $scoredThrough,
                'event_tags'   => self::eventTagsForRequest($gameRequest),
            ];
            $jobId = self::enqueue($npcId, $npcName, $job);
            RelDynStorage::setKey($npcId, self::KEY_PRODUCER, [
                'last_enqueued_gamets' => $gamets,
                'last_anchor_rowid'    => max(intval($anchor ?? 0), is_numeric($prevAnchor) ? intval($prevAnchor) : 0),
            ]);
            RelationshipDynamics::log("EVAL queued job {$jobId} for {$npcName} (type={$job['request_type']}, anchor={$job['anchor_rowid']})");

            if (!empty($cfg['autostart_worker'])) {
                self::launchWorker();
            }
            return $jobId;
        } catch (\Throwable $e) {
            error_log("[RelDyn-EVAL] ERROR onPostrequest for {$npcName}: " . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Tags the request itself proves (touch actions, combat, item handover), from the same
     * classifier the passion path uses (classifyInteraction without a mood). The dialogue
     * fallback (quality_time for any talk) is not an event and is left to the eval.
     */
    public static function eventTagsForRequest(array $gameRequest): array
    {
        $ll = RelationshipDynamics::classifyInteraction($gameRequest, null);
        if ($ll === null || $ll === RelationshipDynamics::LL_TIME) {
            return [];
        }
        return self::tagsForLoveLanguage($ll);
    }

    private static function playerName(): string
    {
        foreach (['RELDYN_PLAYER_NAME', 'PLAYER_NAME'] as $key) {
            $v = $GLOBALS[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return 'Player';
    }

    private static function db()
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            throw new RuntimeException('RelDynEval: no database connection in $GLOBALS[\'db\']');
        }
        return $db;
    }

    /**
     * Raw gamets after a request's own gamets that its logged rows can carry: returnLines()
     * logs each NPC line as prechat at gamets+1 and chat at gamets+2 (chat_helper_functions).
     * 100 raw gamets is under one game second (1 game day = 1e7), so another exchange is
     * never inside it.
     */
    const EXCHANGE_GAMETS_SLACK = 100;

    /**
     * The newest eventlog row of the exchange at raw game time $gamets: the last row logged
     * no later than $gamets + EXCHANGE_GAMETS_SLACK. Rows of a later request of the same NPC
     * (already logged when this hook runs) are after it. Game clock unknown (0): newest row.
     */
    public static function exchangeAnchorRowid(float $gamets): ?int
    {
        if ($gamets > 0) {
            $row = self::db()->fetchOne('SELECT max(rowid) AS r FROM eventlog WHERE gamets <= $1',
                [(string) intval(floor($gamets + self::EXCHANGE_GAMETS_SLACK))]);
        } else {
            $row = self::db()->fetchOne('SELECT max(rowid) AS r FROM eventlog');
        }
        return (isset($row['r']) && is_numeric($row['r'])) ? intval($row['r']) : null;
    }

    // =========================================================================
    // QUEUE
    // =========================================================================

    /** Create reldyn_eval_queue when missing (idempotent, checked on every call: no process memo). */
    public static function ensureQueueTable(): void
    {
        $db = self::db();
        $row = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::QUEUE_TABLE]);
        if (($row['present'] ?? null) === 't' || ($row['present'] ?? null) === true) {
            return;
        }
        $db->fetchOne(
            "CREATE TABLE IF NOT EXISTS " . self::QUEUE_TABLE . " (
                 id bigserial PRIMARY KEY,
                 npc_id integer NOT NULL,
                 npc_name text NOT NULL,
                 job jsonb NOT NULL,
                 status text NOT NULL DEFAULT 'pending',
                 attempts integer NOT NULL DEFAULT 0,
                 last_error text
             )"
        );
        $db->fetchOne("CREATE INDEX IF NOT EXISTS " . self::QUEUE_TABLE . "_status_id ON " . self::QUEUE_TABLE . " (status, id)");
        $check = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::QUEUE_TABLE]);
        if (!in_array($check['present'] ?? null, ['t', true], true)) {
            throw new RuntimeException('RelDynEval: could not create ' . self::QUEUE_TABLE);
        }
    }

    public static function enqueue(int $npcId, string $npcName, array $job): int
    {
        self::ensureQueueTable();
        $row = self::db()->fetchOne(
            'INSERT INTO ' . self::QUEUE_TABLE . ' (npc_id, npc_name, job) VALUES ($1, $2, $3::jsonb) RETURNING id',
            [$npcId, $npcName, json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        if (!isset($row['id'])) {
            throw new RuntimeException("RelDynEval: enqueue failed for {$npcName}");
        }
        return intval($row['id']);
    }

    /** Start a detached one-shot worker (mirrors core context_pre's proc_open launch). */
    public static function launchWorker(): bool
    {
        if (self::$launcher !== null) {
            (self::$launcher)();
            return true;
        }
        if (getenv('PHPUNIT_TEST')) {
            RelationshipDynamics::log('EVAL worker not launched under PHPUNIT_TEST');
            return false;
        }
        $enginePath = $GLOBALS['ENGINE_PATH'] ?? (dirname(__DIR__, 2) . '/');
        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
        $log = rtrim($enginePath, '/') . '/log/reldyn_eval_worker.log';
        $cmd = (is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '')
            . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/eval_worker.php')
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            error_log('[RelDyn-EVAL] ERROR launchWorker: proc_open failed');
            return false;
        }
        proc_close($proc);   // returns once the shell has backgrounded the worker
        return true;
    }

    // =========================================================================
    // WORKER
    // =========================================================================

    /**
     * Worker entry: drain until no pending job is left that this run has not tried. The
     * lock is released before the final re-check, so a job queued while another drainer
     * was finishing is never stranded.
     *
     * @param callable|null $llm callable(array $messages, array $params): ?string; null = connector
     */
    public static function runWorker(?callable $llm = null): array
    {
        $total = ['processed' => 0, 'queued' => 0, 'dropped' => 0, 'failed' => 0, 'dead' => 0, 'locked' => false];
        $tried = [];
        $blockedNpcs = [];
        for ($pass = 0; $pass < 3; $pass++) {
            $stats = self::drain($llm, $tried, $blockedNpcs);
            foreach (['processed', 'queued', 'dropped', 'failed', 'dead'] as $k) {
                $total[$k] += $stats[$k];
            }
            if ($stats['locked']) {
                $total['locked'] = true;
                break;   // another drainer holds the lock; it re-checks after releasing
            }
            if (self::pendingCount($tried, $blockedNpcs) === 0) {
                break;
            }
        }
        return $total;
    }

    /**
     * One locked pass over pending jobs in id order. Each job is tried at most once per
     * worker run ($tried); after a failure the NPC's later jobs wait for the next run
     * ($blockedNpcs), so its inbox keeps order.
     */
    public static function drain(?callable $llm = null, array &$tried = [], array &$blockedNpcs = []): array
    {
        $stats = ['processed' => 0, 'queued' => 0, 'dropped' => 0, 'failed' => 0, 'dead' => 0, 'locked' => false];
        self::ensureQueueTable();
        $db = self::db();
        $lock = $db->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [self::LOCK_CLASS, self::LOCK_DRAINER]);
        if (!in_array($lock['got'] ?? null, ['t', true], true)) {
            $stats['locked'] = true;
            return $stats;
        }
        try {
            $limit = max(1, intval(self::config()['jobs_per_run']));
            while ($stats['processed'] < $limit) {
                $row = $db->fetchOne(
                    'SELECT id, npc_id, npc_name, job::text AS job, attempts FROM ' . self::QUEUE_TABLE . "
                     WHERE status = 'pending' AND NOT (id = ANY(\$1::bigint[])) AND NOT (npc_id = ANY(\$2::int[]))
                     ORDER BY id LIMIT 1",
                    [self::pgIntArray($tried), self::pgIntArray($blockedNpcs)]
                );
                if (!isset($row['id'])) {
                    break;
                }
                $id = intval($row['id']);
                $tried[] = $id;
                $stats['processed']++;
                $outcome = self::processJob($row, $llm);
                $stats[$outcome]++;
                if ($outcome === 'failed' || $outcome === 'dead') {
                    $blockedNpcs[] = intval($row['npc_id']);
                }
            }
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [self::LOCK_CLASS, self::LOCK_DRAINER]);
        }
        return $stats;
    }

    private static function pendingCount(array $excludeIds, array $excludeNpcs): int
    {
        $row = self::db()->fetchOne(
            'SELECT count(*) AS n FROM ' . self::QUEUE_TABLE . "
             WHERE status = 'pending' AND NOT (id = ANY(\$1::bigint[])) AND NOT (npc_id = ANY(\$2::int[]))",
            [self::pgIntArray($excludeIds), self::pgIntArray($excludeNpcs)]
        );
        return intval($row['n'] ?? 0);
    }

    private static function pgIntArray(array $ints): string
    {
        return '{' . implode(',', array_map('intval', $ints)) . '}';
    }

    /** @return string 'queued' | 'dropped' | 'failed' | 'dead' */
    private static function processJob(array $row, ?callable $llm): string
    {
        $id = intval($row['id']);
        $npc = (string) $row['npc_name'];
        $job = json_decode((string) $row['job'], true);
        if (!is_array($job) || !is_string($job['npc'] ?? null)) {
            error_log("[RelDyn-EVAL] ERROR job {$id} for {$npc}: unreadable job payload, dropped");
            self::deleteJob($id);
            return 'dropped';
        }

        try {
            $result = self::evaluateJob($job, $llm ?? self::defaultLlm());
            if (isset($result['drop'])) {
                self::deleteJob($id);
                return 'dropped';
            }
            // The inbox write and the job delete are ONE statement: a worker that dies in
            // between, or a delete that does nothing, can never leave the exchange queued
            // for a second evaluation next to its inbox item.
            $npcId = RelDynStorage::resolveNpcId($job['npc']);
            if ($npcId === null) {
                throw new RuntimeException("eval inbox write failed: '{$job['npc']}' is not in core_npc_master");
            }
            if (!RelDynStorage::appendItemConsumingRow($npcId, RelDynStorage::KEY_EVAL_INBOX,
                    RelationshipDynamics::evalInboxEntry($result['item']), self::QUEUE_TABLE, $id)) {
                throw new RuntimeException('eval inbox write + job delete wrote nothing');
            }
            error_log("[RelDyn-EVAL] job {$id} {$npc}: " . json_encode($result['item']['signals'])
                . ' tags=' . implode(',', $result['item']['tags']) . ' positive=' . ($result['item']['positive_interaction'] ? 'yes' : 'no'));
            return 'queued';
        } catch (\Throwable $e) {
            $attempts = intval($row['attempts']) + 1;
            $max = max(1, intval(self::config()['max_attempts']));
            $status = $attempts >= $max ? 'dead' : 'pending';
            $msg = substr(get_class($e) . ': ' . $e->getMessage(), 0, 500);
            self::db()->fetchOne(
                'UPDATE ' . self::QUEUE_TABLE . ' SET attempts = $2, status = $3, last_error = $4 WHERE id = $1 RETURNING id',
                [$id, $attempts, $status, $msg]
            );
            error_log("[RelDyn-EVAL] ERROR job {$id} for {$npc} attempt {$attempts}/{$max}" . ($status === 'dead' ? ' (dead-lettered)' : '') . ": {$msg}");
            return $status === 'dead' ? 'dead' : 'failed';
        }
    }

    /** Delete a dropped job; logged when nothing was deleted (the job is then seen again). */
    private static function deleteJob(int $id): bool
    {
        $row = self::db()->fetchOne('DELETE FROM ' . self::QUEUE_TABLE . ' WHERE id = $1 RETURNING id', [$id]);
        if (!isset($row['id'])) {
            error_log("[RelDyn-EVAL] ERROR job {$id}: delete removed nothing; the job stays queued");
            return false;
        }
        return true;
    }

    /**
     * Evaluate one job: fresh state, eventlog window, one LLM call, strict parse.
     *
     * @return array ['item' => contract item] or ['drop' => reason] (already logged).
     * @throws \Throwable on LLM/transport failure (the job is retried)
     */
    public static function evaluateJob(array $job, callable $llm): array
    {
        $npc = (string) $job['npc'];
        $player = is_string($job['player_name'] ?? null) && $job['player_name'] !== '' ? $job['player_name'] : self::playerName();
        $anchor = isset($job['anchor_rowid']) && is_numeric($job['anchor_rowid']) ? intval($job['anchor_rowid']) : null;
        $cfg = self::config();

        if ($anchor === null || !self::eventlogRowExists($anchor)) {
            error_log("[RelDyn-EVAL] job for {$npc} dropped: its exchange is no longer in eventlog (save load rolled it back)");
            return ['drop' => 'rolled_back'];
        }

        $scoredThrough = isset($job['scored_through_rowid']) && is_numeric($job['scored_through_rowid']) ? intval($job['scored_through_rowid']) : null;
        $window = self::conversationWindow($npc, $player, $anchor, intval($cfg['window_lines']), intval($cfg['scan_rows']), $scoredThrough);
        if (empty($window['current'])) {
            error_log("[RelDyn-EVAL] job for {$npc} dropped: no reply from {$npc} in the conversation window");
            return ['drop' => 'no_reply'];
        }

        $dynamics = RelationshipDynamics::getDynamics($npc);   // fresh read, no cache
        $messages = self::buildMessages($npc, $player, $window, self::stateSummary($npc, $dynamics), (array) ($job['event_tags'] ?? []));
        $raw = $llm($messages, ['MAX_TOKENS' => intval($cfg['max_tokens'])]);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('eval LLM returned no response');
        }

        $reason = null;
        $item = self::parseResponse($raw, $job, $reason);
        if ($item === null) {
            error_log("[RelDyn-EVAL] ERROR malformed eval output for {$npc} dropped ({$reason}): " . substr(str_replace("\n", ' ', $raw), 0, 300));
            return ['drop' => 'malformed'];
        }
        return ['item' => $item];
    }

    private static function eventlogRowExists(int $rowid): bool
    {
        $row = self::db()->fetchOne('SELECT 1 AS present FROM eventlog WHERE rowid = $1 LIMIT 1', [$rowid]);
        return isset($row['present']);
    }

    /** Default LLM call: the eval connector through core's LLMConnector, globals swapped back after. */
    public static function defaultLlm(): callable
    {
        return static function (array $messages, array $params) {
            $id = self::connectorId(self::config());
            if ($id <= 0) {
                throw new RuntimeException('no eval connector configured (eval_producer.connector_id / RELLLM_CONNECTOR)');
            }
            require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';
            $lc = new LLMConnector();
            $conn = $lc->readOne($id);
            if (!is_array($conn) || empty($conn['driver'])) {
                throw new RuntimeException("eval connector {$id} not found");
            }
            $driver = $lc->getConnector($conn);
            $saved = $GLOBALS['CONNECTOR'] ?? null;
            try {
                $lc->setOldGlobals($conn);
                return $driver->fast_request($messages, $params, 'reldyn_eval');
            } finally {
                if ($saved !== null) {
                    $GLOBALS['CONNECTOR'] = $saved;
                }
            }
        };
    }

    // =========================================================================
    // EVAL INPUT: conversation window
    // =========================================================================

    /**
     * Parse one eventlog dialogue row into speaker / text / listener.
     *
     * Rows look like "Name: text (talking to X)" (chat) or "Name: text (Talking to X)"
     * (player input), sometimes with a "(Context location: ...)" group in front. Names may be
     * multi-word and non-ASCII ("Aela the Huntress", "J'zargo", "Þórr"); everything before the
     * first ':' outside those groups is the speaker. Player input rows are the player's by type.
     *
     * @return array{role:string,speaker:string,text:string,listener:?string}|null
     */
    public static function parseDialogueRow(string $type, string $data, string $npcName, string $playerName): ?array
    {
        $s = preg_replace('/\((?:Context (?:new )?location)[^)]*\)/iu', ' ', $data) ?? $data;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return null;
        }
        $listener = null;
        if (preg_match('/\s*\((?:talking to|speaking loudly to|speaking privately to|whispering to)\s+(.+?)(?:\s+from far away)?\)\s*$/iu', $s, $m, PREG_OFFSET_CAPTURE)) {
            $listener = trim($m[1][0]);
            $s = trim(substr($s, 0, $m[0][1]));
        }
        $speaker = '';
        $text = $s;
        if (preg_match('/^([^:]{1,80}):\s*(.*)$/su', $s, $m)) {
            $speaker = trim($m[1]);
            $text = trim($m[2]);
        }
        if ($text === '') {
            return null;
        }
        $isInput = in_array(strtolower($type), self::PLAYER_INPUT_TYPES, true);
        if ($isInput) {
            $role = 'player';
            $speaker = $playerName;
        } elseif (self::sameName($speaker, $npcName)) {
            $role = 'npc';
            $speaker = $npcName;
        } elseif (self::sameName($speaker, $playerName)) {
            $role = 'player';
            $speaker = $playerName;
        } else {
            $role = 'other';
        }
        return ['role' => $role, 'speaker' => $speaker, 'text' => $text, 'listener' => $listener];
    }

    /**
     * The conversation between this NPC and the player up to $anchorRowid: chat + player input
     * rows only (prechat duplicates never read), speakers parsed, consecutive duplicates
     * removed, last $windowLines lines. 'current' is the exchange being scored (the NPC's
     * trailing reply and the player line right before it), 'earlier' is context. Lines at or
     * before $scoredThroughRowid (the previous job's anchor) were scored already and are
     * never 'current': an NPC follow-up without a new player line is scored on its own.
     *
     * @return array{earlier: list<array>, current: list<array>} lines carry their eventlog rowid
     */
    public static function conversationWindow(string $npcName, string $playerName, int $anchorRowid, int $windowLines, int $scanRows, ?int $scoredThroughRowid = null): array
    {
        $types = '{' . implode(',', self::WINDOW_ROW_TYPES) . '}';
        $states = '{' . implode(',', self::VISIBLE_CHAT_STATES) . '}';
        $row = self::db()->fetchOne(
            "SELECT coalesce(json_agg(r ORDER BY r.rowid), '[]'::json)::text AS rows FROM (
                 SELECT rowid, type, data, people FROM eventlog
                 WHERE rowid <= \$1 AND type = ANY(\$2::text[])
                   AND (type <> 'chat' OR delivery_state IS NULL OR delivery_state = ANY(\$3::text[]))
                 ORDER BY rowid DESC LIMIT \$4
             ) r",
            [$anchorRowid, $types, $states, max(1, $scanRows)]
        );
        $rows = json_decode((string) ($row['rows'] ?? '[]'), true);
        if (!is_array($rows)) {
            throw new RuntimeException('RelDynEval: eventlog window query failed');
        }

        $lines = [];
        foreach ($rows as $r) {
            $line = self::parseDialogueRow((string) $r['type'], (string) $r['data'], $npcName, $playerName);
            if ($line === null || !self::lineInvolves($line, (string) ($r['people'] ?? ''), $npcName)) {
                continue;
            }
            $prev = end($lines);
            if ($prev !== false && $prev['role'] === $line['role'] && mb_strtolower($prev['speaker']) === mb_strtolower($line['speaker'])
                && self::normText($prev['text']) === self::normText($line['text'])) {
                continue;   // the same line logged twice
            }
            $line['rowid'] = intval($r['rowid']);
            $lines[] = $line;
        }
        $lines = array_slice($lines, -max(2, $windowLines));

        $i = count($lines) - 1;
        while ($i >= 0 && $lines[$i]['role'] === 'npc') {
            $i--;
        }
        if ($i === count($lines) - 1) {
            return ['earlier' => $lines, 'current' => []];   // the NPC has not replied yet
        }
        $start = ($i >= 0 && $lines[$i]['role'] === 'player') ? $i : $i + 1;
        if ($scoredThroughRowid !== null) {
            while ($start < count($lines) && $lines[$start]['rowid'] <= $scoredThroughRowid) {
                $start++;   // scored by the previous job
            }
        }
        return ['earlier' => array_slice($lines, 0, $start), 'current' => array_slice($lines, $start)];
    }

    private static function lineInvolves(array $line, string $people, string $npcName): bool
    {
        if ($line['role'] === 'npc') {
            return true;
        }
        // "(Talking to everyone)" addresses whoever is present: decided by the people column below.
        $toAll = $line['listener'] !== null && in_array(mb_strtolower($line['listener']), ['everyone', 'all'], true);
        if ($line['listener'] !== null && !$toAll) {
            foreach (preg_split('/\s*(?:,|&|\band\b)\s*/iu', $line['listener']) ?: [] as $part) {
                if (self::sameName(trim($part), $npcName)) {
                    return true;
                }
            }
            return false;
        }
        // No addressee: a player line counts when the NPC was present (people is "|A|B|").
        if ($line['role'] === 'player') {
            foreach (explode('|', $people) as $p) {
                $p = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $p) ?? $p);
                if ($p !== '' && self::sameName($p, $npcName)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normText(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    private static function sameName(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        return $a !== '' && $b !== '' && mb_strtolower($a) === mb_strtolower($b);
    }

    // =========================================================================
    // EVAL INPUT: state summary and prompt
    // =========================================================================

    /**
     * Compact state in words (bands, never raw numbers): tier, type, temperament, attachment,
     * maturity / trust / comfort bands, passion, jealousy and resentment bands, current mood.
     */
    public static function stateSummary(string $npcName, array $dynamics): array
    {
        $dimX = static fn(string $dim) => floatval($dynamics['dimensions'][$dim]['x'] ?? 50);
        $band = static function (string $dim, float $x): string {
            $b = RelationshipDynamics::getDimensionBand($dim, $x);
            return $b ? $b['label'] . ($b['keywords'] !== '' ? " ({$b['keywords']})" : '') : 'unknown';
        };
        $tier = RelationshipDynamics::getCurrentTier(RelationshipDynamics::getCoreAffinity($dynamics));
        $type = RelationshipDynamics::getRelationshipType($npcName, $dynamics);
        $jealousy = floatval($dynamics['jealousy_anger'] ?? 0);   // 0..jealousy_max points
        $jealousyBand = RelationshipDynamics::getJealousyBand($jealousy);
        $rival = is_string($dynamics['jealousy_trigger_npc'] ?? null) ? $dynamics['jealousy_trigger_npc'] : null;

        $lines = [
            'Bond with the player: ' . str_replace('_', ' ', $tier) . ($type ? ", {$type}" : ''),
            'Temperament: ' . ($dynamics['inferred_temperament'] ?? 'unknown')
                . '; attachment: ' . RelationshipDynamics::getAttachmentStyle($dynamics),
            'Maturity: ' . $band('maturity', $dimX('maturity')),
            'Trust in the player: ' . $band('trust', $dimX('trust')),
            'Comfort with the player: ' . $band('comfort', $dimX('comfort')),
            'Passion: ' . RelationshipDynamics::getPassionBand(RelationshipDynamics::getPassion($dynamics)),
            'Jealousy: ' . $jealousyBand . ($jealousyBand !== 'none' && $rival ? " (about {$rival})" : ''),
            'Resentment: ' . $band('resentment', floatval($dynamics['dimensions']['resentment']['x'] ?? 0)),
        ];
        $mood = self::currentMood($npcName);
        if ($mood !== null) {
            $lines[] = "Current mood: {$mood}";
        }
        return $lines;
    }

    private static function currentMood(string $npcName): ?string
    {
        $row = self::db()->fetchOne(
            "SELECT mood FROM moods_issued WHERE lower(speaker) = lower(\$1) ORDER BY localts DESC LIMIT 1",
            [$npcName]
        );
        $mood = is_string($row['mood'] ?? null) ? trim($row['mood']) : '';
        return $mood !== '' ? $mood : null;
    }

    /** @return list<array{role:string,content:string}> system + user messages for the eval LLM */
    public static function buildMessages(string $npc, string $player, array $window, array $state, array $eventTags): array
    {
        $fmt = static function (array $lines): string {
            $out = [];
            foreach ($lines as $l) {
                $to = $l['listener'] !== null ? " (to {$l['listener']})" : '';
                $out[] = "[{$l['speaker']}]{$to}: {$l['text']}";
            }
            return implode("\n", $out);
        };
        $tagDefs = [];
        foreach (self::TAG_DEFINITIONS as $tag => $def) {
            $tagDefs[] = "- {$tag}: {$def}";
        }
        $signalSpec = [];
        foreach (self::SIGNAL_LIMITS as $sig => $lim) {
            $signalSpec[] = "\"{$sig}\": int -{$lim}..{$lim}";
        }
        $earlier = empty($window['earlier']) ? '(none)' : $fmt($window['earlier']);
        $current = $fmt($window['current']);
        $events = empty($eventTags) ? '' : "\nObserved events in this exchange (already certain): " . implode(', ', $eventTags) . "\n";
        $stateText = implode("\n", array_map(static fn($s) => "- {$s}", $state));
        $tagText = implode("\n", $tagDefs);
        $signalText = implode(', ', $signalSpec);

        $system = "You score how one exchange changed a Skyrim character's feelings toward the player. "
            . "You judge from the character's point of view: what they actually felt, which can differ from what they said. "
            . "You answer with one JSON object and nothing else.";

        $user = <<<PROMPT
CHARACTER: {$npc} (whose feelings you score)
PLAYER: {$player}

{$npc}'S CURRENT STATE:
{$stateText}

EARLIER CONVERSATION (context only, already scored, do not score it again):
{$earlier}

THIS EXCHANGE (score only this):
{$current}
{$events}
TASK: How did THIS EXCHANGE change {$npc}'s feelings toward {$player}? Consider {$npc}'s state and temperament: the same words land differently on different people.

SIGNALS (raw change, 0 = no change):
- affinity: do they like {$player} more or less
- trust: can they rely on {$player}
- comfort: can they be themselves around {$player}
- respect: do they value {$player}
- passion: romantic or intense attraction
- maturity: how maturely {$npc} handled their own feelings here (not about {$player})
Scale: most exchanges 0 to 3; a meaningful moment 5 to 10; a major one (rescue, betrayal, confession) up to 30. Maturity at most 10.

TAGS (what {$player} did; use only these, empty list if none apply):
{$tagText}

GRIEVANCE: flag true when {$npc} was hurt or wronged and it was not resolved in this exchange; kind = short word (e.g. insult, neglect, disrespect, being used); severity 1 mild .. 3 severe.
JEALOUSY: flag true when {$npc} felt jealous of a rival because of this exchange; rival = the rival's name; intensity 1..3.
SIGNIFICANCE: 0..1, how much this exchange matters to {$npc} (small talk 0.1, meaningful 0.5, life-changing 1).

Reply with exactly this JSON shape:
{"signals": {{$signalText}}, "tags": [], "grievance": {"flag": false, "kind": null, "severity": 0}, "jealousy": {"flag": false, "rival": null, "intensity": 0}, "significance": 0.2, "summary": "one short line"}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    // =========================================================================
    // EVAL OUTPUT: strict parse into the contract
    // =========================================================================

    /**
     * Validate the LLM reply into a contract item. Numbers are clamped, unknown tags dropped,
     * missing fields defaulted; a reply that is not one JSON object, or has a field of the
     * wrong type, is malformed: returns null and sets $reason.
     */
    public static function parseResponse(string $raw, array $job, ?string &$reason = null): ?array
    {
        $reason = null;
        $text = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $text, $m)) {
            $text = $m[1];
        }
        $data = json_decode($text, true, 16);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $reason = 'not a JSON object';
            return null;
        }

        if (!isset($data['signals']) || !is_array($data['signals']) || ($data['signals'] !== [] && array_is_list($data['signals']))) {
            $reason = 'signals missing or not an object';
            return null;
        }
        $signals = [];
        foreach (self::SIGNAL_LIMITS as $sig => $lim) {
            $v = $data['signals'][$sig] ?? 0;
            if (!is_int($v) && !is_float($v)) {
                $reason = "signal {$sig} is not a number";
                return null;
            }
            $signals[$sig] = (int) max(-$lim, min($lim, round($v)));
        }

        $tags = [];
        if (array_key_exists('tags', $data)) {
            if (!is_array($data['tags']) || !array_is_list($data['tags'])) {
                $reason = 'tags is not a list';
                return null;
            }
            foreach ($data['tags'] as $t) {
                if (!is_string($t)) {
                    $reason = 'tag is not a string';
                    return null;
                }
                $t = strtolower(trim($t));
                if (isset(self::TAG_DEFINITIONS[$t])) {
                    $tags[$t] = true;   // unknown tags are dropped
                }
            }
        }

        $npc = (string) ($job['npc'] ?? '');
        $player = (string) ($job['player_name'] ?? '');
        $grievance = self::parseFlagObject($data, 'grievance', 'kind', 'severity', $reason);
        if ($grievance === null) {
            return null;
        }
        $jealousy = self::parseFlagObject($data, 'jealousy', 'rival', 'intensity', $reason);
        if ($jealousy === null) {
            return null;
        }
        if ($jealousy['rival'] !== null && (self::sameName($jealousy['rival'], $npc) || self::sameName($jealousy['rival'], $player))) {
            $jealousy['rival'] = null;   // the rival is a third person
        }

        $significance = self::DEFAULT_SIGNIFICANCE;
        if (array_key_exists('significance', $data)) {
            if (!is_int($data['significance']) && !is_float($data['significance'])) {
                $reason = 'significance is not a number';
                return null;
            }
            $significance = max(0.0, min(1.0, (float) $data['significance']));
        }
        $summary = '';
        if (array_key_exists('summary', $data) && $data['summary'] !== null) {
            if (!is_string($data['summary'])) {
                $reason = 'summary is not a string';
                return null;
            }
            $summary = mb_substr(trim(preg_replace('/\s+/u', ' ', $data['summary']) ?? ''), 0, self::SUMMARY_MAX_CHARS);
        }

        foreach ((array) ($job['event_tags'] ?? []) as $t) {
            if (is_string($t) && isset(self::TAG_DEFINITIONS[$t])) {
                $tags[$t] = true;
            }
        }
        $classified = self::classify($signals, array_keys($tags), $grievance, $jealousy);

        return [
            'v'                    => self::CONTRACT_VERSION,
            'npc'                  => $npc,
            'npc_id'               => intval($job['npc_id'] ?? 0),
            'gamets'               => intval($job['gamets'] ?? 0),
            'source'               => self::SOURCE,
            'signals'              => $signals,
            'tags'                 => $classified['tags'],
            'grievance'            => $classified['grievance'],
            'jealousy'             => $classified['jealousy'],
            'significance'         => round($significance, 3),
            'positive_interaction' => $classified['positive_interaction'],
            'summary'              => $summary,
        ];
    }

    /** grievance / jealousy object: {flag bool, <textKey> string|null, <levelKey> 0..3}; null = malformed. */
    private static function parseFlagObject(array $data, string $key, string $textKey, string $levelKey, ?string &$reason): ?array
    {
        $out = ['flag' => false, $textKey => null, $levelKey => 0];
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return $out;
        }
        $o = $data[$key];
        if (!is_array($o) || ($o !== [] && array_is_list($o))) {
            $reason = "{$key} is not an object";
            return null;
        }
        $flag = $o['flag'] ?? false;
        if ($flag === 0 || $flag === 1) {
            $flag = (bool) $flag;
        }
        if (!is_bool($flag)) {
            $reason = "{$key}.flag is not a boolean";
            return null;
        }
        $text = $o[$textKey] ?? null;
        if ($text !== null && !is_string($text)) {
            $reason = "{$key}.{$textKey} is not a string";
            return null;
        }
        $level = $o[$levelKey] ?? 0;
        if (!is_int($level) && !is_float($level)) {
            $reason = "{$key}.{$levelKey} is not a number";
            return null;
        }
        if (!$flag) {
            return $out;
        }
        $text = $text === null ? '' : trim($text);
        $maxChars = $textKey === 'kind' ? self::KIND_MAX_CHARS : 80;
        return [
            'flag' => true,
            $textKey => $text === '' ? null : mb_substr($textKey === 'kind' ? mb_strtolower($text) : $text, 0, $maxChars),
            $levelKey => (int) max(1, min(self::LEVEL_MAX, round($level))),
        ];
    }

    // =========================================================================
    // INTERACTION CLASSIFICATION (one source)
    // =========================================================================

    /**
     * Make tags, grievance, jealousy and positive_interaction agree:
     *   - jealousy.flag <=> tag jealousy_trigger
     *   - a grievance whose kind is a negative tag carries that tag
     *   - positive_interaction = no grievance, no negative tag, and the player-facing signals
     *     (affinity, trust, comfort, respect, passion) sum above 0 with affinity not negative.
     */
    public static function classify(array $signals, array $tags, array $grievance, array $jealousy): array
    {
        $set = [];
        foreach ($tags as $t) {
            if (is_string($t) && isset(self::TAG_DEFINITIONS[$t])) {
                $set[$t] = true;
            }
        }
        if (!empty($jealousy['flag'])) {
            $set['jealousy_trigger'] = true;
        } elseif (isset($set['jealousy_trigger'])) {
            $jealousy = ['flag' => true, 'rival' => $jealousy['rival'] ?? null, 'intensity' => max(1, intval($jealousy['intensity'] ?? 0))];
        }
        if (!empty($grievance['flag']) && is_string($grievance['kind'] ?? null) && in_array($grievance['kind'], self::NEGATIVE_TAGS, true)) {
            $set[$grievance['kind']] = true;
        }

        $sum = 0;
        foreach (self::POSITIVE_SIGNALS as $sig) {
            $sum += intval($signals[$sig] ?? 0);
        }
        $negativeTag = array_intersect(array_keys($set), self::NEGATIVE_TAGS) !== [];
        $positive = empty($grievance['flag']) && !$negativeTag && $sum > 0 && intval($signals['affinity'] ?? 0) >= 0;

        // Contract order: the TAG_DEFINITIONS order, so equal sets compare equal.
        $ordered = array_values(array_filter(array_keys(self::TAG_DEFINITIONS), static fn($t) => isset($set[$t])));
        return ['tags' => $ordered, 'grievance' => $grievance, 'jealousy' => $jealousy, 'positive_interaction' => $positive];
    }

    /** Love languages (RelationshipDynamics::LL_*) the tags feed, without duplicates. */
    public static function loveLanguagesForTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $ll = self::TAG_LOVE_LANGUAGE[$t] ?? null;
            if ($ll !== null && !in_array($ll, $out, true)) {
                $out[] = $ll;
            }
        }
        return $out;
    }

    /** Tags a local love-language classification stands for ([] for null / unknown). */
    public static function tagsForLoveLanguage(?string $loveLanguage): array
    {
        $tag = $loveLanguage !== null ? (self::LOVE_LANGUAGE_TAGS[$loveLanguage] ?? null) : null;
        return $tag !== null ? [$tag] : [];
    }
}
