<?php
/**
 * RELATIONSHIP MANAGEMENT LLM
 *
 * A dedicated small/fast LLM connector for relationship processing.
 * Uses a cheap model (24B class) at low temperature for consistent, factual output.
 *
 * Features:
 * - On-activation: Auto-analyze NPC relationships when first met
 * - Batch processing: Analyze all NPCs at once
 * - Relationship inference: If A loves B and B hates C, A becomes wary of C
 * - Group inference: "Imperial soldier" auto-adds faction biases
 * - Consistency checking: Ensure reciprocal relationships make sense
 *
 * Uses GLOBALS['RELLLM_CONNECTOR'] for the connector ID (set in conf.php)
 * Falls back to the profile's LLM connector if not configured.
 */

// Ensure Logger is available
require_once $GLOBALS["ENGINE_PATH"] . "lib/logger.php";

class RelationshipLLM {

    private $db;
    private $connector;
    private $driver;
    private $modelName;
    private $promptCache = [];

    public function __construct() {
        $this->db = $GLOBALS['db'];
        $this->initConnector();
    }

    /**
     * Safely decode JSON with proper error handling
     * CRITICAL: Prevents data loss if extended_data is corrupted
     *
     * If JSON decode fails, logs the error and returns null (NOT empty array).
     * Callers must check for null before proceeding with writes.
     *
     * @param string|null $json The JSON string to decode
     * @param string $context Description for error logging
     * @return array|null Decoded array, or null if decode failed
     */
    private function safeJsonDecode($json, $context = 'unknown') {
        if ($json === null || $json === '') {
            return []; // Empty/null is valid - start fresh
        }

        $decoded = json_decode($json, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // JSON was present but corrupted - DO NOT return empty array!
            $error = json_last_error_msg();
            Logger::error("[REL-LLM] CRITICAL: JSON decode failed for {$context}: {$error}");
            Logger::error("[REL-LLM] Corrupted JSON (first 200 chars): " . substr($json, 0, 200));
            return null; // Return null to signal failure - caller must abort write
        }

        return $decoded ?: [];
    }

    /**
     * Acquire advisory lock for NPC relationship updates
     * Prevents race conditions when multiple requests update the same NPC
     *
     * @param int $npcId The NPC ID to lock
     * @return bool True if lock acquired
     */
    private function acquireNpcLock($npcId) {
        try {
            // Use pg_advisory_lock with a namespace (1001) + npc_id to avoid collisions
            $lockId = 1001000000 + intval($npcId);
            $this->db->execQuery("SELECT pg_advisory_lock({$lockId})");
            return true;
        } catch (Exception $e) {
            Logger::error("[REL-LLM] Failed to acquire advisory lock for NPC {$npcId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release advisory lock for NPC relationship updates
     *
     * @param int $npcId The NPC ID to unlock
     */
    private function releaseNpcLock($npcId) {
        try {
            $lockId = 1001000000 + intval($npcId);
            $this->db->execQuery("SELECT pg_advisory_unlock({$lockId})");
        } catch (Exception $e) {
            Logger::error("[REL-LLM] Failed to release advisory lock for NPC {$npcId}: " . $e->getMessage());
        }
    }

    /**
     * Load a prompt from the database prompts table
     * Falls back to hardcoded default if not found
     *
     * @param string $promptKey The prompt_key to load
     * @param string $fallback Hardcoded fallback if DB lookup fails
     * @return string The prompt text
     */
    private function loadPrompt($promptKey, $fallback) {
        // Check cache first
        if (isset($this->promptCache[$promptKey])) {
            return $this->promptCache[$promptKey];
        }

        try {
            if ($this->db) {
                $escapedKey = $this->db->escape($promptKey);
                $row = $this->db->fetchOne(
                    "SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = '{$escapedKey}'"
                );
                if ($row) {
                    // Use custom_prompt if set, otherwise default_prompt
                    $prompt = !empty($row['custom_prompt']) ? $row['custom_prompt'] : $row['default_prompt'];
                    $this->promptCache[$promptKey] = $prompt;
                    return $prompt;
                }
            }
        } catch (Exception $e) {
            Logger::warn("[REL-LLM] Failed to load prompt '{$promptKey}': " . $e->getMessage());
        }

        // Fallback to hardcoded
        $this->promptCache[$promptKey] = $fallback;
        return $fallback;
    }

    /**
     * Initialize the LLM connector
     * NOTE: Does NOT call setOldGlobals() here - that happens in makeSafeRequest()
     * to avoid corrupting the main chat connector's globals
     */
    private function initConnector() {
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/llm_connector.class.php";

        $llmConnector = new LLMConnector();
        $connectorId = $GLOBALS['RELLLM_CONNECTOR'] ?? 0;

        if ($connectorId > 0) {
            $this->connector = $llmConnector->readOne($connectorId);
        }

        // Fallback to first available connector
        if (empty($this->connector)) {
            $connectors = $llmConnector->readAll();
            if (!empty($connectors)) {
                $this->connector = $connectors[0];
            }
        }

        if ($this->connector) {
            $this->driver = $llmConnector->getConnector($this->connector);
            $this->modelName = $this->connector['model'] ?? $this->connector['driver'] ?? 'unknown';
        }
    }

    /**
     * Make a safe LLM request with scoped global swapping
     *
     * CRITICAL: This prevents the relationship connector from corrupting
     * the main chat connector's globals. We:
     * 1. Save the current $GLOBALS["CONNECTOR"]
     * 2. Call setOldGlobals() to configure for relationship LLM
     * 3. Make the request
     * 4. Restore the original $GLOBALS["CONNECTOR"] in finally block
     *
     * @param array $messages The messages to send
     * @param array $params Request parameters (MAX_TOKENS, etc)
     * @param string $context Context identifier for logging
     * @return string|null The response, or null on failure
     */
    private function makeSafeRequest($messages, $params, $context) {
        if (!$this->driver || !$this->connector) {
            return null;
        }

        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/llm_connector.class.php";
        $llmConnector = new LLMConnector();

        // Save current connector globals (the main chat connector's settings)
        $savedGlobals = isset($GLOBALS["CONNECTOR"]) ? $GLOBALS["CONNECTOR"] : null;

        try {
            // SWAP IN: Configure globals for relationship LLM
            $llmConnector->setOldGlobals($this->connector);

            // Make the request
            return $this->driver->fast_request($messages, $params, $context);
        } catch (Exception $e) {
            Logger::error("[REL-LLM] Request failed ({$context}): " . $e->getMessage());
            return null;
        } finally {
            // SWAP BACK: Restore main chat connector's globals
            if ($savedGlobals !== null) {
                $GLOBALS["CONNECTOR"] = $savedGlobals;
            }
        }
    }

    /**
     * Check if the LLM is available
     */
    public function isAvailable() {
        return $this->driver !== null;
    }

    /**
     * Log request to audit_request table for UI visibility
     */
    private function logToAudit($request, $response, $callType = 'relationship') {
        if (!$this->db) return;

        $connectorLabel = $this->connector['label'] ?? 'RelationshipLLM';
        $model = $this->modelName ?? 'unknown';

        $this->db->insert('audit_request', [
            'request' => json_encode([
                'type' => $callType,
                'model' => $model,
                'messages' => $request
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'result' => is_string($response) ? substr($response, 0, 2000) : json_encode($response),
            'connector' => "RelationshipLLM ({$connectorLabel})",
            'url' => "ext/relationship_system/{$callType}"
        ]);
    }

    /**
     * Analyze a single NPC's relationships
     * Called when an NPC is first activated/met
     */
    public function analyzeNpc($npcId, $forceReanalyze = false) {
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        $npcMaster = new NpcMaster();
        $npc = $npcMaster->getById($npcId);

        if (!$npc) {
            return ['ok' => false, 'error' => 'NPC not found'];
        }

        // Check if already has JSONB relationships
        $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "analyzeNpc:{$npc['npc_name']}");
        if ($extended === null) {
            return ['ok' => false, 'error' => 'Corrupted extended_data - refusing to overwrite'];
        }
        if (!empty($extended['relationships']) && !$forceReanalyze) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'Already has relationships'];
        }

        // Check if has TEXT relationships to analyze
        if (empty($npc['relationships'])) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'No text relationships'];
        }

        // Build the analysis
        return $this->runAnalysis($npc);
    }

