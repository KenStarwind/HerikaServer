<?php
/**
 * RelDyn personality traits: the bio re-ingest (decisions 2026-10-01 §21 #10 and the review
 * queue's "Next lane": bios fill in over time, CHIM's dynamic profile rewrites them).
 *
 * The first bio read (RelDynTraitRead, from the shipped template) sets an NPC's vector. A live
 * bio moves on: the dynamic profile rewrites the NPC's own bio fields in core_npc_master. Every
 * `every_game_days` (default 90) of game calendar, or soon after a milestone (a romance
 * promotion, a bond break, a betrayal, a marriage), this module checks whether the live bio
 * text (the same six fields the first read sends, `core` never) still hashes to the text the
 * vector was read from (RelDynTraitRead::srcHash). Only a changed bio queues a re-read, through
 * the existing trait-read worker (reldyn_trait_reads, key `live:<npc name>`, so a template's
 * cached rows are never touched). No change, no model call.
 *
 * A finished re-read does not replace the vector, it moves it. For every trait the new read has
 * evidence for, the vector goes a fraction k of the way to what the new read would have given:
 *   k = clamp(blend_base x plasticity x maturity, floor, ceiling)
 * Plasticity is the mean of the NPC's maturity Y_up / Y_down (a Rigid NPC 0.3, Adaptive 1.0,
 * Volatile 1.5), maturity a mild factor (1.2 at maturity 0, 0.8 at 100): set-in-their-ways
 * NPCs move less. The floor keeps k above zero: nobody is fully immune to a changed bio. A
 * trait the vector knew nothing about (no evidence in the read it rests on) moves by
 * k + (1 - k)(1 - known): inertia belongs to an established personality, not to ignorance
 * ("unknown traits shouldn't bias; bios fill in over time"). A trait the new read has no
 * evidence for does not move (no evidence is not a vote for the prior).
 *
 * The result is kept as `drift` (storage trait name => offset, plus maturity_start), applied by
 * RelDynTraitAssign::resolve() on top of prior + first read, so every later resolution (a
 * priors fix, a version bump) keeps it. The read the vector rested on before goes into a short
 * history (`history`, newest last, config `history` entries).
 *
 * Exempt (never re-read, no drift): a skip-listed NPC and one with a hand-set vector (Ashe), a
 * config / Sharmat / editor preset (the preset point is the vector), every trait set in the
 * editor. A partial per-trait editor override is kept (the override wins per trait) and the
 * other traits still move.
 *
 * State (dynamics `_bio_reingest`): last_read (game calendar of the read the vector rests on),
 * last_check, base_hash, anchor (the template hash the drift is relative to; a changed shipped
 * template starts the NPC over from the new read), seen (milestone baselines), milestone,
 * pending {hash, since, reason, forced}, drift, known, current, history, reads, last_error.
 *
 * Config trait_reader.reingest: enabled, every_game_days, min_gap_days, milestones (kinds),
 * blend_base, floor, ceiling, history. Off: nothing is checked, queued or applied.
 *
 * Units: game calendar = raw gamets (RelationshipDynamics::GAMETS_PER_DAY per day); traits
 * 0..1, maturity_start 0..100; k 0..1.
 */

final class RelDynTraitReingest
{
    const KEY = '_bio_reingest';

    /** Queue key prefix: the NPC's live bio, apart from any template row. */
    const LIVE_PREFIX = 'live:';

    const MILESTONE_KINDS = ['romance', 'bond_break', 'betrayal', 'marriage'];

    public static function defaultConfig(): array
    {
        return [
            'enabled'         => true,
            'every_game_days' => 90,     // cadence of the bio check
            'min_gap_days'    => 7,      // a milestone never checks sooner than this after the last check
            'milestones'      => self::MILESTONE_KINDS,
            'blend_base'      => 0.5,    // the share of the way a new read moves an average NPC
            'floor'           => 0.08,   // nobody is immune: the least any evidenced trait moves
            'ceiling'         => 0.85,   // nobody is a blank page either
            'history'         => 3,      // superseded reads kept
        ];
    }

    /** trait_reader.reingest over its defaults, read fresh. */
    public static function config(): array
    {
        return RelDynTraitRead::reingestConfig();
    }

