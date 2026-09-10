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
            // FIX (Round 14): this exact string was rendered to visitors — the
            // first thing anyone saw on Explore Mode was "Add your key to
            // includes/config.php". The operator detail belongs in the log.
            error_log('[TMS AI] No GEMINI_API_KEY configured — set it in .env (see .env.example).');
            return $this->fallback('The AI assistant is not switched on for this site yet.');
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
            $m['content'] = $this->sanitizePromptInput((string)($m['content'] ?? ''), $m['role'] ?? 'user');
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
                // Round 14: all four were BLOCK_ONLY_HIGH, the loosest available
                // setting, on a public consumer travel site.
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ],
        ];

        // ── API call ──────────────────────────────────────────────────────────
        // Round 14: the key used to travel in the query string, which lands in
        // every proxy access log. Gemini accepts it as a header instead.
        $url    = self::GEMINI_BASE . $this->model . ':generateContent';
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
            // FIX (Round 14): this exact string was rendered to visitors — the
            // first thing anyone saw on Explore Mode was "Add your key to
            // includes/config.php". The operator detail belongs in the log.
            error_log('[TMS AI] No GEMINI_API_KEY configured — set it in .env (see .env.example).');
            return $this->fallback('The AI assistant is not switched on for this site yet.');
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

        $url    = self::GEMINI_BASE . $this->model . ':generateContent';
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
        // FIX (Round 14): CURLOPT_TIMEOUT was 30s with no connect timeout, inside
        // a 3-attempt retry loop — a worst case of ~91 seconds for an
        // INTERACTIVE chat request. XAMPP's default max_execution_time is 30-60s,
        // so PHP killed the script mid-retry and the browser got an HTML fatal
        // instead of JSON. These values keep the whole operation inside a
        // sensible interactive budget.
        $maxAttempts   = 3;
        $backoffMs     = 400;
        $perAttemptSec = 12;
        $totalBudgetSec = 22;
        $startedAt     = microtime(true);

        // Encoding can fail on invalid UTF-8 (easy to send in a raw POST), which
        // used to post an empty body and surface as a baffling "HTTP 400".
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            return ['success' => false, 'error' => 'Could not encode the request (invalid characters in the message).'];
        }

        $response = false; $httpCode = 0; $curlErr = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $remaining = $totalBudgetSec - (int)(microtime(true) - $startedAt);
            if ($remaining < 3) break;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => min($perAttemptSec, $remaining),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $isTransientNetworkError = (bool)$curlErr;
            $isTransientHttpError    = in_array($httpCode, [429, 500, 502, 503, 504], true);

            // A 429 is either a per-minute burst (worth one short retry) or the
            // daily free-tier quota being spent (retrying is pure waste and just
            // hammers the API). Tell them apart from the response body.
            if ($httpCode === 429 && is_string($response) && stripos($response, 'per day') !== false) {
                return ['success' => false, 'error' => 'The AI assistant has reached today\'s usage limit. Please try again tomorrow.'];
            }

            if (!$isTransientNetworkError && !$isTransientHttpError) {
                break; // clean success, or a definitive failure retrying won't fix
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffMs * 1000);
                $backoffMs *= 2;
                continue;
            }
        }

        if ($curlErr) {
            error_log('[TMS AI] network error after ' . $maxAttempts . ' attempt(s): ' . $curlErr);
            return ['success' => false, 'error' => 'The AI assistant could not be reached. Please try again in a moment.'];
        }

        $data = json_decode((string)$response, true);

        if ($httpCode !== 200) {
            $detail = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' — ' . substr((string)$response, 0, 200));
            error_log('[TMS AI] Gemini returned HTTP ' . $httpCode . ': ' . $detail);
            if ($httpCode === 401 || $httpCode === 403) {
                return ['success' => false, 'error' => 'The AI assistant is not configured correctly on this site.'];
            }
            if ($httpCode === 429) {
                return ['success' => false, 'error' => 'The AI assistant is busy right now. Please try again in a minute.'];
            }
            return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.'];
        }

        // A prompt blocked BEFORE generation is reported at the top level, not on
        // the candidate — that case used to fall through to the generic
        // "Empty response from Gemini API."
        $blockReason = $data['promptFeedback']['blockReason'] ?? '';
        if ($blockReason !== '') {
            return ['success' => false, 'error' => 'That message could not be processed. Please rephrase it and try again.'];
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($content)) {
            $finishReason = $data['candidates'][0]['finishReason'] ?? '';
            if ($finishReason === 'SAFETY') {
                return ['success' => false, 'error' => 'The answer was blocked by a safety filter. Try rephrasing your question.'];
            }
            if ($finishReason === 'MAX_TOKENS') {
                return ['success' => false, 'error' => 'That answer was too long to finish. Try asking something more specific.'];
            }
            error_log('[TMS AI] empty Gemini response (finishReason=' . $finishReason . ')');
            return ['success' => false, 'error' => 'The AI assistant returned an empty answer. Please try again.'];
        }

        return ['success' => true, 'content' => $content];
    }

    private function isRateLimited(string $userEmail): bool {
        // FIX (Round 14): every signed-out visitor was logged under the literal
        // string 'guest', so the 31st AI call from ANY guest in a rolling hour
        // locked out the chatbot for EVERY guest on the site. Callers still pass
        // 'guest'; we scope it per browser session here so one visitor can no
        // longer exhaust everyone else's allowance.
        if ($userEmail === 'guest' || $userEmail === '') {
            $sid = session_id();
            $userEmail = 'guest:' . ($sid !== '' ? substr($sid, 0, 24) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }
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
        // Must match the key isRateLimited() counts, or the limit never triggers.
        if ($userEmail === 'guest' || $userEmail === '') {
            $sid = session_id();
            $userEmail = 'guest:' . ($sid !== '' ? substr($sid, 0, 24) : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }
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

    /**
     * FIX (Round 14): this used to regex-replace five English phrases
     * ("ignore previous instructions", "system prompt", "jailbreak", ...) with
     * "[filtered]". That stopped nothing — two spaces, a synonym, another
     * language or base64 all sail past — while corrupting legitimate messages:
     * a visitor asking "what's in the system prompt for this bot?" got
     * "[filtered]", and a review saying "forget everything I said about the
     * hotel" was rewritten before the sentiment analyser scored it. Worse, it
     * created false confidence while the defences that actually matter were
     * missing (structural role separation, never putting untrusted text in the
     * system instruction, and escaping model output at the DOM sink — all three
     * now in place).
     *
     * strip_tags is also no longer applied to assistant turns: the site
     * assistant is instructed to answer in HTML anchors, and stripping them from
     * its own replayed history taught it that its previous format was wrong.
     */
    private function sanitizePromptInput(string $input, string $role = 'user'): string {
        $input = trim($input);
        if ($role !== 'assistant') {
            $input = strip_tags($input);
        }
        return mb_substr($input, 0, 4000);
    }

    private function fallback(string $reason): array {
        return ['success' => false, 'content' => '', 'error' => $reason, 'cached' => false];
    }
}