    /**
     * Run the actual LLM analysis for an NPC
     */
    private function runAnalysis($npc) {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'LLM not available'];
        }

        $npcName = $npc['npc_name'];
        $relationshipsText = $npc['relationships'];

        // Get player name
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'the Player';
        if ($playerName === 'the Player') {
            $playerRow = $this->db->fetchOne("SELECT player_name FROM eventlog WHERE player_name IS NOT NULL AND player_name != '' ORDER BY id DESC LIMIT 1");
            if ($playerRow && !empty($playerRow['player_name'])) {
                $playerName = $playerRow['player_name'];
            }
        }

        // Replace player placeholder
        $relationshipsText = str_replace('#PLAYER_NAME#', $playerName, $relationshipsText);

        // Build NPC context
        $npcContext = "";
        if (!empty($npc['npc_static_bio'])) {
            $npcContext .= "Background: " . $npc['npc_static_bio'] . "\n";
        }
        if (!empty($npc['personality'])) {
            $npcContext .= "Personality: " . $npc['personality'] . "\n";
        }
        if (!empty($npc['occupation'])) {
            $npcContext .= "Occupation: " . $npc['occupation'] . "\n";
        }
        if (!empty($npc['race'])) {
            $npcContext .= "Race: " . $npc['race'] . "\n";
        }

        // Build prompt
        $systemPrompt = $this->getAnalysisPrompt($playerName);

        $userPrompt = "NPC: {$npcName}\n\n";
        if (!empty($npcContext)) {
            $userPrompt .= "NPC Context:\n{$npcContext}\n";
        }
        $userPrompt .= "Relationship Descriptions:\n{$relationshipsText}\n\n";
        $userPrompt .= "Analyze these relationships and infer any faction/group biases from context. Return JSON.";

        $contextData = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        Logger::info("[REL-LLM] Analyzing {$npcName} using {$this->modelName}");

        // Make LLM request with scoped global swapping
        // This prevents corrupting the main chat connector's globals
        $response = $this->makeSafeRequest(
            $contextData,
            ["MAX_TOKENS" => 1024],
            "relationship_llm"
        );

        // Log to audit_request for UI visibility
        $this->logToAudit($contextData, $response, "analyze_{$npcName}");

        // Parse response
        $relationships = $this->parseResponse($response);

        if ($relationships === null) {
            Logger::warn("[REL-LLM] Failed to parse response for {$npcName}: " . substr($response, 0, 200));
            return ['ok' => false, 'error' => 'Failed to parse response', 'raw' => $response];
        }

        // Save to NPC
        $this->saveRelationships($npc['id'], $relationships);

        Logger::info("[REL-LLM] Saved " . count($relationships) . " relationships for {$npcName}");

        return [
            'ok' => true,
            'npc_name' => $npcName,
            'relationships' => $relationships,
            'count' => count($relationships),
            'model' => $this->modelName
        ];
    }

    /**
     * Get the system prompt for relationship analysis
     * Loads from database prompts table, falls back to hardcoded default
     */
    private function getAnalysisPrompt($playerName) {
        $fallback = <<<'PROMPT'
You are a relationship analyzer for Skyrim NPCs. Analyze relationship descriptions and output JSON.

AFFINITY SCALE (-100 to +100, bell curve - extremes are RARE):
+91 to +100: Bonded (soulmates, unbreakable)
+76 to +90: Devoted (deep loyalty/love)
+56 to +75: Fond (genuine affection)
+31 to +55: Friendly (pleasant, helpful)
+6 to +30: Acquaintance (polite nod)
-5 to +5: Neutral (stranger)
-6 to -30: Wary (distrustful)
-31 to -55: Cold (unfriendly)
-56 to -75: Resentful (bitter, grudges)
-76 to -90: Hateful (active malice)
-91 to -100: Hostile (kill on sight)

TYPES: romantic, platonic, familial, professional, rival, enemy, neutral, nemesis, estranged, transactional, protective, indebted, fanatical, mentor, student, servant, client, patron, crush, ex, betrayed, suspicious, admirer, jealous, fearful, obsessed, awed, contempt, pitying, grateful, curious, dismissive

INFERENCE RULES:
1. FACTION: Imperial → add "Stormcloak": -60 enemy. Stormcloak → add "Imperial": -60 enemy.
2. RACIAL: If NPC shows racial attitudes, add race as target (e.g., "Khajit": -40 contempt)
3. OCCUPATION: Thieves Guild → "Guard": -40 rival. Companions → "Silver Hand": -70 enemy.
4. "{PLAYER_NAME}" = Player character. Store using their name "{PLAYER_NAME}".

OUTPUT (JSON only):
{"relationships": {"Target": {"aff": 50, "type": "professional", "note": "works together"}}}
PROMPT;

        $prompt = $this->loadPrompt('rel_llm_analysis', $fallback);

        // Replace {PLAYER_NAME} placeholder
        return str_replace('{PLAYER_NAME}', $playerName, $prompt);
    }

    /**
     * Parse LLM response into relationships array
     */
    private function parseResponse($response) {
        // Handle markdown code blocks
        $jsonResponse = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $jsonResponse = trim($matches[1]);
        }

        // Fix common LLM JSON issues
        $jsonResponse = str_replace(
            ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
            ['"', '"', "'", "'", ""],
            $jsonResponse
        );
        $jsonResponse = preg_replace('/[\x00-\x1F\x7F]/u', '', $jsonResponse);

        $parsed = json_decode($jsonResponse, true);

        // Fallback: extract JSON object
        if ($parsed === null) {
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $cleanJson = str_replace(
                    ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
                    ['"', '"', "'", "'", ""],
                    $matches[0]
                );
                $cleanJson = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleanJson);
                $parsed = json_decode($cleanJson, true);
            }
        }

        if (!isset($parsed['relationships'])) {
            return null;
        }

        // Validate and normalize
        $relationships = [];
        $validTypes = ['romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral'];

        foreach ($parsed['relationships'] as $target => $data) {
            $aff = isset($data['aff']) ? intval($data['aff']) : 0;
            $type = isset($data['type']) ? strtolower($data['type']) : 'neutral';
            $note = isset($data['note']) ? trim($data['note']) : '';

            $aff = max(-100, min(100, $aff));
            if (!in_array($type, $validTypes) && !preg_match('/^[a-z]+$/', $type)) {
                $type = 'neutral';
            }

            // Normalize player references to actual player name
            $targetLower = strtolower(trim($target));
            $realPlayerName = $this->getPlayerName();
            if (in_array($targetLower, ['player', 'the player', 'dragonborn', 'the dragonborn', '#player_name#', strtolower($realPlayerName)])) {
                $target = $realPlayerName;
            }

            $rel = ['aff' => $aff, 'type' => $type];
            if (!empty($note)) {
                $rel['note'] = $note;
            }
            $relationships[$target] = $rel;
        }

        return $relationships;
    }

    /**
     * Save relationships to NPC's extended_data
     */
    private function saveRelationships($npcId, $relationships) {
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        // Advisory lock to prevent race conditions
        $this->acquireNpcLock($npcId);

        try {
            $npcMaster = new NpcMaster();
            $npc = $npcMaster->getById($npcId);

            if (!$npc) {
                $this->releaseNpcLock($npcId);
                return false;
            }

            $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "saveRelationships:{$npc['npc_name']}");
            if ($extended === null) {
                // CRITICAL: Corrupted data - abort to prevent data loss
                Logger::error("[REL-LLM] ABORT: saveRelationships for {$npc['npc_name']} - corrupted extended_data");
                $this->releaseNpcLock($npcId);
                return false;
            }

            $extended['relationships'] = $relationships;
            $extended['relationships_analyzed'] = date('Y-m-d H:i:s');
            $extended['relationships_model'] = $this->modelName;

            $result = $npcMaster->updateByArray([
                'id' => $npcId,
                'extended_data' => json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);

            $this->releaseNpcLock($npcId);
            return $result;
        } catch (Exception $e) {
            $this->releaseNpcLock($npcId);
            throw $e;
        }
    }

    /**
     * Batch process all NPCs that need relationship analysis
     */
    public function batchAnalyze($limit = 50, $forceReanalyze = false) {
        $results = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];

        // Get NPCs with TEXT relationships
        $query = "SELECT id, npc_name, relationships, extended_data
                  FROM core_npc_master
                  WHERE relationships IS NOT NULL AND relationships != ''
                  ORDER BY id
                  LIMIT " . intval($limit);

        $npcs = $this->db->fetchAll($query);

        foreach ($npcs as $npc) {
            // Check if already processed
            $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "batchAnalyze:{$npc['npc_name']}");
            if ($extended === null) {
                $results['errors']++;
                $results['details'][] = [
                    'npc' => $npc['npc_name'],
                    'error' => 'Corrupted extended_data'
                ];
                continue;
            }
            if (!empty($extended['relationships']) && !$forceReanalyze) {
                $results['skipped']++;
                continue;
            }

            $result = $this->analyzeNpc($npc['id'], $forceReanalyze);

            if ($result['ok'] && empty($result['skipped'])) {
                $results['processed']++;
                $results['details'][] = [
                    'npc' => $npc['npc_name'],
                    'count' => $result['count'] ?? 0
                ];
            } elseif (!$result['ok']) {
                $results['errors']++;
                $results['details'][] = [
                    'npc' => $npc['npc_name'],
                    'error' => $result['error'] ?? 'Unknown error'
                ];
            } else {
                $results['skipped']++;
            }

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        return $results;
    }

    /**
     * Infer transitive relationships
     * If A loves B (+80) and B hates C (-70), then A should be wary of C (-20 to -40)
     */
    public function inferTransitiveRelationships($npcId) {
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        $npcMaster = new NpcMaster();
        $npc = $npcMaster->getById($npcId);

        if (!$npc) return ['ok' => false, 'error' => 'NPC not found'];

        $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "inferTransitive:{$npc['npc_name']}");
        if ($extended === null) {
            return ['ok' => false, 'error' => 'Corrupted extended_data'];
        }
        $myRels = $extended['relationships'] ?? [];

        if (empty($myRels)) {
            return ['ok' => true, 'inferred' => 0];
        }

        $inferred = [];

        // For each of my relationships
        foreach ($myRels as $targetName => $targetData) {
            $myAffinity = $targetData['aff'];

            // Skip weak relationships
            if (abs($myAffinity) < 30) continue;

            // Find the target NPC
            $escapedTarget = $this->db->escape($targetName);
            $targetNpc = $this->db->fetchOne(
                "SELECT id, extended_data FROM core_npc_master WHERE npc_name = '" . $escapedTarget . "' LIMIT 1"
            );

            if (!$targetNpc) continue;

            $targetExtended = $this->safeJsonDecode($targetNpc['extended_data'] ?? null, "inferTransitive:target:{$targetName}");
            if ($targetExtended === null) continue; // Skip corrupted target, don't abort
            $targetRels = $targetExtended['relationships'] ?? [];

            // Check target's relationships
            foreach ($targetRels as $thirdParty => $thirdData) {
                // Skip if I already have a relationship with this entity
                if (isset($myRels[$thirdParty])) continue;

                // Skip self-reference
                if ($thirdParty === $npc['npc_name']) continue;

                $theirAffinity = $thirdData['aff'];

                // Calculate transitive affinity
                // If I love someone (+80) who hates someone else (-70), I become wary (-30ish)
                // If I love someone (+80) who loves someone (+80), I become warm (+30ish)
                $transitiveAff = intval(($myAffinity * $theirAffinity) / 200);

                // Only infer if significant
                if (abs($transitiveAff) >= 15) {
                    $transitiveAff = max(-50, min(50, $transitiveAff)); // Cap at moderate levels

                    $inferred[$thirdParty] = [
                        'aff' => $transitiveAff,
                        'type' => $transitiveAff > 0 ? 'neutral' : 'rival',
                        'inferred_from' => $targetName
                    ];
                }
            }
        }

        if (!empty($inferred)) {
            // Advisory lock to prevent race conditions
            $this->acquireNpcLock($npcId);

            try {
                // Re-fetch to get latest state after acquiring lock
                $npc = $npcMaster->getById($npcId);
                $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "inferTransitive:save:{$npc['npc_name']}");
                if ($extended === null) {
                    // CRITICAL: Corrupted data - abort to prevent data loss
                    Logger::error("[REL-LLM] ABORT: inferTransitive save for {$npc['npc_name']} - corrupted extended_data");
                    $this->releaseNpcLock($npcId);
                    return ['ok' => false, 'error' => 'Corrupted extended_data during save'];
                }
                $myRels = $extended['relationships'] ?? [];

                // Merge with existing (don't overwrite explicit relationships)
                foreach ($inferred as $target => $data) {
                    if (!isset($myRels[$target])) {
                        $myRels[$target] = $data;
                    }
                }

                $extended['relationships'] = $myRels;
                $extended['relationships_inferred'] = date('Y-m-d H:i:s');

                $npcMaster->updateByArray([
                    'id' => $npcId,
                    'extended_data' => json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ]);

                $this->releaseNpcLock($npcId);
                Logger::info("[REL-LLM] Inferred " . count($inferred) . " relationships for " . $npc['npc_name']);
            } catch (Exception $e) {
                $this->releaseNpcLock($npcId);
                throw $e;
            }
        }

        return [
            'ok' => true,
            'inferred' => count($inferred),
            'relationships' => $inferred
        ];
    }

    /**
     * DYNAMIC RELATIONSHIP EVALUATION
     *
     * Called after each conversation turn to evaluate if relationships should change.
     * Receives the full context (recent dialogue, events, actions) and decides
     * what affinity changes should occur.
     *
     * This is the "arbiter" function - it sees everything and makes the final call.
     *
     * @param int $npcId The NPC who is speaking
     * @param string $npcResponse The NPC's response text
     * @param array $context Full context including recent events, dialogue, player actions
     * @return array Changes to apply: ['Player' => ['delta' => +15, 'type' => null], ...]
     */
    public function evaluateContext($npcId, $npcResponse, $context = []) {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'LLM not available'];
        }

        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        $npcMaster = new NpcMaster();
        $npc = $npcMaster->getById($npcId);

        if (!$npc) {
            return ['ok' => false, 'error' => 'NPC not found'];
        }

        $npcName = $npc['npc_name'];

        // Get current relationships
        $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "evaluateContext:{$npcName}");
        if ($extended === null) {
            return ['ok' => false, 'error' => 'Corrupted extended_data'];
        }
        $currentRels = $extended['relationships'] ?? [];

        // Build context string
        $contextStr = "";

        // Director instruction context (rolemaster guidance)
        // This explains why an NPC might behave in ways that seem out of character
        // e.g., if instructed to "be rude", don't penalize the Player relationship
        if (!empty($context['director_instruction'])) {
            $contextStr .= "⚠ DIRECTOR INSTRUCTION (game master guidance that prompted this response):\n";
            $contextStr .= "\"" . $context['director_instruction'] . "\"\n";
            $contextStr .= "NOTE: The NPC's behavior below was DIRECTED by the game master. ";
            $contextStr .= "Attribute the behavior to following instructions, not to genuine feelings toward the other party.\n\n";
        }

        // Recent events
        if (!empty($context['events'])) {
            $contextStr .= "Recent Events:\n" . implode("\n", array_slice($context['events'], -10)) . "\n\n";
        }

        // Build EXPLICIT speaker/listener attribution
        // CRITICAL: The LLM must know unambiguously WHO is the speaker and WHO is the listener
        // to record the correct relationship direction
        //
        // In Player<->NPC conversations:
        // - SPEAKER: The NPC (whose feelings we're recording)
        // - LISTENER: The Player (who they're talking to)
        //
        // Note: $context['dialogue'] contains the NPC's previous lines (talkedSoFar)
        // $context['player_action'] is what the Player said/did
        // $npcResponse is the NPC's latest response being evaluated

        $playerName = $this->getPlayerName();
        $listenerName = $context['listener_name'] ?? $playerName;
        $listenerKey = ($listenerName === 'Player' || strcasecmp($listenerName, $playerName) === 0) ? $playerName : $listenerName;

        // EXPLICIT SPEAKER/LISTENER HEADER
        $contextStr .= "═══════════════════════════════════════════════════════\n";
        $contextStr .= "SPEAKER: {$npcName} (the NPC whose feelings we are recording)\n";
        $contextStr .= "LISTENER: {$listenerKey} (who they were talking to)\n";
        $contextStr .= "═══════════════════════════════════════════════════════\n\n";

        $contextStr .= "Your task: Record how {$npcName} felt about this exchange with {$listenerKey}.\n";
        $contextStr .= "Your output MUST include \"{$listenerKey}\" - this is mandatory, not optional.\n\n";

        // NPC's pre-computed emotional state — what they actually felt, not inferred from words.
        // The main LLM already processed their personality, context, and RelDyn state to produce this.
        if (!empty($context['npc_emotional_state'])) {
            $emo = $context['npc_emotional_state'];
            $emotionParts = [];
            if (!empty($emo['mood']))      $emotionParts[] = $emo['mood'];
            if (!empty($emo['emotion']))   $emotionParts[] = $emo['emotion'];
            if (!empty($emo['intensity'])) $emotionParts[] = "(intensity: {$emo['intensity']})";
            $contextStr .= "{$npcName}'s EMOTIONAL STATE: " . implode(', ', $emotionParts) . "\n";
        }
        if (!empty($context['reldyn_state'])) {
            $rd = $context['reldyn_state'];
            $contextStr .= "{$npcName}'s RELATIONSHIP DYNAMICS: warmth={$rd['warmth']}, passion={$rd['passion']}, ";
            $contextStr .= "speed={$rd['speed']}, gears={$rd['gears']}, temperament={$rd['temperament']}\n";
        }
        if (!empty($context['npc_emotional_state']) || !empty($context['reldyn_state'])) {
            $contextStr .= "Use these as the PRIMARY signal for scoring — the NPC's words may not match their true feelings.\n\n";
        }

        $contextStr .= "CONVERSATION:\n";

        // Player's action/speech (what triggered this NPC response)
        if (!empty($context['player_action'])) {
            $contextStr .= "[{$listenerKey} said]: " . $context['player_action'] . "\n";
        }

        // NPC's response to the Player
        $contextStr .= "[{$npcName} replied]: " . $npcResponse . "\n";

        // Recent dialogue history (for additional context, but clearly labeled)
        if (!empty($context['dialogue'])) {
            $recentLines = array_slice($context['dialogue'], -4);
            if (!empty($recentLines)) {
                $contextStr .= "\nPrevious exchanges (for context):\n";
                foreach ($recentLines as $line) {
                    // These are the NPC's previous lines
                    $contextStr .= "  [{$npcName} said earlier]: " . $line . "\n";
                }
            }
        }
        $contextStr .= "\n";

        // Current relationship state (for context)
        // Only include Player + nearby NPCs + mentioned NPCs (not ALL relationships)
        $relStateStr = "";

        // Always include Player (use actual name)
        $playerRelKey = isset($currentRels[$playerName]) ? $playerName : (isset($currentRels['Player']) ? 'Player' : null);
        if ($playerRelKey) {
            $data = $currentRels[$playerRelKey];
            $relStateStr .= "  {$playerName}: {$data['aff']} ({$data['type']})\n";
        } else {
            $relStateStr .= "  {$playerName}: 0 (neutral)\n";
        }

        // Get nearby NPCs from context
        $nearbyNpcs = $context['nearby_npcs'] ?? [];

        // Scan dialogue/events for mentioned NPCs
        $mentionedNpcs = [];
        $textToScan = $npcResponse . ' ' . ($context['player_action'] ?? '');
        if (!empty($context['dialogue'])) {
            $textToScan .= ' ' . implode(' ', $context['dialogue']);
        }
        $textLower = strtolower($textToScan);

        foreach (array_keys($currentRels) as $knownNpc) {
            if ($knownNpc === 'Player' || $knownNpc === $playerName) continue;
            if (stripos($textLower, strtolower($knownNpc)) !== false) {
                $mentionedNpcs[] = $knownNpc;
            }
        }

        // Combine nearby + mentioned, remove duplicates
        $relevantNpcs = array_unique(array_merge($nearbyNpcs, $mentionedNpcs));

        // Add relevant NPCs to relationship state
        foreach ($relevantNpcs as $npcTarget) {
            $npcTarget = trim($npcTarget);
            if (empty($npcTarget) || strtolower($npcTarget) === 'player') continue;
            if (isset($currentRels[$npcTarget])) {
                $data = $currentRels[$npcTarget];
                $relStateStr .= "  {$npcTarget}: {$data['aff']} ({$data['type']})\n";
            }
        }

        $systemPrompt = $this->getDynamicEvalPrompt();

        // Build output key instruction based on listener
        $outputKeyInstruction = "";
        if ($listenerKey === $playerName) {
            $outputKeyInstruction = "IMPORTANT: Use \"{$playerName}\" as the key for the player character.";
        } else {
            $outputKeyInstruction = "IMPORTANT: Use \"{$listenerKey}\" as the key for the listener NPC.";
        }

        // Inject RelDyn type constraints if available (relationship preference gating)
        $typeConstraintStr = '';
        if (file_exists(dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php')) {
            require_once dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php';
            $reldynCfg = RelationshipDynamics::getConfig();
            if ($reldynCfg['type_filter_enabled'] ?? true) {
                $reldynDynamics = RelationshipDynamics::getDynamics($npcName);
                if (!empty($reldynDynamics['relationship_preference'])) {
                    $playerRelKey2 = isset($currentRels[$playerName]) ? $playerName : (isset($currentRels['Player']) ? 'Player' : null);
                    $currentAffForFilter = $playerRelKey2 ? intval($currentRels[$playerRelKey2]['aff'] ?? 0) : 0;
                    // Check blocked types with CHIM affinity (not RelDyn blob affinity)
                    $blockedTypes = RelationshipDynamics::getBlockedTypes($reldynDynamics, $currentAffForFilter);
                    $typeConstraintStr = RelationshipDynamics::getTypeConstraintPrompt($reldynDynamics, $npcName, $currentAffForFilter);
                }
            }
        }

        $userPrompt = <<<PROMPT
═══════════════════════════════════════════════════════
SPEAKER: {$npcName} (whose feelings we are recording)
LISTENER: {$listenerKey} (who they were talking to)
═══════════════════════════════════════════════════════

CURRENT RELATIONSHIPS:
{$relStateStr}

{$typeConstraintStr}CONTEXT:
{$contextStr}

Based on this interaction, score how {$npcName}'s feelings toward {$listenerKey} changed.
Consider: Was there kindness, insult, betrayal, gratitude, violence, romance, etc.?
Only score what actually happened - most dimensions should be 0.

{$outputKeyInstruction}

Return a flat JSON object with all dimensions (do NOT nest under target name):
{"affinity_delta": X, "affinity_reason": "brief", "trust_delta": X, "trust_reason": "brief", "comfort_delta": X, "comfort_reason": "brief", "respect_delta": X, "respect_reason": "brief", "maturity_delta": X, "maturity_reason": "brief", "grievance": null}
If relationship type should change (rare), add: "type": "new_type"
PROMPT;

        $contextData = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        Logger::info("[REL-LLM] Evaluating context for {$npcName}");

        // Make LLM request with scoped global swapping
        $response = $this->makeSafeRequest(
            $contextData,
            ["MAX_TOKENS" => 768],
            "relationship_eval"
        );

        // Log to audit_request for UI visibility
        $this->logToAudit($contextData, $response, "eval_{$npcName}");

        // Parse the response (returns ['changes' => ..., 'raw_eval' => ...])
        $evalParsed = $this->parseEvalResponse($response);
        $changes = $evalParsed['changes'] ?? [];
        $rawEval = $evalParsed['raw_eval'] ?? [];

        if (empty($changes)) {
            return ['ok' => true, 'changes' => [], 'reason' => 'No significant changes'];
        }

        // NEW multi-delta format returns a single change entry (not keyed by target).
        // Wrap it with the listener key so applyChanges can iterate it the old way.
        if (isset($changes['delta']) && !isset($changes[$listenerKey])) {
            $changes = [$listenerKey => $changes];
        }

        // Apply the changes (pass rawEval for RelDyn multi-delta storage)
        $applied = $this->applyChanges($npcId, $changes, $currentRels, $rawEval);

        Logger::info("[REL-LLM] Applied " . count($applied) . " changes for {$npcName}");

        return [
            'ok' => true,
            'changes' => $applied,
            'model' => $this->modelName
        ];
    }

    /**
     * Evaluate NPC-to-NPC conversation context (token-efficient bidirectional)
     *
     * When one NPC speaks to another, both may form impressions.
     * This method evaluates BOTH perspectives in a single LLM call,
     * saving ~50% tokens compared to two separate evaluateContext() calls.
     *
     * @param int $speakerNpcId The NPC who spoke
     * @param int $listenerNpcId The NPC who listened
     * @param string $dialogue What was said
     * @param array $context Additional context (events, etc)
     * @return array Results for both NPCs
     */
    public function evaluateNpcToNpcContext($speakerNpcId, $listenerNpcId, $dialogue, $context = []) {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'LLM not available'];
        }

        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        $npcMaster = new NpcMaster();
        $speaker = $npcMaster->getById($speakerNpcId);
        $listener = $npcMaster->getById($listenerNpcId);

        if (!$speaker || !$listener) {
            return ['ok' => false, 'error' => 'NPC(s) not found'];
        }

        $speakerName = $speaker['npc_name'];
        $listenerName = $listener['npc_name'];

        // Get current relationships for both NPCs
        $speakerExtended = $this->safeJsonDecode($speaker['extended_data'] ?? null, "npc2npc:speaker:{$speakerName}");
        $listenerExtended = $this->safeJsonDecode($listener['extended_data'] ?? null, "npc2npc:listener:{$listenerName}");

        // If either is corrupted, skip them but don't abort completely
        if ($speakerExtended === null) {
            Logger::warn("[REL-LLM] Skipping speaker {$speakerName} - corrupted extended_data");
            $speakerExtended = [];
        }
        if ($listenerExtended === null) {
            Logger::warn("[REL-LLM] Skipping listener {$listenerName} - corrupted extended_data");
            $listenerExtended = [];
        }

        $speakerRels = $speakerExtended['relationships'] ?? [];
        $listenerRels = $listenerExtended['relationships'] ?? [];

        // ── TIERED EVAL ──
        // Tier 1 (neither NPC > 20 aff with player): original eval — no personality injection
        // Tier 2 (at least one NPC 20-50 aff with player): keywords only — traits + bonds
        // Tier 3 (at least one NPC > 50 aff with player): full personality injection
        $playerName = $this->getPlayerName();
        $speakerPlayerAff = intval($speakerRels[$playerName]['aff'] ?? 0);
        $listenerPlayerAff = intval($listenerRels[$playerName]['aff'] ?? 0);
        $maxPlayerAff = max(abs($speakerPlayerAff), abs($listenerPlayerAff));

        if ($maxPlayerAff > 50) {
            $evalTier = 3;
        } elseif ($maxPlayerAff >= 20) {
            $evalTier = 2;
        } else {
            $evalTier = 1;
        }

        Logger::info("[REL-LLM] NPC-to-NPC tier={$evalTier} (speaker:{$speakerName} playerAff={$speakerPlayerAff}, listener:{$listenerName} playerAff={$listenerPlayerAff})");

        // Build personality context based on tier
        if ($evalTier >= 3) {
            // Full personality: speechstyle, personality, traits, bonds, notes
            $speakerProfile = $this->buildNpcPersonalitySummary($speaker, $speakerExtended);
            $listenerProfile = $this->buildNpcPersonalitySummary($listener, $listenerExtended);
        } elseif ($evalTier >= 2) {
            // Keywords only: traits + significant bonds (no speechstyle/personality paragraphs)
            $speakerProfile = $this->buildNpcKeywordSummary($speaker, $speakerExtended);
            $listenerProfile = $this->buildNpcKeywordSummary($listener, $listenerExtended);
        } else {
            // Tier 1: no personality injection
            $speakerProfile = '';
            $listenerProfile = '';
        }

        // Build context string
        $contextStr = "";

        // Director instruction context (rolemaster guidance)
        if (!empty($context['director_instruction'])) {
            $contextStr .= "⚠ DIRECTOR INSTRUCTION (game master guidance that prompted this response):\n";
            $contextStr .= "\"" . $context['director_instruction'] . "\"\n";
            $contextStr .= "NOTE: The speaker's behavior was DIRECTED by the game master, not driven by genuine feelings.\n\n";
        }

        if (!empty($context['events'])) {
            $contextStr .= "Recent Events:\n" . implode("\n", array_slice($context['events'], -5)) . "\n\n";
        }
        $contextStr .= "What was said: " . $dialogue . "\n";

        // Current relationship states
        $speakerRelWithListener = $speakerRels[$listenerName] ?? ['aff' => 0, 'type' => 'neutral'];
        $listenerRelWithSpeaker = $listenerRels[$speakerName] ?? ['aff' => 0, 'type' => 'neutral'];

        // Include existing relationship notes if they exist (tier 2+)
        $speakerNote = ($evalTier >= 2 && !empty($speakerRelWithListener['note'])) ? " — \"{$speakerRelWithListener['note']}\"" : '';
        $listenerNote = ($evalTier >= 2 && !empty($listenerRelWithSpeaker['note'])) ? " — \"{$listenerRelWithSpeaker['note']}\"" : '';

        // System prompt: tier 3 gets personality-aware version, tier 1-2 get original
        $systemPrompt = ($evalTier >= 3) ? $this->getNpcToNpcEvalPrompt() : $this->getNpcToNpcEvalPromptOriginal();

        // Eval instruction varies by tier
        $evalInstruction = ($evalTier >= 3)
            ? "Evaluate BASED ON EACH CHARACTER'S PERSONALITY — not generic reactions:"
            : "Evaluate:";

        $userPrompt = <<<PROMPT