    /** The feature switch: its own, under the trait reader's. */
    public static function enabled(?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled']) || empty(RelDynTraitRead::config()['enabled'])) return false;
        return !(class_exists('RelationshipDynamics') && !RelationshipDynamics::isEnabled());
    }

    // =========================================================================
    // KEYS AND THE LIVE BIO
    // =========================================================================

    public static function isLiveKey(string $key): bool
    {
        return strncmp($key, self::LIVE_PREFIX, strlen(self::LIVE_PREFIX)) === 0;
    }

    public static function baseKey(string $key): string
    {
        return self::isLiveKey($key) ? substr($key, strlen(self::LIVE_PREFIX)) : $key;
    }

    public static function liveKey(string $npcName): string
    {
        return self::LIVE_PREFIX . strtolower(trim($npcName));
    }

    /**
     * The NPC's live bio: ['npc' => name as stored, 'gender' => female|male|null, 'fields' => the six
     * read fields], or null (no such NPC). `core` is never selected. Throws on a DB error.
     */
    public static function liveBio(string $npcName, $db = null): ?array
    {
        $db = $db ?? ($GLOBALS['db'] ?? null);
        $npcName = trim($npcName);
        if (!$db || $npcName === '') return null;
        $row = $db->fetchOne('SELECT npc_name, gender, ' . implode(', ', RelDynTraitRead::FIELDS)
            . ' FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1', [$npcName]);
        if (!is_array($row) || !isset($row['npc_name'])) return null;
        $g = strtolower(trim((string) ($row['gender'] ?? '')));
        return ['npc' => (string) $row['npc_name'], 'gender' => in_array($g, ['female', 'male'], true) ? $g : null,
                'fields' => RelDynTraitRead::fieldsOf($row)];
    }

    /** What a queued live row reads: liveBio of the key's NPC (the worker, RelDynTraitRead::processRow). */
    public static function sourceFor(string $liveKey, $db = null): ?array
    {
        return self::liveBio(self::baseKey($liveKey), $db);
    }

    // =========================================================================
    // STATE
    // =========================================================================

    public static function state(array $dynamics): array
    {
        $s = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        return $s + ['v' => 1, 'last_read' => null, 'last_check' => null, 'last_queued' => null, 'base_hash' => null,
            'anchor' => null, 'seen' => [], 'milestone' => null, 'pending' => null, 'drift' => [], 'known' => [],
            'current' => null, 'history' => [], 'reads' => 0, 'last_moved' => [], 'last_error' => null];
    }

    /** Game calendar now (raw gamets): the game clock, else the NPC's last stamp. */
    public static function now(array $dynamics): float
    {
        $now = class_exists('RelationshipDynamics') ? RelationshipDynamics::currentGamets() : 0.0;
        return $now > 0 ? $now : floatval($dynamics['_last_gamets'] ?? 0);
    }

    private static function gametsPerDay(): float
    {
        return class_exists('RelationshipDynamics') ? floatval(RelationshipDynamics::GAMETS_PER_DAY) : 10000000.0;
    }

    /** The drift input of RelDynTraitAssign::resolve() ('known' travels with it for the provenance). */
    public static function driftInput(array $state): ?array
    {
        return $state['drift'] !== [] ? ['x' => $state['drift'], 'known' => $state['known']] : null;
    }

    // =========================================================================
    // EXEMPTION
    // =========================================================================

    /**
     * Why an NPC is never re-read (null = it may be): skip-listed, a hand-set / config / Sharmat /
     * editor preset, every trait set in the editor, or not on the read assignment at all.
     */
    public static function exemption(string $npcName, array $dynamics): ?string
    {
        $src = is_array($dynamics['_trait_vector_src'] ?? null) ? $dynamics['_trait_vector_src'] : null;
        $keys = RelDynTraitRead::keyCandidates($npcName);
        if (is_string($src['template_key'] ?? null)) $keys[] = $src['template_key'];
        foreach ($keys as $k) {
            if (RelDynTraitRead::isSkipped($k)) return 'on the read skip list: the vector is hand-set';
        }
        if (class_exists('RelDynTraits') && RelDynTraits::assignment() !== 'read') return 'the read assignment is off';
        if ($src === null || ($src['assignment'] ?? null) !== 'read') return 'no read vector yet';
        $as = (string) ($src['auto_source'] ?? '');
        if ($as === 'hand-set') return 'a hand-set vector';
        if (in_array($as, ['preset', 'sharmat'], true)) return 'a preset sets the vector';
        $over = (array) ($dynamics['profile_overrides'] ?? []);
        if (isset($over['temperament']) || in_array((string) ($src['composed'] ?? 'auto'), ['override', 'stored'], true)) {
            return 'a preset is set in the editor';
        }
        $tv = class_exists('RelDynTraits') ? RelDynTraits::validOverride($over['trait_vector'] ?? null) : null;
        if ($tv !== null && count($tv) >= count(RelDynTraits::TRAITS)) return 'every trait is set in the editor';
        return null;
    }

