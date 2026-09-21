<?php
/**
 * Minimal client for TypeSafe's System One API (the Jev model).
 *
 * Jev does not generate text. It takes a state plus a set of typed questions and returns a
 * typed answer for every question, each with calibrated probabilities:
 *
 *   POST https://api.typesafe.ai/v1/systemone
 *   Authorization: Bearer <TYPESAFE_API_KEY>
 *   {
 *     "model": "jev-latest",
 *     "state": <string | object | array>,
 *     "questions": {
 *       "<key>": {"type":"choice","instructions":"...","criteria":{"<label>":"<description or null>", ...}},
 *       "<key>": {"type":"noul","instructions":"...","criteria":{"true":"...","false":"..."}},
 *       "<key>": {"type":"score","instructions":"...","criteria":["<level 1>","<level 2>",...]}
 *     }
 *   }
 *
 *   {
 *     "model": "jev-1.x.y",
 *     "answers": {
 *       "<key>": {"type":"choice","choice":"<label>","confidence":0.91,"probabilities":{"<label>":0.91,...}},
 *       "<key>": {"type":"noul","noul":0.82},
 *       "<key>": {"type":"score","score":2.4,"confidence":0.7,"probabilities":{"<level>":0.1,...}}
 *     },
 *     "usage": {"input_tokens":382,"output_tokens":55}
 *   }
 *
 * Questions inside one request are evaluated independently and in parallel: a question cannot
 * see another question's answer. Conditional questions must therefore state their premise
 * ("assuming the action is Attack, which target?") and the caller reads only the answers that
 * apply. This client is deliberately dependency-free and mirrors the CHIM connectors' use of
 * $GLOBALS["mockConnectorSend"] so unit tests can intercept the HTTP call.
 */

class JevClientException extends Exception
{
}

class JevClient
{
    public const DEFAULT_URL         = "https://api.typesafe.ai/v1/systemone";
    public const DEFAULT_MODEL       = "jev-latest";
    public const MAX_CHOICE_OPTIONS  = 255;

    private string $apiKey;
    private string $model;
    private string $url;
    private int $timeout;

