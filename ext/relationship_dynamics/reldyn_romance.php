<?php
/**
 * Relationship Dynamics — romance promotion and the Sharmat handoff (rulings 2026-09-24 §9)
 *
 * "RelDyn owns romance (promotion into romantic types, gated by attraction + significant
 * interaction), then hands off to Sharmat for NSFW." CHIM's own relationship eval cannot
 * persist a romantic promotion (relationship_llm.php: the earned-romance gate and the
 * rebase guard keep romantic-leaning types player-set), so RelDyn writes it.
 *
 * PROMOTION (one rung per significant moment, never skipping a rung):
 *   core Player.type  non-romantic (ladder 'from' list) -> 'crush' -> 'romantic'
 *   Core's romantic type names (lib/relationship_manager.php TYPES; the set core's
 *   earned-romance gate and Sharmat's consent gate treat as romantic): crush, admirer,
 *   obsessed (rung 1), romantic (rung 2).
 *
 *   1. A MOMENT is an eval contract item (processEvalContractItem -> noteMoment) that is a
 *      positive interaction and either
 *        - tagged with a moment tag (intimacy, quality_time) at significance >= the
 *          moment threshold with a positive passion signal, or
 *        - tagged 'confession' (the player openly declared feelings) at significance >=
 *          the confession threshold; it weighs at least confession_weight.
 *      A SETBACK (a grievance, or a significant non-positive exchange) resets momentum.
 *   2. After the inbox is applied (applyEvalInbox -> maybePromote) the gate is checked on
 *      fresh core data. Only moments while the gate is OPEN count (attraction design:
 *      the gate opening is the beginning of progression, not the end), so momentum
 *      resets while it is closed. The gate:
 *        - romance promotion on, core relationships_locked not set (editor lock);
 *        - the current core type has a ladder step (familial, hostile, ex, custom: none);
 *        - no walkaway, open conflict, ick, resentment withdrawal or a mature boundary
 *          pending / on probation (block_states);
 *        - the preference filter (attraction preferences: aromantic, not interested, uncommitted);
 *        - core affinity at the step's RelDyn tier (MDD tiers on core units: crush at
 *          close_friend = core Fond 56, core's own romance gate; romantic at bonded 76);
 *        - attraction (attractionFor): passes, not friendzoned (MDD 6.2: never promote a
 *          friendzoned NPC; decisions §13: passes = attracted (every passion unit at its
 *          MDD bar, a balanced NPC at the bonded tier, or won over: passion climbed on the
 *          uphill to the MDD 8.1 "Friendzone limit"), no hard zero, not a bond-gated NPC
 *          before the bond; friendzoned = a label for not attracted with the sociological
 *          pillars met, no longer a passion cap; bond relief below the bonded tier eases
 *          only the passion rate, never the label), the attraction's romance axis has opened AND earned the
 *          step's type (not in blocked_types), its depth ceiling at least the step's, and
 *          for 'romantic' the sociological pillars too (MDD 2.6: visceral only = crush).
 *   3. Momentum (sum of moment weights, each 0..1 significance) must reach
 *        required = momentum_required x min(attachment mult x temperament mult, momentum_stack_cap)
 *                   (x stepback_momentum_mult after a step-back out of romance)
 *      so a Guarded or avoidant NPC needs several meaningful moments and a single
 *      significant one wins a secure NPC (attraction design: speed depends on who they
 *      are). After a deliberate step-back (fulfillment lane, mature boundary) the pattern
 *      must change consistently: more moments, never time (time does not heal).
 *   4. The step is written with RelationshipDynamics::changeCoreRelationshipType() under
 *      core's advisory lock, only if the core type is still the one checked.
 *   Demotion out of romance is the fulfillment lane's step-back, not here.
 *
 * OWNERSHIP GUARD (guardCorePromotion, prerequest): core's MODE 2 (no RELLLM connector) writes
 * #TYPE tags from the dialogue model straight into core Player.type, with no earned-romance
 * gate. A romance rung core wrote since this NPC's last request, that RelDyn did not write
 * (STORAGE_KEY_TYPE_CHANGE) and that this request's attraction does not allow (friendzoned,
 * or its effective romance level below the rung), is stepped back to the previous type under
 * core's lock; relationships_locked (the editor's manual edits) is respected. Mode 'strict'
 * steps back every such promotion RelDyn did not write, allowed by attraction or not (only
 * RelDyn's moments promote); 'attraction' (default) keeps the ones attraction allows.
 *
 * SHARMAT HANDOFF (domain split: RelDyn emotional layer, Sharmat sexual layer):
 *   - Sharmat's consent gate (common.php aiagentNsfwRelTypeSexEligible) reads core
 *     Player.type through RelationshipManager::getPlayerRelationship() against its
 *     eligible types (default romantic, crush, ex) plus core affinity floors. A promotion
 *     is therefore the handoff: Sharmat sees it with no change on its side.
 *   - Sharmat reads nothing else of RelDyn. RelDyn publishes its romantic state
 *     (publishState: plugin_extended_data.reldyn.romance) for a later Sharmat hook:
 *     type/rung, passion band, attraction pass, friendzone, walkaway, conflict, ick,
 *     withdrawal, consent_block and the effective disposition (MDD overlay on Sharmat's
 *     own arousal). RelDyn never writes Sharmat's store: its arousal (sex_disposal) is
 *     Sharmat's runtime state, only read here.
 */