    // =========================================================================
    // MILESTONES
    // =========================================================================

    /** Mark a milestone (betrayal hook): the next prerequest checks the bio soon. Pure state. */
    public static function noteMilestone(array &$dynamics, string $kind, float $gamets): void
    {
        if (!in_array($kind, self::MILESTONE_KINDS, true)) return;
        $s = self::state($dynamics);
        $s['milestone'] = ['kind' => $kind, 'gamets' => $gamets];
        $dynamics[self::KEY] = $s;
    }

    /**
     * The milestone the dynamics show since the last look ($seen holds the baselines and is
     * updated): a romance promotion (_romance.last_promotion), a bond break (_bond_break.count),
     * a betrayal (the core type 'betrayed') or a marriage (the core type turning 'romantic'
     * by any path). The first look only sets the baselines.
     */
    public static function detectMilestone(array $dynamics, array &$seen, array $cfg): ?string
    {
        $first = $seen === [];
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $promo = floatval($dynamics['_romance']['last_promotion']['gamets'] ?? 0);
        $breaks = intval($dynamics['_bond_break']['count'] ?? 0);
        $found = null;
        if (!$first) {
            if ($promo > floatval($seen['romance_at'] ?? 0)) $found = 'romance';
            elseif ($type === 'romantic' && ($seen['core_type'] ?? '') !== 'romantic' && ($seen['core_type'] ?? '') !== '') $found = 'marriage';
            if ($breaks > intval($seen['break_count'] ?? 0)) $found = $found ?? 'bond_break';
            if ($type === 'betrayed' && ($seen['core_type'] ?? '') !== 'betrayed') $found = $found ?? 'betrayal';
        }
        $seen = ['core_type' => $type, 'romance_at' => $promo, 'break_count' => $breaks];
        return ($found !== null && in_array($found, (array) ($cfg['milestones'] ?? []), true)) ? $found : null;
    }

    // =========================================================================
    // THE CHECK (prerequest)
    // =========================================================================