NPC-TO-NPC INTERACTION:

SPEAKER (the one who said this): {$speakerName}
{$speakerProfile}
  Currently feels toward {$listenerName}: {$speakerRelWithListener['aff']} ({$speakerRelWithListener['type']}){$speakerNote}

LISTENER (the one who heard it): {$listenerName}
{$listenerProfile}
  Currently feels toward {$speakerName}: {$listenerRelWithSpeaker['aff']} ({$listenerRelWithSpeaker['type']}){$listenerNote}

{$contextStr}

{$speakerName} SAID the above dialogue. {$listenerName} HEARD it.

{$evalInstruction}
- "speaker" = Did {$speakerName}'s feelings toward {$listenerName} change?
- "listener" = Did {$listenerName}'s feelings toward {$speakerName} change after hearing this?

Return JSON using exactly "speaker" and "listener" as keys:
PROMPT;

        $contextData = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        Logger::info("[REL-LLM] Evaluating NPC-to-NPC: {$speakerName} <-> {$listenerName}");

        // Make LLM request with scoped global swapping
        $response = $this->makeSafeRequest(
            $contextData,
            ["MAX_TOKENS" => 512],
            "relationship_npc_to_npc"
        );

        // Log to audit
        $this->logToAudit($contextData, $response, "npc2npc_{$speakerName}_{$listenerName}");

        // Parse bidirectional response (pass names so it can handle NPC-name-as-key format)
        $parsed = $this->parseNpcToNpcResponse($response, $speakerName, $listenerName);

        // Debug: Log parsed response
        Logger::debug("[REL-LLM] NPC-to-NPC raw response: " . substr($response, 0, 500));
        Logger::debug("[REL-LLM] NPC-to-NPC parsed: speaker=" . json_encode($parsed['speaker']) . " listener=" . json_encode($parsed['listener']));

        $results = [
            'ok' => true,
            'speaker' => ['name' => $speakerName, 'changes' => []],
            'listener' => ['name' => $listenerName, 'changes' => []],
            'model' => $this->modelName
        ];

        // Apply speaker's changes (their feelings toward listener)
        if (!empty($parsed['speaker'])) {
            $speakerChanges = [$listenerName => $parsed['speaker']];
            $results['speaker']['changes'] = $this->applyChanges($speakerNpcId, $speakerChanges, $speakerRels);
        }

        // Apply listener's changes (their feelings toward speaker)
        if (!empty($parsed['listener'])) {
            $listenerChanges = [$speakerName => $parsed['listener']];
            $results['listener']['changes'] = $this->applyChanges($listenerNpcId, $listenerChanges, $listenerRels);
        }

        $totalChanges = count($results['speaker']['changes']) + count($results['listener']['changes']);
        Logger::info("[REL-LLM] NPC-to-NPC: Applied {$totalChanges} changes ({$speakerName} <-> {$listenerName})");

        return $results;
    }

    /**
     * Build a compact personality summary for NPC-to-NPC eval context.
     * Keeps token budget low (~60-100 tokens per NPC).
     * No raw RelDyn metrics (passion/stage) — those confuse the LLM.
     * Instead, scans relationships for existing bonds and presents naturally.
     */
    private function buildNpcPersonalitySummary($npc, $extendedData) {
        $lines = [];

        // Personality (from CHIM profile)
        $personality = trim($npc['personality'] ?? '');
        if (!empty($personality)) {
            $short = strlen($personality) > 120 ? substr($personality, 0, 120) . '...' : $personality;
            $lines[] = "  Personality: {$short}";
        }

        // Speechstyle (tells the eval WHO this character is)
        $speechstyle = trim($npc['speechstyle'] ?? '');
        if (!empty($speechstyle)) {
            $short = strlen($speechstyle) > 150 ? substr($speechstyle, 0, 150) . '...' : $speechstyle;
            $lines[] = "  Style: {$short}";
        }

        // RelDyn temperament + relationship preference (personality traits, not metrics)
        $reldyn = $extendedData['relationship_dynamics'] ?? [];
        $traits = [];
        if (!empty($reldyn['temperament'])) {
            $traits[] = $reldyn['temperament'];
        }
        if (!empty($reldyn['relationship_preference'])) {
            $traits[] = $reldyn['relationship_preference'];
        }
        if (!empty($traits)) {
            $lines[] = "  Traits: " . implode(', ', $traits);
        }

        // Race/gender for context
        $meta = [];
        if (!empty($npc['race'])) $meta[] = $npc['race'];
        if (!empty($npc['gender'])) $meta[] = $npc['gender'];
        if (!empty($meta)) {
            $lines[] = "  Demographics: " . implode(', ', $meta);
        }

        // Scan relationships for existing significant bonds
        // This gives the LLM natural context like "already close to Kaida (crush, 70)"
        $relationships = $extendedData['relationships'] ?? [];
        $significantBonds = [];
        $romanticTypes = ['crush', 'romantic', 'lover', 'spouse', 'committed', 'obsessed', 'fond'];
        foreach ($relationships as $targetName => $rel) {
            $aff = intval($rel['aff'] ?? 0);
            $type = strtolower($rel['type'] ?? 'neutral');
            // Include bonds with aff >= 40 or romantic-leaning types
            if ($aff >= 40 || in_array($type, $romanticTypes)) {
                $significantBonds[] = "{$targetName} ({$type}, affinity {$aff})";
            }
        }
        if (!empty($significantBonds)) {
            $lines[] = "  Significant bonds: " . implode('; ', array_slice($significantBonds, 0, 3));
        }

        return empty($lines) ? '' : implode("\n", $lines);
    }

    /**
     * Build a keyword-only summary for tier 2 eval.
     * Traits + significant bonds only — no speechstyle or personality paragraphs.
     * ~20-40 tokens per NPC.
     */
    private function buildNpcKeywordSummary($npc, $extendedData) {
        $lines = [];

        // RelDyn traits (temperament + preference)
        $reldyn = $extendedData['relationship_dynamics'] ?? [];
        $traits = [];
        if (!empty($reldyn['temperament'])) $traits[] = $reldyn['temperament'];
        if (!empty($reldyn['relationship_preference'])) $traits[] = $reldyn['relationship_preference'];
        if (!empty($npc['race'])) $traits[] = $npc['race'];
        if (!empty($npc['gender'])) $traits[] = $npc['gender'];
        if (!empty($traits)) {
            $lines[] = "  Traits: " . implode(', ', $traits);
        }

        // Significant bonds only
        $relationships = $extendedData['relationships'] ?? [];
        $significantBonds = [];
        $romanticTypes = ['crush', 'romantic', 'lover', 'spouse', 'committed', 'obsessed', 'fond'];
        foreach ($relationships as $targetName => $rel) {
            $aff = intval($rel['aff'] ?? 0);
            $type = strtolower($rel['type'] ?? 'neutral');
            if ($aff >= 40 || in_array($type, $romanticTypes)) {
                $significantBonds[] = "{$targetName} ({$type}, {$aff})";
            }
        }
        if (!empty($significantBonds)) {
            $lines[] = "  Bonds: " . implode('; ', array_slice($significantBonds, 0, 3));
        }

        return empty($lines) ? '' : implode("\n", $lines);
    }

    /**
     * Original system prompt — tier 1 and tier 2 (no personality awareness).
     */
    private function getNpcToNpcEvalPromptOriginal() {
        return <<<'PROMPT'
You are a behavioral psychologist. Evaluate NPC-to-NPC interaction briefly.

DIRECTION:
- speaker = NPC who SPOKE
- listener = NPC who HEARD
- speaker.delta = speaker's feelings toward listener changed?
- listener.delta = listener's feelings toward speaker changed?

SCALE: +/-1 typical, +/-2-3 notable, +/-5+ significant. Be conservative.

REASON FORMAT - Under 15 words:
✓ "Dark humor built rapport"
✓ "Bossy tone caused mild resentment"
✓ "Helpful advice appreciated"

OUTPUT - Use exactly "speaker" and "listener":
{"speaker": {"delta": 0, "reason": "brief"}, "listener": {"delta": 1, "reason": "brief"}}

No changes? Return empty objects: {}
PROMPT;
    }

    /**
     * Get the system prompt for NPC-to-NPC evaluation (tier 3 — personality-aware)
     */
    private function getNpcToNpcEvalPrompt() {
        $fallback = <<<'PROMPT'
You are a behavioral psychologist evaluating NPC-to-NPC interaction in Skyrim.

CRITICAL: Use each character's PERSONALITY, TRAITS, and EMOTIONAL STATE to judge their reaction.
A guarded character won't warm to flattery. A demisexual character won't respond to flirtation from strangers.
A character deeply bonded with someone else may be indifferent to a new person's charm.

DIRECTION:
- speaker = NPC who SPOKE
- listener = NPC who HEARD
- speaker.delta = speaker's feelings toward listener changed?
- listener.delta = listener's feelings toward speaker changed?

SCALE: +/-1 typical, +/-2-3 notable, +/-5+ significant. Be conservative.
Characters with guarded/stoic/independent temperaments should rarely give more than +/-1 to strangers.

REASON FORMAT - Under 15 words, reference personality:
✓ "Guarded temperament — dismissive of performative charm"
✓ "Bold personality appreciated the direct approach"
✓ "Demisexual — flattery from stranger has no effect"

OUTPUT - Use exactly "speaker" and "listener":
{"speaker": {"delta": 0, "reason": "brief"}, "listener": {"delta": 1, "reason": "brief"}}

No changes? Return empty objects: {}
PROMPT;

        return $this->loadPrompt('rel_llm_npc_to_npc', $fallback);
    }

    /**
     * Parse NPC-to-NPC evaluation response
     * Handles both "speaker"/"listener" format and NPC name keys
     */
    private function parseNpcToNpcResponse($response, $speakerName = '', $listenerName = '') {
        $jsonResponse = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $jsonResponse = trim($matches[1]);
        }

        $jsonResponse = str_replace(
            ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
            ['"', '"', "'", "'", ""],
            $jsonResponse
        );
        $jsonResponse = preg_replace('/[\x00-\x1F\x7F]/u', '', $jsonResponse);

        $parsed = json_decode($jsonResponse, true);

        if ($parsed === null) {
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $cleanJson = str_replace(
                    ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
                    ['"', '"', "'", "'", ""],
                    $matches[0]
                );
                $cleanJson = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleanJson);
                $parsed = json_decode($cleanJson, true);
            }
        }

        // Try standard format first
        $speakerData = $parsed['speaker'] ?? null;
        $listenerData = $parsed['listener'] ?? null;

        // If not found, try NPC name keys (LLM sometimes uses actual names)
        if ($speakerData === null && !empty($speakerName) && isset($parsed[$speakerName])) {
            $speakerData = $parsed[$speakerName];
        }
        if ($listenerData === null && !empty($listenerName) && isset($parsed[$listenerName])) {
            $listenerData = $parsed[$listenerName];
        }

        return [
            'speaker' => $speakerData ?? [],
            'listener' => $listenerData ?? []
        ];
    }

    /**
     * Get the system prompt for dynamic evaluation
     * Loads from database prompts table, falls back to hardcoded default
     */
    private function getDynamicEvalPrompt() {
        $fallback = <<<'PROMPT'
You are a behavioral psychologist scoring how an NPC's feelings changed after one interaction.

SPEAKER ATTRIBUTION:
- [PLAYER] and [NPC] tags show who said what
- Score based on what PLAYER did/said, not the NPC's own words

SCORING DIMENSIONS (score each independently; most interactions affect 1-2, rarely all):

affinity_delta (-10 to +10): Does the NPC like the player more or less after this?
  +/-1 normal chat, +/-2-3 notably kind/rude, +/-5-10 meaningful act. MOST = 0 or +/-1.

trust_delta (-10 to +10): Did the player prove reliable or break trust?
  Keeping promises, protecting in danger, honesty = positive.
  Lying, abandoning, breaking word = negative.
  Casual chat with no trust signal = 0.

comfort_delta (-10 to +10): Did the NPC feel more or less at ease?
  Relaxed conversation, respecting boundaries = positive.
  Pushing too hard, invasive questions, ignoring discomfort = negative.
  Routine exchange = 0.

respect_delta (-10 to +10): Did the player demonstrate competence or fail?
  Domain-relevant skill, clever solution, leadership = positive.
  Incompetence at basics, foolish decisions = negative.
  No skill demonstration = 0.

maturity_delta (-5 to +5): How did the NPC HANDLE this interaction?
  IMPORTANT: Score the NPC's behavior, NOT the player's.
  NPC expressed feelings directly, showed growth, thanked sincerely = positive.
  NPC deflected with sarcasm, went passive-aggressive, shut down = negative.
  NPC responded normally = 0.

grievance (null or short string): Did the NPC notice something bothersome they did NOT address?
  Small slights, unkept promises, subject changes when NPC was uncomfortable.
  If nothing was swept under the rug, use null.

REASON FORMAT - Keep each *_reason SHORT (under 15 words).

INTERACTION SIGNIFICANCE (required):
significance (1-3): How significant was this interaction for the relationship?
  1 = Normal: regular conversation, routine exchange, small talk
  2 = Significant: a meaningful shared experience, genuine vulnerability, notable act of kindness/cruelty
  3 = Defining: a relationship-changing moment (confession, betrayal, life-saving, witnessing death together)
  MOST interactions = 1. Be very conservative with 2 and 3.
  Dimension deltas are MULTIPLIED by this value — higher significance = stronger impact.

ROMANTIC INTENT (required):
romantic_intent (0-3): Rate the PLAYER's romantic or flirtatious intent in this interaction.
  0 = No romantic intent (business, combat, neutral conversation)
  1 = Mild warmth (friendly, could be platonic or romantic)
  2 = Noticeable flirting (compliments beyond normal, lingering attention, teasing with intent)
  3 = Overt romantic pursuit (declarations, physical advances, persistent unwanted attention)
  Score based on PLAYER's behavior, not the NPC's response. Most interactions = 0 or 1.

MASKING AWARENESS:
If the NPC is socially masking (hiding true feelings in public), score based on their TRUE
emotional response, not their performed behavior. An NPC may say something polite while
feeling resentment — score the resentment, not the politeness.

BE CONSERVATIVE: most interactions = 0 on most dimensions. Only score what actually happened.

TYPE CHANGES (rare - only for defining moments):
- Only change type for: romance confession, betrayal, violence, marriage, family reveal
- Most interactions just adjust affinity, not type

OUTPUT (JSON only, flat object):
{"significance": 1, "affinity_delta": 1, "affinity_reason": "brief insight", "trust_delta": 0, "trust_reason": "no trust signal", "comfort_delta": 0, "comfort_reason": "neutral exchange", "respect_delta": 0, "respect_reason": "no skill shown", "maturity_delta": 0, "maturity_reason": "normal response", "grievance": null, "romantic_intent": 0}

If you also need to change the relationship type, add a "type" key (string).

No changes at all? Still return the full object with all zeros.
PROMPT;

        return $this->loadPrompt('rel_llm_evaluation', $fallback);
    }

    /**
     * Parse the evaluation response
     *
     * Handles TWO formats:
     * 1. NEW multi-delta (flat): {"affinity_delta": 1, "trust_delta": 2, ...}
     * 2. OLD single-delta (nested): {"changes": {"Player": {"delta": X, "reason": "..."}}}
     *
     * Always returns ['changes' => [...], 'raw_eval' => [...]] where:
     * - 'changes' is the old-format array for applyChanges() backward compat
     * - 'raw_eval' is the full multi-delta data for RelDyn processEvalDeltas()
     *
     * For the new format, affinity_delta is used as the 'delta' in the old format.
     */
    private function parseEvalResponse($response) {
        $jsonResponse = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $jsonResponse = trim($matches[1]);
        }

        $jsonResponse = str_replace(
            ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
            ['"', '"', "'", "'", ""],
            $jsonResponse
        );
        $jsonResponse = preg_replace('/[\x00-\x1F\x7F]/u', '', $jsonResponse);

        $parsed = json_decode($jsonResponse, true);

        if ($parsed === null) {
            if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
                $cleanJson = str_replace(
                    ["\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x98", "\xe2\x80\x99", "*"],
                    ['"', '"', "'", "'", ""],
                    $matches[0]
                );
                $cleanJson = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleanJson);
                $parsed = json_decode($cleanJson, true);
            }
        }

        if (empty($parsed) || !is_array($parsed)) {
            return ['changes' => [], 'raw_eval' => []];
        }

        // DETECT FORMAT: new multi-delta has '*_delta' keys at top level
        if (isset($parsed['affinity_delta']) || isset($parsed['trust_delta']) || isset($parsed['comfort_delta'])) {
            // NEW multi-delta format (flat object)
            $rawEval = $parsed;

            // Check if ALL deltas are zero (no change)
            $allZero = true;
            $deltaKeys = ['affinity_delta', 'trust_delta', 'comfort_delta', 'respect_delta', 'maturity_delta'];
            foreach ($deltaKeys as $dk) {
                if (isset($parsed[$dk]) && intval($parsed[$dk]) !== 0) {
                    $allZero = false;
                    break;
                }
            }

            if ($allZero && empty($parsed['grievance']) && empty($parsed['type'])) {
                return ['changes' => [], 'raw_eval' => $rawEval];
            }

            // Build old-format 'changes' entry using affinity_delta as the primary delta
            // The target name is filled in by evaluateContext() which knows the listener
            $change = [
                'delta' => intval($parsed['affinity_delta'] ?? 0),
                'reason' => $parsed['affinity_reason'] ?? '',
            ];
            if (!empty($parsed['type'])) {
                $change['type'] = $parsed['type'];
            }

            return ['changes' => $change, 'raw_eval' => $rawEval];
        }

        // OLD format: {"changes": {"Player": {"delta": X, "reason": "..."}}}
        // Build raw_eval from the first target's delta for backward compat with RelDyn
        $oldChanges = $parsed['changes'] ?? [];
        $rawEval = [];

        if (!empty($oldChanges)) {
            $firstChange = reset($oldChanges);
            if (is_array($firstChange)) {
                $rawEval['affinity_delta'] = intval($firstChange['delta'] ?? 0);
                $rawEval['affinity_reason'] = $firstChange['reason'] ?? '';
            }
        }

        return ['changes' => $oldChanges, 'raw_eval' => $rawEval];
    }

    /**
     * Apply relationship changes
     */
    private function applyChanges($npcId, $changes, $currentRels, $rawEval = []) {
        require_once $GLOBALS['ENGINE_PATH'] . "lib/core/npc_master.class.php";

        $npcMaster = new NpcMaster();
        $npc = $npcMaster->getById($npcId);
        if (!$npc) return [];

        $applied = [];

        // Titles/roles that should NOT be stored as relationships
        // Factions/groups ARE allowed (Stormcloaks, Companions, Thieves Guild, etc.)
        $blockedTitles = [
            'dragonborn', 'the dragonborn',
            'arch mage', 'archmage', 'the arch mage',
            'harbinger', 'the harbinger',
            'listener', 'the listener',
            'guildmaster', 'guild master', 'the guildmaster',
            'thane', 'the thane',
            'stormblade', 'the stormblade',
            'legate', 'the legate',
            'hero', 'the hero',
            'champion', 'the champion',
            'chosen one', 'the chosen one',
            'nightingale', 'the nightingale'
        ];

        foreach ($changes as $target => $change) {
            $delta = intval($change['delta'] ?? 0);
            $newType = $change['type'] ?? null;
            $reason = $change['reason'] ?? '';

            if ($delta === 0 && $newType === null) continue;

            // Skip titles/roles (but allow factions/groups)
            if (in_array(strtolower(trim($target)), $blockedTitles)) {
                Logger::debug("[REL-LLM] Skipping title/role as relationship target: {$target}");
                continue;
            }

            // Normalize player name references to actual player name
            $playerName = $this->getPlayerName();
            $targetLower = strtolower(trim($target));
            if ($targetLower === strtolower($playerName) ||
                in_array($targetLower, ['player', 'the player', 'dragonborn', 'the dragonborn', '#player_name#'])) {
                $target = $playerName;
            }

            // Initialize if doesn't exist
            if (!isset($currentRels[$target])) {
                $currentRels[$target] = ['aff' => 0, 'type' => 'neutral'];
            }

            $oldAff = $currentRels[$target]['aff'];
            $oldType = $currentRels[$target]['type'] ?? 'neutral';
            $newAff = max(-100, min(100, $oldAff + $delta));

            // When RelDyn is enabled, it owns player↔NPC aff changes (passion-weighted RPM→Speed).
            // NPC↔NPC aff changes apply directly — RelDyn has no NPC↔NPC passion math.
            $reldynEnabled = !empty($GLOBALS['RELATIONSHIP_DYNAMICS_ENABLED'])
                || file_exists(dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php');
            $playerName = $this->getPlayerName();
            $isPlayerTarget = (strtolower(trim($target)) === strtolower($playerName));
            if (!$reldynEnabled || !$isPlayerTarget) {
                // NPC↔NPC or RelDyn not installed: apply delta directly
                $currentRels[$target]['aff'] = $newAff;
            } else {
                // Player↔NPC: defer to RelDyn's passion-weighted RPM→Speed formula
                $currentRels[$target]['_rel_eval_delta'] = $delta;
                $newAff = $oldAff; // Keep aff unchanged — RelDyn will handle it

                // Store full multi-delta eval for RelDyn's XYZ dimension engine.
                // processPendingEvalDeltas() reads _pending_xyz_eval on next postrequest cycle.
                if (!empty($rawEval)) {
                    try {
                        require_once dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php';
                        $reldynDynamics = RelationshipDynamics::getDynamics($npc['npc_name']);
                        if (!empty($reldynDynamics)) {
                            $reldynDynamics['_pending_xyz_eval'] = $rawEval;
                            RelationshipDynamics::saveDynamics($npc['npc_name'], $reldynDynamics);
                            Logger::info("[REL-LLM] Stored multi-delta eval for RelDyn XYZ: " . json_encode($rawEval));
                        }
                    } catch (Exception $e) {
                        Logger::warn("[REL-LLM] Failed to store XYZ eval: " . $e->getMessage());
                    }
                }
            }

            $typeChanged = false;
            $finalType = $oldType;

            if ($newType) {
                $newTypeLower = strtolower($newType);

                // RelDyn type filter: enforce relationship_preference blocks
                $typeBlocked = false;
                if (file_exists(dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php')) {
                    require_once dirname(__DIR__) . '/relationship_dynamics/relationship_dynamics.php';
                    $reldynDyn = RelationshipDynamics::getDynamics($npc['npc_name']);
                    if (!empty($reldynDyn['relationship_preference'])) {
                        $blockedTypes = RelationshipDynamics::getBlockedTypes($reldynDyn, $newAff);
                        if (in_array($newTypeLower, $blockedTypes)) {
                            Logger::info("[REL-LLM] TYPE BLOCKED by RelDyn preference: {$npc['npc_name']} -> {$target}: {$oldType} => {$newTypeLower} (pref={$reldynDyn['relationship_preference']})");
                            $typeBlocked = true;
                        }
                    }
                }

                if (!$typeBlocked && $oldType !== $newTypeLower) {
                    Logger::info("[REL-LLM] TYPE CHANGE: {$npc['npc_name']} -> {$target}: {$oldType} => {$newTypeLower}");
                    $typeChanged = true;
                }
                if (!$typeBlocked) {
                    $currentRels[$target]['type'] = $newTypeLower;
                    $finalType = $newTypeLower;
                }
            } else {
                // Auto-evolve type ONLY when leaving neutral
                // Once you've formed an opinion, you don't go back to neutral
                $currentType = $currentRels[$target]['type'];
                if ($currentType === 'neutral') {
                    $inferredType = $this->inferTypeFromAffinity($newAff);
                    if ($inferredType !== 'neutral') {
                        Logger::info("[REL-LLM] AUTO TYPE CHANGE: {$npc['npc_name']} -> {$target}: neutral => {$inferredType} (affinity: {$newAff})");
                        $currentRels[$target]['type'] = $inferredType;
                        $finalType = $inferredType;
                        $typeChanged = true;
                    }
                }
            }

            // Multi-field note system:
            // - note: Minor recent interactions (updates on delta >= 3)
            // - best: Most significant positive event (only replaced by larger positive)
            // - worst: Most significant negative event (only replaced by larger negative)
            // - best_delta/worst_delta: Track magnitude for comparison
            // - relation: Familial/role detail (son, father, mentor) - set once, rarely changes

            if (!empty($reason)) {
                // Always update 'note' for recent interactions (threshold: |delta| >= 3)
                if (abs($delta) >= 3 || empty($currentRels[$target]['note'] ?? '')) {
                    $currentRels[$target]['note'] = $reason;
                }

                // Track major positive events in 'best' (threshold: delta >= 10)
                if ($delta >= 10) {
                    $existingBestDelta = $currentRels[$target]['best_delta'] ?? 0;
                    // Only replace if this is more significant than previous best
                    if ($delta >= $existingBestDelta) {
                        $currentRels[$target]['best'] = $reason;
                        $currentRels[$target]['best_delta'] = $delta;
                    }
                }

                // Track major negative events in 'worst' (threshold: delta <= -10)
                if ($delta <= -10) {
                    $existingWorstDelta = $currentRels[$target]['worst_delta'] ?? 0;
                    // Only replace if this is more significant (more negative) than previous worst
                    // Note: We compare absolute values since both are negative
                    if ($delta <= $existingWorstDelta) {
                        $currentRels[$target]['worst'] = $reason;
                        $currentRels[$target]['worst_delta'] = $delta;
                    }
                }
            }

            // Handle relation field from LLM output (if provided)
            if (!empty($change['relation'])) {
                // Only set relation if not already set, or if explicitly changing
                if (empty($currentRels[$target]['relation'])) {
                    $currentRels[$target]['relation'] = strtolower(trim($change['relation']));
                }
            }

            $applied[$target] = [
                'old' => $oldAff,
                'new' => $newAff,
                'delta' => $delta,
                'type' => $finalType,
                'reason' => $reason
            ];

            // Include type change info if type changed
            if ($typeChanged) {
                $applied[$target]['old_type'] = $oldType;
                $applied[$target]['type_changed'] = true;
            }

            Logger::info("[REL-LLM] {$npc['npc_name']} -> {$target}: " . sprintf("%+d", $delta) .
                      " (was {$oldAff}, now {$newAff})" . ($reason ? " - {$reason}" : ""));
        }

        if (!empty($applied)) {
            // Advisory lock to prevent race conditions
            $this->acquireNpcLock($npcId);

            try {
                // Re-fetch to get latest state after acquiring lock
                $npc = $npcMaster->getById($npcId);
                $extended = $this->safeJsonDecode($npc['extended_data'] ?? null, "applyChanges:{$npc['npc_name']}");
                if ($extended === null) {
                    // CRITICAL: Corrupted data - abort to prevent data loss
                    Logger::error("[REL-LLM] ABORT: applyChanges for {$npc['npc_name']} - corrupted extended_data");
                    $this->releaseNpcLock($npcId);
                    return []; // Return empty - changes not saved
                }

                // Merge our changes with latest state
                $existingRels = $extended['relationships'] ?? [];
                foreach ($currentRels as $target => $data) {
                    $existingRels[$target] = $data;
                }

                $extended['relationships'] = $existingRels;
                $extended['relationships_last_eval'] = date('Y-m-d H:i:s');

                $jsonData = json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $result = $npcMaster->updateByArray([
                    'id' => $npcId,
                    'extended_data' => $jsonData
                ]);

                $this->releaseNpcLock($npcId);
                Logger::debug("[REL-LLM] Database update for NPC {$npcId}: " . ($result ? "SUCCESS" : "FAILED") . " - relationships: " . json_encode($existingRels));
            } catch (Exception $e) {
                $this->releaseNpcLock($npcId);
                throw $e;
            }
        }

        return $applied;
    }

    /**
     * Get the player name
     */
    public function getPlayerName() {
        $playerName = $GLOBALS['PLAYER_NAME'] ?? 'the Player';
        // Worker sets PLAYER_NAME to 'Player' or conf.php resets to 'Prisoner' — resolve from DB
        if (in_array($playerName, ['the Player', 'Player', 'Prisoner'], true)) {
            $playerRow = $this->db->fetchOne("SELECT value FROM core_player WHERE id = 'player_name' LIMIT 1");
            if ($playerRow && !empty($playerRow['value'])) {
                $playerName = trim($playerRow['value']);
            }
        }
        return $playerName;
    }

    /**
     * Infer relationship type from affinity score
     * Auto-evolves "neutral" to appropriate type based on thresholds
     *
     * Only upgrades FROM neutral - doesn't downgrade other types
     * LLM can still set specific types (romantic, familial, etc.)
     *
     * Thresholds:
     * - +6 or higher → platonic (beyond neutral = some connection forming)
     * - -6 or lower → wary (beyond neutral = some distrust forming)
     * - -30 or lower → rival (active unfriendliness)
     * - -55 or lower → enemy (open hostility)
     */
    private function inferTypeFromAffinity($affinity) {
        // Positive thresholds - any positive relationship beyond neutral
        if ($affinity >= 6) {
            return 'platonic';  // Beyond neutral = connection forming
        }

        // Negative thresholds
        if ($affinity <= -55) {
            return 'enemy';     // Cold/Resentful tier = open hostility
        }
        if ($affinity <= -30) {
            return 'rival';     // Wary tier = active unfriendliness
        }
        if ($affinity <= -6) {
            return 'wary';      // Beyond neutral = distrust forming
        }

        // Still in neutral range (-5 to +5)
        return 'neutral';
    }
}