final class RelDynRomance
{
    /** plugin_extended_data.reldyn key holding the published romantic state (Sharmat handoff). */
    const STORAGE_KEY_STATE = 'romance';
    /** plugin_extended_data.reldyn key holding RelDyn's last write of core Player.type. */
    const STORAGE_KEY_TYPE_CHANGE = 'core_type_change';
    const STATE_VERSION = 1;

    /** Attraction depth ceilings (attractionFor()['ceiling_tier'], null = no cap), lowest first. */
    const CEILING_ORDER = RelDynAttraction::DEPTH_TIERS;

    /** Moments/setbacks kept between an item and the promotion check (count). */
    const PENDING_MAX = 16;

    /**
     * Defaults of config 'romance_promotion'. Units: significance 0..1 (eval contract),
     * passion signal in raw eval points (-30..30), tiers are RELATIONSHIP_TIERS names on
     * core affinity, ceilings are CEILING_ORDER (attraction depth) names, multipliers unitless.
     */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // Core romantic-leaning types and their rung (0 = not romantic).
            'rungs' => ['crush' => 1, 'admirer' => 1, 'obsessed' => 1, 'romantic' => 2],
            // One step per promotion; 'from' = core types this step starts from.
            'ladder' => [
                ['to' => 'crush',
                 'from' => ['neutral', 'platonic', 'professional', 'protective', 'indebted', 'fanatical', 'mentor',
                            'student', 'servant', 'client', 'patron', 'transactional', 'grateful', 'curious', 'awed'],
                 'min_tier' => 'close_friend', 'min_ceiling' => 'friend', 'requires_sociological' => false],
                ['to' => 'romantic',
                 'from' => ['crush', 'admirer'],
                 'min_tier' => 'bonded', 'min_ceiling' => 'close_friend', 'requires_sociological' => true],
            ],
            'moment_tags' => ['intimacy', 'quality_time'],
            'moment_min_significance' => 0.5,
            'moment_min_passion_signal' => 1.0,
            'confession_tag' => 'confession',
            'confession_min_significance' => 0.3,
            'confession_weight' => 1.0,
            // A non-positive exchange at or above this significance (or any grievance) resets momentum.
            'setback_min_significance' => 0.5,
            'momentum_required' => 1.0,
            'momentum_attachment_mult' => ['anxious' => 0.5, 'avoidant' => 2.0],
            'momentum_temperament_mult' => ['Guarded' => 2.0],
            // Cap on attachment mult x temperament mult (unitless; decisions §16 #5): Guarded x2
            // with avoidant x2 was 4x, now 2.5x. Applies only upward, before the step-back mult.
            'momentum_stack_cap' => 2.5,
            'stepback_momentum_mult' => 3.0,
            // 'boundary': a mature boundary is pending or on probation (fulfillment lane)
            'block_states' => ['walkaway', 'conflict', 'ick', 'withdrawn', 'boundary'],
            // Romance types core writes without RelDyn: 'attraction' steps back the ones the
            // attraction does not allow, 'strict' all of them, false none (see the file comment)
            'guard_core_promotions' => 'attraction',
        ];
    }

    /** Stored config per key over the defaults. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('romance_promotion');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** Romantic rung of a core type (0 = not romantic). */
    public static function rung(?string $coreType, ?array $cfg = null): int
    {
        $cfg = $cfg ?? self::config();
        $t = strtolower(trim((string) $coreType));
        if (!class_exists('RelationshipManager')) {
            require_once __DIR__ . '/../../lib/relationship_manager.php';
        }
        $t = RelationshipManager::TYPE_ALIASES[$t] ?? $t;
        return intval(($cfg['rungs'] ?? [])[$t] ?? 0);
    }

    /** 'promotion', 'step_back' or 'lateral' for a core type change. */
    public static function direction(string $from, string $to): string
    {
        $a = self::rung($from);
        $b = self::rung($to);
        return $b > $a ? 'promotion' : ($b < $a ? 'step_back' : 'lateral');
    }

    // =========================================================================
    // MOMENTS (called per applied eval contract item)
    // =========================================================================

    /**
     * Record whether a normalized eval contract item (normalizeEvalContractItem) is a
     * romantic moment or a setback, for maybePromote(). Pure: only $dynamics['_romance'].
     */
    public static function noteMoment(array &$dynamics, array $item): void
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            return;
        }
        $tags = (array) ($item['tags'] ?? []);
        $sig = floatval($item['significance'] ?? 0);
        $positive = !empty($item['positive_interaction']);
        $record = null;

        if (!empty($item['grievance']['flag']) || (!$positive && $sig >= floatval($cfg['setback_min_significance']))) {
            $record = ['kind' => 'setback'];
        } elseif ($positive) {
            $confession = in_array((string) $cfg['confession_tag'], $tags, true)
                && $sig >= floatval($cfg['confession_min_significance']);
            // decisions §15: an asexual NPC's moments come through the emotional channels
            // (a physical moment is none; RelDynAttraction::channelOpen)
            $tagged = array_intersect((array) $cfg['moment_tags'], $tags) !== []
                && RelDynAttraction::channelOpen((array) ($dynamics['_attraction'] ?? []), $tags)
                && $sig >= floatval($cfg['moment_min_significance'])
                && floatval($item['signals']['passion'] ?? 0) >= floatval($cfg['moment_min_passion_signal']);
            if ($confession) {
                $record = ['kind' => 'moment', 'weight' => max($sig, floatval($cfg['confession_weight'])), 'confession' => true];
            } elseif ($tagged) {
                $record = ['kind' => 'moment', 'weight' => $sig, 'confession' => false];
            }
        }
        if ($record === null) {
            return;
        }
        $record['gamets'] = $item['gamets'] ?? null;
        $record['summary'] = substr((string) ($item['summary'] ?? ''), 0, 120);
        $pending = is_array($dynamics['_romance']['pending'] ?? null) ? array_values($dynamics['_romance']['pending']) : [];
        $pending[] = $record;
        $dynamics['_romance']['pending'] = array_slice($pending, -self::PENDING_MAX);
    }

    // =========================================================================
    // OWNERSHIP GUARD (prerequest, after the attraction update)
    // =========================================================================

    /**
     * See the file comment. $previousType is core's Player.type as of this NPC's last request
     * (the _core_rel_type snapshot before this request refreshed it).
     *
     * @return string|null the type written back, or null when nothing was stepped back
     */
    public static function guardCorePromotion(string $npcName, array &$dynamics, ?string $previousType): ?string
    {
        $cfg = self::config();
        $mode = $cfg['guard_core_promotions'] === true ? 'attraction' : $cfg['guard_core_promotions'];
        if (empty($cfg['enabled']) || !in_array($mode, ['attraction', 'strict'], true)) {
            return null;
        }
        $prev = strtolower(trim((string) $previousType));
        $now = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        if ($prev === '' || $now === '' || $prev === $now) {
            return null;
        }
        $rung = self::rung($now, $cfg);
        if ($rung <= self::rung($prev, $cfg)) {
            return null;   // not a promotion into romance
        }
        $sum = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        if ($mode === 'attraction' && empty($sum['enabled'])) {
            return null;   // the Matrix is off: it judges nothing
        }
        $npcId = RelDynStorage::resolveNpcId($npcName);
        $last = $npcId !== null ? (RelDynStorage::getAll($npcId)[self::STORAGE_KEY_TYPE_CHANGE] ?? null) : null;
        if (is_array($last) && strtolower((string) ($last['to'] ?? '')) === $now) {
            return null;   // RelDyn's own promotion
        }
        $allowed = $mode === 'attraction' && empty($sum['friendzoned']) && intval($sum['romance']['effective'] ?? 0) >= $rung;
        if ($allowed) {
            RelationshipDynamics::log("[ROMANCE] {$npcName}: core wrote {$prev} -> {$now}; attraction allows it, kept");
            return null;
        }
        $why = $mode === 'strict' ? 'strict: only RelDyn promotes'
            : (!empty($sum['friendzoned']) ? 'friendzoned' : 'romance not earned (' . ($sum['outcome'] ?? 'unattracted') . ')');
        $reason = "RelDyn owns romance: core wrote {$prev} -> {$now} without RelDyn's gate; attraction: {$why}";
        if (!RelationshipDynamics::changeCoreRelationshipType($npcName, $prev, $reason, $now)) {
            error_log("[RelDyn-ROMANCE] {$npcName}: core's {$prev} -> {$now} not allowed by attraction ({$why}), but it was not stepped back");
            return null;
        }
        $dynamics['_core_rel_type'] = $prev;
        return $prev;
    }

    // =========================================================================
    // PROMOTION (after the eval inbox is applied)
    // =========================================================================

    /**
     * Consume the moments noted since the last check and promote one rung when the gate is
     * open and momentum reaches what this NPC needs. Saves $dynamics when it consumed any.
     *
     * @return string|null the new core type, or null when nothing was promoted
     */
    public static function maybePromote(string $npcName, array &$dynamics): ?string
    {
        $pending = is_array($dynamics['_romance']['pending'] ?? null) ? array_values($dynamics['_romance']['pending']) : [];
        if ($pending === []) {
            return null;
        }
        unset($dynamics['_romance']['pending']);
        $cfg = self::config();
        $promoted = null;

        if (!empty($cfg['enabled'])) {
            $momentum = floatval($dynamics['_romance']['momentum'] ?? 0);
            $gate = null;
            foreach ($pending as $rec) {
                if (($rec['kind'] ?? '') === 'setback') {
                    if ($momentum > 0) {
                        RelationshipDynamics::log("[ROMANCE] {$npcName}: setback resets momentum " . round($momentum, 3));
                    }
                    $momentum = 0.0;
                    continue;
                }
                $gate = $gate ?? self::gate($npcName, $dynamics, $cfg);
                if (!$gate['open']) {
                    $momentum = 0.0;
                    continue;
                }
                $momentum += floatval($rec['weight'] ?? 0);
                RelationshipDynamics::log("[ROMANCE] {$npcName}: moment +" . round(floatval($rec['weight'] ?? 0), 3)
                    . (!empty($rec['confession']) ? ' (confession)' : '')
                    . " momentum " . round($momentum, 3) . "/" . round($gate['required'], 3) . " toward {$gate['to']}");
            }
            if ($gate !== null && !$gate['open']) {
                $dynamics['_romance']['last_block'] = $gate['reason'];
                RelationshipDynamics::log("[ROMANCE] {$npcName}: no promotion, gate closed: {$gate['reason']}");
            }

            if ($gate !== null && $gate['open'] && $momentum + 1e-9 >= $gate['required']) {
                $last = end($pending);
                $reason = 'romance: ' . (trim((string) ($last['summary'] ?? '')) !== '' ? $last['summary'] : 'a significant moment');
                if (RelationshipDynamics::changeCoreRelationshipType($npcName, $gate['to'], $reason, [$gate['from']])) {
                    RelationshipDynamics::setCoreRelationshipType($dynamics, $gate['to']);
                    $dynamics['_romance']['last_promotion'] = [
                        'from' => $gate['from'], 'to' => $gate['to'], 'gamets' => RelationshipDynamics::currentGamets(),
                    ];
                    unset($dynamics['_romance']['last_block']);
                    $momentum = 0.0;
                    $promoted = $gate['to'];
                } else {
                    // Not written (lock, core type changed meanwhile, write failure: logged there).
                    // Momentum is kept for the next moment.
                    $dynamics['_romance']['last_block'] = 'core type write refused';
                }
            }
            $dynamics['_romance']['momentum'] = round($momentum, 6);
        }

        if (!RelationshipDynamics::saveDynamics($npcName, $dynamics)) {
            error_log("[RelDyn-ROMANCE] ERROR {$npcName}: saving romance momentum failed");
        }
        if ($promoted !== null) {
            self::publishState($npcName, $dynamics, $promoted);
        }
        return $promoted;
    }

    /**
     * Is a promotion open for this NPC right now, to which type, and how much momentum does
     * it need? Reads core's row fresh (type, affinity, editor lock).
     *
     * @return array ['open' => bool, 'reason' => string, 'from' => ?string, 'to' => ?string, 'required' => float]
     */
    public static function gate(string $npcName, array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $closed = fn(string $why, ?string $from = null, ?string $to = null): array
            => ['open' => false, 'reason' => $why, 'from' => $from, 'to' => $to, 'required' => 0.0];

        $npcId = RelDynStorage::resolveNpcId($npcName);
        if ($npcId === null) {
            return $closed('no core_npc_master row');
        }
        $row = $GLOBALS['db']->fetchOne('SELECT extended_data FROM core_npc_master WHERE id = $1', [$npcId]);
        $ext = json_decode((string) ($row['extended_data'] ?? ''), true);
        if (!is_array($ext)) {
            error_log("[RelDyn-ROMANCE] {$npcName}: core extended_data is not a JSON object; no promotion");
            return $closed('core row unreadable');
        }
        if (!empty($ext['relationships_locked'])) {
            return $closed('relationships_locked (editor lock)');
        }
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended($ext) ?? ['aff' => 0, 'type' => 'neutral'];
        $from = strtolower(trim((string) ($rel['type'] ?? 'neutral')));
        $from = $from === '' ? 'neutral' : $from;
        $aff = floatval($rel['aff'] ?? 0);

        $step = null;
        foreach ((array) $cfg['ladder'] as $s) {
            if (in_array($from, array_map('strtolower', (array) ($s['from'] ?? [])), true)) {
                $step = $s;
                break;
            }
        }
        if ($step === null) {
            return $closed("no romance step from core type '{$from}'", $from);
        }
        $to = (string) $step['to'];

        $states = self::blockingStates($dynamics);
        $blocking = array_values(array_intersect((array) $cfg['block_states'], $states));
        if ($blocking !== []) {
            return $closed('state: ' . implode(', ', $blocking), $from, $to);
        }
        $tier = RelationshipDynamics::getCurrentTier($aff);
        $minTier = (string) $step['min_tier'];
        if (RelationshipDynamics::tierRank($minTier) < 0) {
            error_log("[RelDyn-ROMANCE] config: unknown min_tier '{$minTier}' for {$to}; no promotion");
            return $closed("unknown min_tier '{$minTier}'", $from, $to);
        }
        if (RelationshipDynamics::tierRank($tier) < RelationshipDynamics::tierRank($minTier)) {
            return $closed("tier {$tier} (core aff " . round($aff) . ") below {$minTier}", $from, $to);
        }

        $a = RelationshipDynamics::attractionFor($npcName, $dynamics);
        // The NPC's relationship preference (type filter; attraction lane's preferences table)
        $pref = $a['preference'] ?? null;
        if ($pref !== null) {
            $acfg = RelDynAttraction::config();
            $max = intval(((array) (((array) $acfg['preferences'])[$pref] ?? []))['romance_max'] ?? RelDynAttraction::ROMANCE_FULL);
            if ($max < intval(((array) $acfg['romance_types'])[$to] ?? RelDynAttraction::ROMANCE_FULL)) {
                return $closed("preference '{$pref}' blocks {$to}", $from, $to);
            }
        }
        if (!empty($a['friendzoned'])) {
            return $closed('friendzoned: ' . ($a['reason'] ?? ''), $from, $to);
        }
        if (empty($a['passes'])) {
            return $closed('attraction does not pass: ' . ($a['reason'] ?? ''), $from, $to);
        }
        if (!empty($step['requires_sociological'])) {
            $status = !empty($a['pillars']['status']['pass']);
            $competence = !empty($a['pillars']['competence']['pass']);
            if (!$status || !$competence) {
                return $closed('sociological pillars not passed (commitment blocked)', $from, $to);
            }
        }
        // The attraction's romance axis: the type must be open (crush: visceral pass; romantic:
        // sociological too, MDD 2.6) AND earned through significant interactions (tier lift).
        if (in_array(strtolower($to), array_map('strtolower', (array) ($a['blocked_types'] ?? [])), true)) {
            return $closed("attraction has not opened {$to} yet: " . ($a['reason'] ?? ''), $from, $to);
        }
        $ceiling = $a['ceiling_tier'] ?? null;
        if ($ceiling !== null) {
            $have = array_search((string) $ceiling, self::CEILING_ORDER, true);
            $need = array_search((string) $step['min_ceiling'], self::CEILING_ORDER, true);
            if ($have === false || $need === false) {
                error_log("[RelDyn-ROMANCE] {$npcName}: unknown attraction ceiling '{$ceiling}' or '{$step['min_ceiling']}'; no promotion");
                return $closed("unknown attraction ceiling '{$ceiling}'", $from, $to);
            }
            if ($have < $need) {
                return $closed("attraction ceiling {$ceiling} below {$step['min_ceiling']}", $from, $to);
            }
        }

        return ['open' => true, 'reason' => '', 'from' => $from, 'to' => $to,
                'required' => self::requiredMomentum($dynamics, $npcId, $cfg)];
    }

    /** RelDyn states that hold a promotion back: walkaway, conflict, ick, withdrawn, a boundary (either lane's). */
    public static function blockingStates(array $dynamics): array
    {
        $out = [];
        if (($dynamics['_walkaway_state'] ?? 'normal') !== 'normal') $out[] = 'walkaway';
        if (!empty($dynamics['in_conflict'])) $out[] = 'conflict';
        if (!empty($dynamics['_ick_tracker']['ick_active'])) $out[] = 'ick';
        if (floatval($dynamics['dimensions']['resentment']['x'] ?? 0) >= RelationshipDynamics::RESENTMENT_WITHDRAWAL_AT) $out[] = 'withdrawn';
        // the §9 boundary of the fulfillment lane or the concern lane's values boundary
        if (RelDynFulfillment::boundaryActive($dynamics) || RelDynConcern::boundaryActive($dynamics)) $out[] = 'boundary';
        return $out;
    }

    /**
     * Who this NPC is, as a momentum multiplier (unitless): the attachment mult (style corners
     * read at the NPC's axes, decisions §12) x the temperament mult (A25 through the trait
     * engine: hinge 1 + 4 max(0, G - 0.6), Guarded G .85 -> 2.0), the product capped at
     * momentum_stack_cap (decisions §16 #5: Guarded x avoidant was 4x, now 2.5x).
     * Returns ['attachment', 'temperament', 'stacked' (uncapped product), 'mult' (capped)].
     */
    public static function momentumMult(array $dynamics, array $cfg): array
    {
        $attachment = RelationshipDynamics::attachmentBlend($dynamics, (array) $cfg['momentum_attachment_mult'], 1.0);
        $temperament = (string) ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? '');
        $tempMult = floatval(RelDynTraits::tableParam($temperament, (array) $cfg['momentum_temperament_mult'], 1.0, 'R',
            fn(array $x) => 1.0 + 4.0 * max(0.0, $x['G'] - 0.6), 'mult', $dynamics));
        $stacked = $attachment * $tempMult;
        $cap = $cfg['momentum_stack_cap'] ?? null;
        $mult = is_numeric($cap) ? min($stacked, max(0.0, floatval($cap))) : $stacked;
        return ['attachment' => $attachment, 'temperament' => $tempMult, 'stacked' => $stacked, 'mult' => $mult];
    }

    /** Momentum (sum of moment weights) this NPC needs for one step. */
    public static function requiredMomentum(array $dynamics, int $npcId, array $cfg): float
    {
        $required = floatval($cfg['momentum_required']) * self::momentumMult($dynamics, $cfg)['mult'];

        // After a deliberate step-back out of romance, in this game timeline (a save loaded
        // from before it restores core's type through core's own timeline snapshot).
        $last = RelDynStorage::getAll($npcId)[self::STORAGE_KEY_TYPE_CHANGE] ?? null;
        if (is_array($last) && ($last['direction'] ?? '') === 'step_back') {
            $at = floatval($last['gamets'] ?? 0);
            $now = RelationshipDynamics::currentGamets();
            if ($at <= 0 || $now <= 0 || $at <= $now) {
                $required *= floatval($cfg['stepback_momentum_mult']);
            }
        }
        return $required;
    }

    // =========================================================================
    // SHARMAT HANDOFF
    // =========================================================================

    /**
     * Sharmat's own arousal for this NPC (nsfw_npc_data aiagent_nsfw_intimacy_data.sex_disposal),
     * read only; null when Sharmat is not loaded or holds none.
     */
    public static function sharmatArousal(string $npcName): ?int
    {
        if (!class_exists('NsfwNpcData')) {
            return null;
        }
        $data = NsfwNpcData::get($npcName);
        $intimacy = is_array($data['aiagent_nsfw_intimacy_data'] ?? null) ? $data['aiagent_nsfw_intimacy_data'] : null;
        return ($intimacy !== null && is_numeric($intimacy['sex_disposal'] ?? null)) ? intval($intimacy['sex_disposal']) : null;
    }

    /** The romantic state RelDyn publishes for Sharmat (see the file comment). */
    public static function buildState(string $npcName, array $dynamics, ?string $coreType, ?int $sharmatArousal): array
    {
        $type = strtolower(trim((string) ($coreType ?? ($dynamics['_core_rel_type'] ?? 'neutral'))));
        $type = $type === '' ? 'neutral' : $type;
        // This request's Attraction Matrix summary (RelDynAttraction::update, prerequest)
        $att = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $sum = !empty($att['enabled']) ? $att : null;
        $friendzoned = !empty($dynamics['_attraction_friendzoned']);
        // The NPC's own preference holds whether or not the Matrix judges the player
        // (withPreference): an asexual NPC's consent stays closed with the Matrix off too
        $intimacy = ($sum === null && ($att['preference'] ?? null) === null) ? null : !empty($att['intimacy_allowed']);
        $states = self::blockingStates($dynamics);
        $reasons = $states;
        if ($friendzoned) $reasons[] = 'friendzoned';
        // Asexual / aromantic / demisexual before the bond / bond-gated before the bond (attraction lane)
        if ($intimacy === false && !$friendzoned) $reasons[] = 'intimacy_not_allowed';

        $effective = null;
        if ($sharmatArousal !== null) {
            $effective = (int) RelationshipDynamics::applyFriendzoneSexCap($npcName, $dynamics,
                RelationshipDynamics::getEffectiveDisposition($sharmatArousal, $dynamics));
        }
        return [
            'v' => self::STATE_VERSION,
            'core_type' => $type,
            'rung' => self::rung($type),
            'romantic' => self::rung($type) > 0,
            'passion_band' => RelationshipDynamics::getPassionBand(RelationshipDynamics::getPassion($dynamics)),
            // decisions §15: 'emotional' = the passion is not sexual (asexual; null = unrestricted)
            'passion_channel' => $att['passion_channel'] ?? null,
            'attraction_pass' => $sum === null ? null : !empty($sum['passes']),
            'friendzoned' => $friendzoned,
            'intimacy_allowed' => $intimacy,
            'boundary' => in_array('boundary', $states, true),
            'walkaway' => in_array('walkaway', $states, true),
            'in_conflict' => in_array('conflict', $states, true),
            'ick' => in_array('ick', $states, true),
            'withdrawn' => in_array('withdrawn', $states, true),
            'consent_block' => $reasons !== [],
            'block_reasons' => $reasons,
            'effective_disposition' => $effective,
        ];
    }

    /**
     * Publish the romantic state to plugin_extended_data.reldyn.romance (only when it
     * changed; 'gamets' stamps when). Never writes Sharmat's store.
     */
    public static function publishState(string $npcName, array $dynamics, ?string $coreType = null, ?int $sharmatArousal = null): bool
    {
        $npcId = RelDynStorage::resolveNpcId($npcName);
        if ($npcId === null) {
            return false;
        }
        if ($sharmatArousal === null) {
            $sharmatArousal = self::sharmatArousal($npcName);
        }
        $state = self::buildState($npcName, $dynamics, $coreType, $sharmatArousal);
        $stored = RelDynStorage::getAll($npcId)[self::STORAGE_KEY_STATE] ?? null;
        if (is_array($stored)) {
            $cmp = $stored;
            unset($cmp['gamets']);
            if ($cmp == $state) {
                return true;
            }
        }
        $state['gamets'] = RelationshipDynamics::currentGamets();
        if (!RelDynStorage::setKey($npcId, self::STORAGE_KEY_STATE, $state)) {
            error_log("[RelDyn-ROMANCE] ERROR {$npcName}: publishing the romantic state failed");
            return false;
        }
        return true;
    }
}