    /**
     * One look at an NPC's bio, on the cadence or after a milestone. Pure bookkeeping plus one
     * SELECT of the live bio and, on a change, one queue row; never a model call. Returns true
     * when the state changed (the caller saves). Never throws for a DB problem: logged, retried
     * on the next request.
     */
    public static function onPrerequest(string $npcName, array &$dynamics): bool
    {
        $cfg = self::config();
        if (!self::enabled($cfg)) return false;
        $now = self::now($dynamics);
        if ($now <= 0) return false;
        $src = $dynamics['_trait_vector_src'] ?? null;
        if (!is_array($src) || ($src['assignment'] ?? null) !== 'read') return false;
        $had = array_key_exists(self::KEY, $dynamics);
        $before = $dynamics[self::KEY] ?? null;
        $s = self::state($dynamics);
        if (self::exemption($npcName, $dynamics) !== null) {
            // nothing is read, queued or kept for an exempt NPC: a pending read and a milestone mark are dropped,
            // and a state with no drift or history behind it goes away (a hand-set vector never carries one)
            if (!$had) return false;
            $s['pending'] = null;
            $s['milestone'] = null;
            if ($s['drift'] === [] && $s['history'] === [] && intval($s['reads']) === 0) unset($dynamics[self::KEY]);
            else $dynamics[self::KEY] = $s;
            return true;
        }
        if (($src['read_status'] ?? null) !== 'done') return false;   // the first read has not landed

        if ($s['last_read'] === null) $s['last_read'] = $now;
        if ($s['anchor'] === null && is_string($src['src_hash'] ?? null)) $s['anchor'] = $src['src_hash'];
        $milestone = self::detectMilestone($dynamics, $s['seen'], $cfg);
        if ($milestone !== null && !is_array($s['milestone'])) $s['milestone'] = ['kind' => $milestone, 'gamets' => $now];
        if ($s['last_check'] === null) $s['last_check'] = $now;   // the clock starts at first sight

        $dayGamets = self::gametsPerDay();
        $sinceCheck = $now - floatval($s['last_check']);
        $due = null;
        if ($s['pending'] === null) {
            if (is_array($s['milestone']) && in_array((string) ($s['milestone']['kind'] ?? ''), (array) $cfg['milestones'], true)
                && $sinceCheck >= max(0.0, floatval($cfg['min_gap_days'])) * $dayGamets) {
                $due = 'milestone: ' . (string) $s['milestone']['kind'];
            } elseif ($sinceCheck >= max(1.0, floatval($cfg['every_game_days'])) * $dayGamets) {
                $due = 'cadence';
            }
        }
        if ($due !== null) {
            try {
                self::check($npcName, $s, $src, $now, $due);
            } catch (\Throwable $e) {
                error_log('[RelDyn-TRAITS] ERROR bio re-ingest check for ' . $npcName . ': ' . $e->getMessage());
            }
        }
        $dynamics[self::KEY] = $s;
        return !$had || json_encode($before) !== json_encode($s);
    }

    /**
     * Compare the live bio's hash with the one the vector rests on; a change queues one read.
     * Updates $s (last_check, pending, base_hash, milestone). Returns 'none' | 'unchanged' |
     * 'baseline' | 'queued' | 'cached' | 'pending'.
     */
    private static function check(string $npcName, array &$s, array $src, float $now, string $reason): string
    {
        $s['last_check'] = $now;
        $s['milestone'] = null;
        $bio = self::liveBio($npcName);
        if ($bio === null || !RelDynTraitRead::hasText($bio['fields'])) return 'none';
        $hash = RelDynTraitRead::srcHash($bio['fields']);
        $base = $s['base_hash'] ?? (is_string($src['src_hash'] ?? null) ? $src['src_hash'] : null);
        if ($base === null) {
            $s['base_hash'] = $hash;   // no baseline to compare with: this is it
            return 'baseline';
        }
        if ($hash === $base) return 'unchanged';
        $r = self::enqueue($npcName, $hash, false);
        $s['pending'] = ['hash' => $hash, 'since' => $now, 'reason' => $reason, 'forced' => false];
        $s['last_queued'] = $now;
        if (class_exists('RelationshipDynamics')) RelationshipDynamics::log("[RelDyn-TRAITS] bio changed for {$npcName}: re-read {$r} ({$reason})");
        return $r;
    }