    /** @var array|null Last request body (decoded), useful for logging and dry runs. */
    public ?array $lastRequest = null;
    /** @var array|null Last full response (decoded). */
    public ?array $lastResponse = null;
    /** @var array|null Last usage block ({"input_tokens":..,"output_tokens":..}). */
    public ?array $lastUsage = null;
    /** @var float Seconds spent in the last HTTP round trip. */
    public float $lastLatency = 0.0;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $url = null, ?int $timeout = null)
    {
        if ($apiKey === null) {
            $apiKey = (string)($GLOBALS["JEV_API_KEY"] ?? "");
            if ($apiKey === "") {
                $envKey = getenv("TYPESAFE_API_KEY");
                $apiKey = ($envKey === false) ? "" : (string)$envKey;
            }
        }
        $this->apiKey  = $apiKey;
        $this->model   = $model ?? (string)($GLOBALS["JEV_MODEL"] ?? self::DEFAULT_MODEL);
        $this->url     = $url ?? (string)($GLOBALS["JEV_API_URL"] ?? self::DEFAULT_URL);
        $this->timeout = $timeout ?? intval($GLOBALS["JEV_TIMEOUT"] ?? 5);
        if ($this->model === "") {
            $this->model = self::DEFAULT_MODEL;
        }
        if ($this->url === "") {
            $this->url = self::DEFAULT_URL;
        }
        if ($this->timeout <= 0) {
            $this->timeout = 5;
        }
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== "";
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /* ------------------------------------------------------------------ */
    /* Question builders                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Pick one option out of a labelled set (2..255 options).
     * $criteria is either a list of labels or a map label => description|null.
     */
    public static function choice(string $instructions, array $criteria): array
    {
        $normalized = [];
        foreach ($criteria as $k => $v) {
            if (is_int($k)) {
                $normalized[(string)$v] = null;
            } else {
                $normalized[(string)$k] = $v;
            }
        }
        return ["type" => "choice", "instructions" => $instructions, "criteria" => $normalized];
    }

    /**
     * Calibrated yes/no. Optional criteria describe what "true" and "false" mean.
     */
    public static function noul(string $instructions, ?array $criteria = null): array
    {
        $q = ["type" => "noul", "instructions" => $instructions];
        if ($criteria !== null) {
            $q["criteria"] = $criteria;
        }
        return $q;
    }

    /**
     * Position on an ordered scale. $levels is an ordered list of at least two level labels
     * (or label => description entries).
     */
    public static function score(string $instructions, array $levels): array
    {
        $list = [];
        foreach ($levels as $k => $v) {
            if (is_int($k)) {
                $list[] = $v;
            } else {
                $list[] = [(string)$k => $v];
            }
        }
        return ["type" => "score", "instructions" => $instructions, "criteria" => $list];
    }

    /**
     * Throws when a question set cannot be sent: empty set, a choice with fewer than two or
     * more than 255 options, numeric-only labels (PHP would encode them as a JSON list), or a
     * score with fewer than two levels.
     */
    public static function validateQuestions(array $questions): void
    {
        if (count($questions) === 0) {
            throw new JevClientException("At least one question is required");
        }
        foreach ($questions as $key => $q) {
            if (!is_array($q) || !isset($q["type"])) {
                throw new JevClientException("Question '$key' has no type");
            }
            switch ($q["type"]) {
                case "choice":
                    $criteria = $q["criteria"] ?? [];
                    $n = is_array($criteria) ? count($criteria) : 0;
                    if ($n < 2) {
                        throw new JevClientException("Choice '$key' needs at least two options");
                    }
                    if ($n > self::MAX_CHOICE_OPTIONS) {
                        throw new JevClientException("Choice '$key' has $n options, the maximum is " . self::MAX_CHOICE_OPTIONS);
                    }
                    if (array_is_list($criteria)) {
                        throw new JevClientException("Choice '$key' criteria must be a label => description map with non-numeric labels");
                    }
                    break;
                case "score":
                    $levels = $q["criteria"] ?? [];
                    if (!is_array($levels) || count($levels) < 2) {
                        throw new JevClientException("Score '$key' needs at least two levels");
                    }
                    break;
                case "noul":
                    break;
                default:
                    throw new JevClientException("Question '$key' has unknown type '{$q["type"]}'");
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Request / response                                                  */
    /* ------------------------------------------------------------------ */

    public function buildRequest($state, array $questions): array
    {
        return ["model" => $this->model, "state" => $state, "questions" => $questions];
    }

    /**
     * Send one System One request and return the "answers" map.
     *
     * @param string|array $state
     * @throws JevClientException
     */
    public function decide($state, array $questions): array
    {
        if (!$this->hasApiKey()) {
            throw new JevClientException("JEV_API_KEY (or the TYPESAFE_API_KEY environment variable) is not configured");
        }
        self::validateQuestions($questions);

        $request = $this->buildRequest($state, $questions);
        $body    = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new JevClientException("Unable to encode Jev request: " . json_last_error_msg());
        }
        $this->lastRequest  = $request;
        $this->lastResponse = null;
        $this->lastUsage    = null;

        $context = stream_context_create([
            "http" => [
                "method"        => "POST",
                "header"        => "Content-Type: application/json\r\nAuthorization: Bearer {$this->apiKey}\r\n",
                "content"       => $body,
                "timeout"       => $this->timeout,
                "ignore_errors" => true,
            ],
        ]);

        $start = microtime(true);
        $raw   = $this->send($this->url, $context);
        $this->lastLatency = microtime(true) - $start;

        if ($raw === false || $raw === null || $raw === "") {
            throw new JevClientException("Jev request returned no data");
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new JevClientException("Jev returned a non-JSON response: " . substr((string)$raw, 0, 200));
        }
        $this->lastResponse = $decoded;

        if (!isset($decoded["answers"]) || !is_array($decoded["answers"])) {
            $msg = $decoded["error"]["message"] ?? ($decoded["error"] ?? ($decoded["message"] ?? "response has no answers"));
            throw new JevClientException("Jev error: " . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $this->lastUsage = isset($decoded["usage"]) && is_array($decoded["usage"]) ? $decoded["usage"] : null;

        return $decoded["answers"];
    }

    /**
     * Performs the HTTP call. Tests set $GLOBALS["mockConnectorSend"] (same hook the CHIM LLM
     * connectors use); it may return a string or a stream resource.
     *
     * @return string|false
     */
    public function send(string $url, $context)
    {
        if (isset($GLOBALS["mockConnectorSend"])) {
            $result = call_user_func($GLOBALS["mockConnectorSend"], $url, $context);
            if (is_resource($result)) {
                return stream_get_contents($result);
            }
            return $result;
        }

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            throw new JevClientException("Jev HTTP request failed: " . ($err["message"] ?? "unknown error"));
        }
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})(\s|$)/', $http_response_header[0], $m) && intval($m[1]) >= 400) {
            throw new JevClientException("Jev HTTP " . $m[1] . ": " . substr($raw, 0, 300));
        }
        return $raw;
    }

    /* ------------------------------------------------------------------ */
    /* Answer readers                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @return array|null ["choice" => label, "confidence" => float|null, "probabilities" => [label => p]]
     */
    public static function answerChoice(array $answers, string $key): ?array
    {
        $a = $answers[$key] ?? null;
        if (!is_array($a) || ($a["type"] ?? "choice") !== "choice" || !isset($a["choice"])) {
            return null;
        }
        return [
            "choice"        => (string)$a["choice"],
            "confidence"    => isset($a["confidence"]) ? floatval($a["confidence"]) : null,
            "probabilities" => (isset($a["probabilities"]) && is_array($a["probabilities"])) ? $a["probabilities"] : [],
        ];
    }

    /** Probability that the statement is true, or null when the answer is missing. */
    public static function answerNoul(array $answers, string $key): ?float
    {
        $a = $answers[$key] ?? null;
        if (!is_array($a) || !isset($a["noul"])) {
            return null;
        }
        return floatval($a["noul"]);
    }

    /**
     * @return array|null ["score" => float, "confidence" => float|null, "probabilities" => [level => p], "level" => most likely level or null]
     */
    public static function answerScore(array $answers, string $key): ?array
    {
        $a = $answers[$key] ?? null;
        if (!is_array($a) || !isset($a["score"])) {
            return null;
        }
        $probabilities = (isset($a["probabilities"]) && is_array($a["probabilities"])) ? $a["probabilities"] : [];
        $level = null;
        $best  = -1.0;
        foreach ($probabilities as $label => $p) {
            if (floatval($p) > $best) {
                $best  = floatval($p);
                $level = (string)$label;
            }
        }
        return [
            "score"         => floatval($a["score"]),
            "confidence"    => isset($a["confidence"]) ? floatval($a["confidence"]) : null,
            "probabilities" => $probabilities,
            "level"         => $level,
        ];
    }
}
