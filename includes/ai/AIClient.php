<?php
/**
 * AIClient.php — Google Gemini API wrapper for TMS AI Travel Copilot
 *
 * ✅ Drop-in replacement for the original Anthropic AIClient.php
 * ✅ Uses Google Gemini (FREE tier: 1,500 requests/day, no credit card needed)
 * ✅ Same public methods: call(), ask(), parseJson()
 *
 * FREE API KEY: https://aistudio.google.com → Get API Key → Create API Key
 */

require_once __DIR__ . '/../CacheService.php';

class AIClient {

    private string $apiKey;
    private string $model;
    private int    $maxTokens;
    private int    $cacheTtl;
    private PDO    $db;
    private CacheService $cache;

    // Gemini API base URL
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * @param PDO $db
     * @param CacheService|null $cache  Optional shared cache instance (Phase
     *   1d). If omitted, AIClient creates its own — same as before this
     *   parameter existed, so every existing `new AIClient($db)` call site
     *   keeps working unchanged. Pass the app-wide $tmsCache from
     *   includes/config.php when available so AI responses share the same
     *   cache backend (APCu/DB) as everything else instead of each feature
     *   maintaining its own cache table.
     */
    public function __construct(PDO $db, ?CacheService $cache = null) {
        $this->db        = $db;
        // ensureTable=false on the fallback: includes/config.php (loaded on
        // every page before any feature class is touched) already
        // instantiates its own CacheService and runs the migration once, so
        // repeating a CREATE TABLE IF NOT EXISTS on every single AIClient
        // construction would just be wasted queries.
        $this->cache     = $cache ?? new CacheService($db, false);
        $this->apiKey    = defined('GEMINI_API_KEY')  ? GEMINI_API_KEY  : (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
        $this->model     = defined('GEMINI_MODEL')    ? GEMINI_MODEL    : 'gemini-2.5-flash';
        $this->maxTokens = defined('AI_MAX_TOKENS')   ? AI_MAX_TOKENS   : 2000;
        $this->cacheTtl  = defined('AI_CACHE_TTL')    ? AI_CACHE_TTL    : 3600;
    }

    /**
     * Main call — identical signature to the original Anthropic AIClient.
     *
     * @param string $system     System prompt (role/context)
     * @param array  $messages   [['role'=>'user|assistant', 'content'=>'...']]
     * @param string $cacheKey   Optional cache key
     * @param string $userEmail  For rate limiting
     * @return array             ['success'=>bool, 'content'=>string, 'cached'=>bool]
     */
    public function call(
        string $system,
        array  $messages,
        string $cacheKey  = '',
        string $userEmail = 'guest'
    ): array {

        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
            return $this->fallback('No Gemini API key configured. Add your key to includes/config.php');
        }

        // ── Rate limit check ──────────────────────────────────────────────────
        if ($this->isRateLimited($userEmail)) {
            return $this->fallback('AI request limit reached. Please try again in a few minutes.');
        }

        // ── Cache check ───────────────────────────────────────────────────────
        if ($cacheKey) {
            $cached = $this->getCached($cacheKey);
            if ($cached !== null) {
                return ['success' => true, 'content' => $cached, 'cached' => true];
            }
        }

        // ── Sanitize inputs ───────────────────────────────────────────────────
        $messages = array_map(function($m) {
            $m['content'] = $this->sanitizePromptInput($m['content']);
            return $m;
        }, $messages);

        // ── Convert Anthropic-style messages → Gemini format ──────────────────
        $geminiContents = [];

        // Prepend system prompt as a "user" turn (Gemini handles system via systemInstruction)
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
            $geminiContents[] = [
                'role'  => $role,
                'parts' => [['text' => $msg['content']]],
            ];
        }

        // ── Build Gemini payload ──────────────────────────────────────────────
        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents'           => $geminiContents,
            'generationConfig'   => [
                'maxOutputTokens' => $this->maxTokens,
                'temperature'     => 0.7,
                'topP'            => 0.9,
            ],
            'safetySettings'     => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        // ── API call ──────────────────────────────────────────────────────────
        $url    = self::GEMINI_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        $result = $this->httpPost($url, $payload);

        if (!$result['success']) {
            error_log('[TMS AI Gemini] API error: ' . $result['error']);
            return $this->fallback($result['error']);
        }

        $content = $result['content'] ?? '';

        // ── Cache result ──────────────────────────────────────────────────────
        if ($cacheKey && $content) {
            $this->setCached($cacheKey, $content);
        }

        // ── Log usage ─────────────────────────────────────────────────────────
        $this->logUsage($userEmail);