    /**
     * Queue (or find) the live-bio read of a hash. 'queued' (a row to work, worker asked for),
     * 'cached' (this text was read already), 'pending' (already waiting). $force re-queues even a
     * finished row; a dead row is always re-queued (a new cadence is a new chance).
     */
    public static function enqueue(string $npcName, string $hash, bool $force): string
    {
        RelDynTraitRead::ensureTable();
        $db = $GLOBALS['db'];
        $key = self::liveKey($npcName);
        $tbl = RelDynTraitRead::TABLE;
        $row = $db->fetchOne("SELECT status FROM {$tbl} WHERE template_key = \$1 AND src_hash = \$2", [$key, $hash]);
        if (isset($row['status'])) {
            $st = (string) $row['status'];
            if ($force || $st === 'dead') {
                $db->fetchOne("UPDATE {$tbl} SET status = 'pending', attempts = 0, last_error = NULL, updated = now() WHERE template_key = \$1 AND src_hash = \$2", [$key, $hash]);
                RelDynTraitRead::launchWorker();
                return 'queued';
            }
            return $st === 'done' ? 'cached' : 'pending';
        }
        $ins = $db->fetchOne("INSERT INTO {$tbl} (template_key, src_hash, prompt_v, status) VALUES (\$1, \$2, \$3, 'pending')
                              ON CONFLICT (template_key, src_hash) DO NOTHING RETURNING 1 AS ins", [$key, $hash, RelDynTraitRead::PROMPT_V]);
        if (isset($ins['ins'])) RelDynTraitRead::launchWorker();
        return 'queued';
    }

    /**
     * The editor's "read again": queue a fresh read of the live bio now (even unchanged text),
     * pending until the worker has it. Returns ['ok' => bool, 'message' => string]; $dynamics gets the
     * pending mark (the caller saves).
     */
    public static function requestRead(string $npcName, array &$dynamics): array
    {
        $cfg = self::config();
        if (!self::enabled($cfg)) return ['ok' => false, 'message' => 'Bio re-reading is switched off (trait_reader.reingest.enabled).'];
        $ex = self::exemption($npcName, $dynamics);
        if ($ex !== null) return ['ok' => false, 'message' => 'This NPC is not re-read: ' . $ex . '.'];
        $src = (array) ($dynamics['_trait_vector_src'] ?? []);
        if (($src['read_status'] ?? null) !== 'done') return ['ok' => false, 'message' => 'The first bio read has not landed yet; nothing to read again.'];
        $s = self::state($dynamics);
        if ($s['pending'] !== null) return ['ok' => true, 'message' => 'A re-read is already queued.'];
        $bio = self::liveBio($npcName);
        if ($bio === null || !RelDynTraitRead::hasText($bio['fields'])) return ['ok' => false, 'message' => 'The NPC has no bio text to read.'];
        $now = self::now($dynamics);
        $hash = RelDynTraitRead::srcHash($bio['fields']);
        $r = self::enqueue($npcName, $hash, true);
        $s['pending'] = ['hash' => $hash, 'since' => $now, 'reason' => 'manual', 'forced' => true];
        $s['last_queued'] = $now;
        $s['milestone'] = null;
        $dynamics[self::KEY] = $s;
        return ['ok' => true, 'message' => 'A re-read is queued; it lands the next time the background reader runs.'];
    }

    // =========================================================================
    // SETTLING A FINISHED READ
    // =========================================================================

    private static $liveMemo = [];
    private static $liveMemoToken = null;

    /** Status (+ result, model) of a live read; 'none' without a row. Memoized per request scope. */
    public static function liveState(string $npcName, string $hash): array
    {
        $token = class_exists('RelationshipDynamics') ? RelationshipDynamics::requestScopeToken() : null;
        if ($token === null || $token !== self::$liveMemoToken) { self::$liveMemo = []; self::$liveMemoToken = $token; }
        $mk = self::liveKey($npcName) . '|' . $hash;
        if ($token !== null && isset(self::$liveMemo[$mk])) return self::$liveMemo[$mk];
        $out = ['status' => 'none', 'result' => null, 'model' => null];
        try {
            if (RelDynTraitRead::tableExists(RelDynTraitRead::TABLE)) {
                $row = ($GLOBALS['db'])->fetchOne('SELECT status, result::text AS result, model FROM ' . RelDynTraitRead::TABLE
                    . ' WHERE template_key = $1 AND src_hash = $2', [self::liveKey($npcName), $hash]);
                if (isset($row['status'])) {
                    $out['status'] = (string) $row['status'];
                    $out['model'] = $row['model'] ?? null;
                    if ($out['status'] === 'done') {
                        $res = json_decode((string) ($row['result'] ?? ''), true);
                        if (is_array($res) && is_array($res['traits'] ?? null)) $out['result'] = $res; else $out['status'] = 'dead';
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[RelDyn-TRAITS] ERROR live read lookup for ' . $npcName . ': ' . $e->getMessage());
            $out['status'] = 'error';
        }
        if ($token !== null && $out['status'] !== 'pending') self::$liveMemo[$mk] = $out;
        return $out;
    }

    /** Forget the per-request memo (tests). */
    public static function reset(): void
    {
        self::$liveMemo = [];
        self::$liveMemoToken = null;
    }

    /** True when the NPC waits on a read that is done (or dead) now: the profile is resolved again (traitProfileStale). */
    public static function settleDue(string $npcName, array $dynamics): bool
    {
        $p = self::state($dynamics)['pending'];
        if (!is_array($p) || !is_string($p['hash'] ?? null) || !self::enabled()) return false;
        return in_array(self::liveState($npcName, $p['hash'])['status'], ['done', 'dead'], true);
    }

    /** The share k of the way a new read moves this NPC, and what sized it. */
    public static function inertia(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $pf = 1.0;
        try {
            if (class_exists('RelationshipDynamics')) {
                $y = RelationshipDynamics::effectiveMaturityY($dynamics);
                $pf = (floatval($y['Y_up']) + floatval($y['Y_down'])) / 2.0;
            }
        } catch (\Throwable $e) {
            $pf = 1.0;
        }
        $m = $dynamics['dimensions']['maturity']['x'] ?? ($dynamics['dimensions']['maturity']['baseline'] ?? ($dynamics['trait_vector']['maturity_start'] ?? 50.0));
        $m = max(0.0, min(100.0, is_numeric($m) ? floatval($m) : 50.0));
        $mf = 1.2 - 0.4 * ($m / 100.0);
        $k = floatval($cfg['blend_base']) * $pf * $mf;
        $k = max(floatval($cfg['floor']), min(floatval($cfg['ceiling']), $k));
        return ['k' => $k, 'plasticity' => $pf, 'maturity' => $m];
    }

    /** value/conf pairs of a read's evidenced entries (the history keeps this, not the quotes). */
    public static function compact(?array $read): array
    {
        $out = [];
        if (!is_array($read)) return $out;
        foreach (array_merge(RelDynTraitRead::TRAIT_KEYS, ['maturity_start']) as $name) {
            $e = $name === 'maturity_start' ? ($read['maturity_start'] ?? null) : ($read['traits'][$name] ?? null);
            if (is_array($e) && floatval($e['conf'] ?? 0) > 0) $out[$name] = [round(floatval($e['value']), 3), round(floatval($e['conf']), 2)];
        }
        return $out;
    }

    /** The evidence weight a read gives each trait (RelDynTraitAssign::combine's w), storage name => 0..1. */
    public static function weights(?array $read): array
    {
        $out = [];
        foreach (self::compact($read) as $name => [$v, $c]) $out[$name] = min(1.0, $c * RelDynTraitAssign::READ_WEIGHT);
        return $out;
    }

    /**
     * Validate the state against the template the vector is read from: a changed shipped
     * template (its hash differs from the one the drift is anchored to) starts the NPC over
     * from the new first read. Returns the state to resolve with.
     */
    public static function anchored(array $s, ?string $templateHash): array
    {
        if ($templateHash === null) return $s;
        if ($s['anchor'] === null) {
            $s['anchor'] = $templateHash;
        } elseif ($s['anchor'] !== $templateHash) {
            $s = array_merge($s, ['anchor' => $templateHash, 'drift' => [], 'known' => [], 'current' => null, 'history' => [],
                'base_hash' => null, 'pending' => null, 'reads' => 0, 'last_read' => null]);
        }
        return $s;
    }

    /**
     * Fold a finished live read into the NPC's drift. $cur: the vector in effect (codes +
     * maturity_start, drift included); $priorX: the prior vector (RelDynTraitAssign::prior()['x']);
     * $templateRead: the first read (its weights are what the vector knew). Pure.
     * Returns the new state; 'moved' lists the offsets this step added.
     */
    public static function advance(array $s, array $cur, array $priorX, array $read, ?array $templateRead, float $k,
                                   float $now, string $hash, string $reason, int $historyKeep): array
    {
        $new = RelDynTraitAssign::combine($priorX, $read)['x'];
        $w1 = self::weights($read);
        $known = $s['known'];
        foreach (self::weights($templateRead) as $name => $w) $known[$name] = max(floatval($known[$name] ?? 0), $w);
        $drift = $s['drift'];
        $moved = [];
        foreach (array_merge(RelDynTraits::TRAITS, ['maturity_start' => 'maturity_start']) as $code => $name) {
            $w = floatval($w1[$name] ?? 0);
            if ($w <= 0.0) continue;
            $lo = 0.0;
            $hi = $name === 'maturity_start' ? 100.0 : 1.0;
            $x0 = floatval($cur[$code] ?? ($name === 'maturity_start' ? 50.0 : 0.5));
            $kEff = $k + (1.0 - $k) * (1.0 - min(1.0, floatval($known[$name] ?? 0)));
            $x1 = max($lo, min($hi, $x0 + $kEff * (floatval($new[$code]) - $x0)));
            $delta = round($x1 - $x0, 4);
            if (abs($delta) > 0.0) $drift[$name] = round(floatval($drift[$name] ?? 0) + $delta, 4);
            if (abs($delta) >= 0.0005) $moved[$name] = $delta;
            $known[$name] = max(floatval($known[$name] ?? 0), $w);
        }
        $old = is_array($s['current']) ? $s['current'] : ['hash' => $s['anchor'], 'at' => $s['last_read'], 'read' => self::compact($templateRead)];
        $hist = $s['history'];
        $hist[] = ['superseded' => $old, 'at' => $now, 'hash' => substr($hash, 0, 12), 'reason' => $reason, 'k' => round($k, 3), 'moved' => $moved];
        $s['history'] = array_slice($hist, -max(1, $historyKeep));
        $s['drift'] = $drift;
        $s['known'] = $known;
        $s['current'] = ['hash' => substr($hash, 0, 12), 'at' => $now, 'read' => self::compact($read)];
        $s['base_hash'] = $hash;
        $s['last_read'] = $now;
        $s['pending'] = null;
        $s['reads'] = intval($s['reads']) + 1;
        $s['last_error'] = null;
        $s['last_moved'] = $moved;
        return $s;
    }

    /**
     * Settle a pending read inside the profile resolution (RelationshipDynamics::traitAssignmentFor).
     * $resolve: fn(?array $driftInput): the RelDynTraitAssign::resolve() result with that drift.
     * Returns ['state' => new state, 'auto' => the resolve() result with the new drift] when the read
     * was done, ['state' => ..., 'auto' => null] when it died, or null when it is still pending.
     */
    public static function settle(string $npcName, array $dynamics, array $s, array $auto, ?array $templateRead, callable $resolve, float $now): ?array
    {
        $p = $s['pending'];
        if (!is_array($p) || !is_string($p['hash'] ?? null)) return null;
        $live = self::liveState($npcName, $p['hash']);
        if ($live['status'] === 'dead') {
            $s['pending'] = null;
            $s['last_error'] = 'the bio read failed';
            return ['state' => $s, 'auto' => null];
        }
        if ($live['status'] !== 'done' || !is_array($live['result'])) return null;
        $cfg = self::config();
        $in = self::inertia($dynamics, $cfg);
        $s = self::advance($s, $auto['x'], $auto['prior']['x'], $live['result'], $templateRead, $in['k'], $now, $p['hash'],
            (string) ($p['reason'] ?? 'cadence'), intval($cfg['history']));
        return ['state' => $s, 'auto' => $resolve(self::driftInput($s))];
    }

    // =========================================================================
    // THE EDITOR'S VIEW
    // =========================================================================

    /**
     * What the editor shows: when the profile was last read, a queued re-read, the exemption, the
     * history and the drift. Read-only; $now = game calendar now.
     */
    public static function describe(string $npcName, array $dynamics, float $now): array
    {
        $s = self::state($dynamics);
        $src = (array) ($dynamics['_trait_vector_src'] ?? []);
        $cfg = self::config();
        $ex = self::exemption($npcName, $dynamics);
        $status = (string) ($src['read_status'] ?? 'none');
        return [
            'enabled'     => self::enabled($cfg),
            'exempt'      => $ex,
            'read_status' => $status,
            'last_read'   => $s['last_read'] !== null ? floatval($s['last_read']) : null,
            'reads'       => intval($s['reads']),
            'last_check'  => $s['last_check'] !== null ? floatval($s['last_check']) : null,
            'pending'     => is_array($s['pending']) ? $s['pending'] : null,
            'last_error'  => $s['last_error'],
            'drift'       => $s['drift'],
            'history'     => $s['history'],
            'every_days'  => floatval($cfg['every_game_days']),
            'can_reread'  => self::enabled($cfg) && $ex === null && $status === 'done' && $s['pending'] === null,
        ];
    }
}