        return ['success' => true, 'content' => $content, 'cached' => false];
    }

    /**
     * Single-turn call with an image attached (Gemini 2.5 Flash is natively
     * multimodal — no separate vision API/model needed). Used by the AI
     * Image Analyzer feature: user uploads a destination photo, this
     * identifies the scene so the app can recommend matching packages.
     * Deliberately NOT cached by image content (each photo is different)
     * but still goes through the same rate limiter as every other AI call
     * so this can't be used to bypass usage limits.
     *
     * @param string $system      System prompt
     * @param string $userText    Accompanying text instruction
     * @param string $imageB64    Raw base64 image data (no data: prefix)
     * @param string $mimeType    e.g. 'image/jpeg', 'image/png'
     * @param string $userEmail   For rate limiting
     */
    public function askWithImage(string $system, string $userText, string $imageB64, string $mimeType, string $userEmail = 'guest'): array {
        if (empty($this->apiKey) || $this->apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
            return $this->fallback('No Gemini API key configured. Add your key to includes/config.php');
        }
        if ($this->isRateLimited($userEmail)) {
            return $this->fallback('AI request limit reached. Please try again in a few minutes.');
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            return $this->fallback('Unsupported image type.');
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['text' => $this->sanitizePromptInput($userText)],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageB64]],
                ],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => $this->maxTokens,
                'temperature'     => 0.4, // lower than chat — this is classification, not creative writing
                'topP'            => 0.9,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        $url    = self::GEMINI_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        $result = $this->httpPost($url, $payload);

        if (!$result['success']) {
            error_log('[TMS AI Vision] API error: ' . $result['error']);
            return $this->fallback($result['error']);
        }

        $this->logUsage($userEmail);
        return ['success' => true, 'content' => $result['content'] ?? '', 'cached' => false];
    }

    /**
     * Convenience: single-turn user message.
     * Same signature as original AIClient::ask()
     */
    public function ask(string $system, string $userMessage, string $cacheKey = '', string $userEmail = 'guest'): array {
        return $this->call($system, [['role' => 'user', 'content' => $userMessage]], $cacheKey, $userEmail);
    }

    /**
     * Parse JSON from AI response. Strips markdown fences if present.
     * Identical to original AIClient::parseJson()
     */
    public function parseJson(string $content): ?array {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $clean = preg_replace('/^```\s*$/m', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Phase 2b — error handling/retries: this previously made exactly one
     * cURL attempt and gave up on any failure, including transient ones
     * (a dropped connection, Gemini returning a temporary 503, or a 429
     * rate-limit that would likely succeed a second later). Now retries up
     * to 2 additional times with exponential backoff (300ms, then 900ms) —
     * but ONLY for transient conditions. A 400 (bad request), 401/403
     * (auth), or a safety-filter block will never succeed on retry, so
     * retrying those would just waste time and (for auth errors) potentially
     * look like a hammering pattern against the API — those still fail fast
     * on the first attempt, unchanged from before.
     */
    private function httpPost(string $url, array $payload): array {
        $maxAttempts = 3;
        $backoffMs   = 300;
        $lastError   = 'Unknown error';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $isTransientNetworkError = (bool)$curlErr;
            $isTransientHttpError    = in_array($httpCode, [429, 500, 502, 503, 504], true);

            if (!$isTransientNetworkError && !$isTransientHttpError) {
                // Either a clean success, or a definitive failure (4xx other
                // than 429) that retrying won't fix — handle it below and
                // stop looping either way.
                break;
            }

            $lastError = $curlErr ? "Network error: $curlErr" : "HTTP $httpCode (transient)";
            if ($attempt < $maxAttempts) {
                usleep($backoffMs * 1000);
                $backoffMs *= 3; // 300ms -> 900ms
                continue;
            }
        }

        if ($curlErr) {
            return ['success' => false, 'error' => "Network error after $maxAttempts attempt(s): $curlErr"];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? "HTTP $httpCode — " . substr($response, 0, 200);
            return ['success' => false, 'error' => $errMsg];
        }

        // Extract text from Gemini response structure
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($content)) {
            // Check if blocked by safety filters
            $finishReason = $data['candidates'][0]['finishReason'] ?? '';
            if ($finishReason === 'SAFETY') {
                return ['success' => false, 'error' => 'Response blocked by safety filter. Try rephrasing.'];
            }
            return ['success' => false, 'error' => 'Empty response from Gemini API.'];
        }

        return ['success' => true, 'content' => $content];
    }

    private function isRateLimited(string $userEmail): bool {
        $limit  = defined('AI_RATE_LIMIT') ? AI_RATE_LIMIT : 30;
        $window = 3600;
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM tbl_ai_usage_log
                 WHERE user_email = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$userEmail, $window]);
            return (int)$stmt->fetchColumn() >= $limit;
        } catch (Exception $e) {
            return false;
        }
    }

    private function logUsage(string $userEmail): void {
        try {
            $this->db->prepare(
                "INSERT INTO tbl_ai_usage_log (user_email, created_at) VALUES (?, NOW())"
            )->execute([$userEmail]);
        } catch (Exception $e) {}
    }

    private function getCached(string $key): ?string {
        // Delegates to the shared CacheService (Phase 1d) instead of a
        // bespoke implementation piggybacking on tbl_ai_analytics (a metrics
        // table, not really a cache table — this also frees that table up
        // for genuine analytics use only). The 'ai_' prefix keeps AI cache
        // keys in their own namespace within the shared tblcache table.
        $val = $this->cache->get('ai_' . $key);
        return is_string($val) ? $val : null;
    }

    private function setCached(string $key, string $value): void {
        $this->cache->set('ai_' . $key, $value, $this->cacheTtl);
    }

    private function sanitizePromptInput(string $input): string {
        $blacklist = [
            '/ignore previous instructions/i',
            '/forget everything/i',
            '/system prompt/i',
            '/jailbreak/i',
            '/disregard all/i',
        ];
        foreach ($blacklist as $pattern) {
            $input = preg_replace($pattern, '[filtered]', $input);
        }
        return mb_substr(strip_tags(trim($input)), 0, 2000);
    }

    private function fallback(string $reason): array {
        return ['success' => false, 'content' => '', 'error' => $reason, 'cached' => false];
    }
}