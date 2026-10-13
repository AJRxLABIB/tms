<?php
/**
 * AIClient.php — vendor-agnostic AI orchestrator for TMS AI Travel Copilot
 *
 * Round 34 (item 21, "AI Provider Abstraction + telemetry"): this class used
 * to hardcode Google Gemini's request/response shape directly inside its own
 * retry loop (a straight port of the original Anthropic-based AIClient
 * before that). It now delegates the vendor-specific parts to a small
 * AIProviderInterface implementation (see includes/ai/providers/) chosen via
 * the AI_PROVIDER config constant, and keeps everything that never varied by
 * vendor here: caching, rate limiting, input sanitization, retry/backoff,
 * and now telemetry (one row per call in tbl_ai_telemetry: provider, model,
 * feature, latency, success, tokens where the provider reports them).
 *
 * ✅ Every existing public method signature is unchanged except one new
 *    trailing optional `$feature` parameter (a short tag like 'trip_copilot'
 *    used only for telemetry) -- every pre-existing call site across the 9
 *    other AI feature classes keeps working exactly as before whether or not
 *    it passes one.
 * ✅ Default provider stays Gemini, so an existing .env with only
 *    GEMINI_API_KEY set behaves identically to before this round.
 */

require_once __DIR__ . '/../CacheService.php';
require_once __DIR__ . '/providers/AIProviderInterface.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/OpenAIProvider.php';

class AIClient {

    private int    $maxTokens;
    private int    $cacheTtl;
    private PDO    $db;
    private CacheService $cache;
    private AIProviderInterface $provider;

    /**
     * @param PDO $db
     * @param CacheService|null $cache  Optional shared cache instance (Phase
     *   1d). If omitted, AIClient creates its own -- same as before this
     *   parameter existed, so every existing `new AIClient($db)` call site
     *   keeps working unchanged.
     * @param AIProviderInterface|null $provider  Optional explicit provider
     *   override, mainly for tests/diagnostics. If omitted (the normal
     *   case), the provider is chosen from the AI_PROVIDER config constant.
     */
    public function __construct(PDO $db, ?CacheService $cache = null, ?AIProviderInterface $provider = null) {
        $this->db        = $db;
        // ensureTable=false on the fallback: includes/config.php (loaded on
        // every page before any feature class is touched) already
        // instantiates its own CacheService and runs the migration once, so
        // repeating a CREATE TABLE IF NOT EXISTS on every single AIClient
        // construction would just be wasted queries.
        $this->cache     = $cache ?? new CacheService($db, false);
        $this->maxTokens = defined('AI_MAX_TOKENS') ? AI_MAX_TOKENS : 2000;
        $this->cacheTtl  = defined('AI_CACHE_TTL')  ? AI_CACHE_TTL  : 3600;
        $this->provider  = $provider ?? self::makeConfiguredProvider();
    }

    /**
     * Picks the provider named by AI_PROVIDER (default 'gemini' -- the only
     * provider TMS had before this round, so an unset/blank AI_PROVIDER
     * changes nothing for an existing deployment). An unrecognized value
     * logs a warning and falls back to Gemini rather than fataling.
     */
    private static function makeConfiguredProvider(): AIProviderInterface {
        $wanted = defined('AI_PROVIDER') ? strtolower(trim((string)AI_PROVIDER)) : 'gemini';

        if ($wanted === 'openai') {
            return new OpenAIProvider(
                defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : '',
                defined('OPENAI_MODEL')    ? OPENAI_MODEL    : 'gpt-4o-mini',
                defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1'
            );
        }

        if ($wanted !== 'gemini' && $wanted !== '') {
            error_log("[TMS AI] AI_PROVIDER='{$wanted}' is not recognized (expected 'gemini' or 'openai') -- using Gemini.");
        }

        // Round 113: dropped a fallback to ANTHROPIC_API_KEY that lived here --
        // this branch only ever constructs a GeminiProvider (the 'openai'
        // branch above is the only other option), and an Anthropic-shaped key
        // was never going to work as a Gemini key. Vestigial from the
        // pre-Round-34 Anthropic-based AIClient this file's own header
        // docblock already says was replaced; harmless but misleading, since
        // no other file in this codebase reads ANTHROPIC_API_KEY at all.
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $model  = defined('GEMINI_MODEL')   ? GEMINI_MODEL   : 'gemini-2.5-flash';
        return new GeminiProvider($apiKey, $model);
    }

    /**
     * Main call — identical signature to the original Anthropic AIClient,
     * plus one new optional trailing $feature tag for telemetry.
     *
     * @param string $system     System prompt (role/context)
     * @param array  $messages   [['role'=>'user|assistant', 'content'=>'...']]
     * @param string $cacheKey   Optional cache key
     * @param string $userEmail  For rate limiting
     * @param string $feature    Optional short tag identifying which AI
     *                           feature is calling (e.g. 'trip_copilot',
     *                           'itinerary') -- recorded in telemetry only,
     *                           never sent to the provider.
     * @return array             ['success'=>bool, 'content'=>string, 'cached'=>bool]
     */
    public function call(
        string $system,
        array  $messages,
        string $cacheKey  = '',
        string $userEmail = 'guest',
        int    $totalBudgetSec = 22,
        int    $perAttemptSec  = 12,
        string $feature = '',
        ?int   $cacheTtlSeconds = null
    ): array {

        // Round 95 FIX (severity: critical, live, previously undetected):
        // this whole method -- along with askWithImage() and httpPost() right
        // below -- was still the pre-Round-34 Gemini-hardcoded body, left
        // behind untouched when the AIProviderInterface abstraction shipped.
        // It referenced $this->apiKey, $this->model and self::GEMINI_BASE --
        // none of which exist ANYWHERE on this class (the constructor only
        // ever sets $this->provider) -- so `empty($this->apiKey)` was always
        // true and every single real AI feature (recommender, sentiment,
        // itinerary, budget tools, crowd predictor, package generator, site
        // assistant chatbot, NL search) has been silently returning the
        // generic "not switched on" fallback since Round 34, REGARDLESS of a
        // valid GEMINI_API_KEY in .env -- masking a second, worse bug one
        // line further down (`self::GEMINI_BASE` isn't a defined constant at
        // all -- a fatal "Undefined constant" error was one working API key
        // away from happening on every real call). Found only now because
        // Round 95's AI Evaluation suite finally checks tbl_ai_telemetry
        // ground truth instead of trusting a fallback's still-valid-looking
        // JSON shape -- exactly the gap that suite was built to catch.
        // Fixed by actually routing through $this->provider, as the Round 34
        // docblock always claimed this method did.
        if (!$this->provider->isConfigured()) {
            error_log('[TMS AI] Provider "' . $this->provider->name() . '" is not configured -- set its API key in .env (see .env.example).');
            return $this->fallback('The AI assistant is not switched on for this site yet.');
        }

        // ── Rate limit check ──────────────────────────────────────────────────
        if ($this->isRateLimited($userEmail)) {
            $this->recordTelemetry($feature, $userEmail, false, false, 0, null, null, null, 'rate_limited');
            return $this->fallback('AI request limit reached. Please try again in a few minutes.');
        }

        // ── Cache check ───────────────────────────────────────────────────────
        if ($cacheKey) {
            $cached = $this->getCached($cacheKey);
            if ($cached !== null) {
                $this->recordTelemetry($feature, $userEmail, true, true, 0, null, null, null, null);
                return ['success' => true, 'content' => $cached, 'cached' => true];
            }
        }

        // ── Sanitize inputs ───────────────────────────────────────────────────
        $messages = array_map(function($m) {
            $m['content'] = $this->sanitizePromptInput((string)($m['content'] ?? ''), $m['role'] ?? 'user');
            return $m;
        }, $messages);

        // ── Build the provider-specific request (vendor payload shape lives
        // in the provider class now, not here) ────────────────────────────
        try {
            $request = $this->provider->buildTextRequest($system, $messages, ['maxTokens' => $this->maxTokens]);
        } catch (\RuntimeException $e) {
            return $this->fallback($e->getMessage());
        }

        // ── API call (generic retry/backoff; vendor-specific parsing via
        // the provider's own parseResponse()) ──────────────────────────────
        $startedAt = microtime(true);
        $result    = $this->httpPost($request, $totalBudgetSec, $perAttemptSec);
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        if (!$result['success']) {
            error_log('[TMS AI ' . $this->provider->name() . '] call failed: ' . ($result['error'] ?? 'unknown error'));
            $this->recordTelemetry($feature, $userEmail, false, false, $latencyMs, null, null, null, $result['error'] ?? 'unknown error');
            return $this->fallback($result['error'] ?? 'The AI assistant had a problem answering that. Please try again.');
        }

        $content = $result['content'] ?? '';

        // ── Cache result ──────────────────────────────────────────────────────
        // Round 113 (AI-upgrade priority, speed pass): callers can now ask for
        // a TTL other than the one-size-fits-all AI_CACHE_TTL. Nothing here
        // changes for a caller that doesn't pass one -- $cacheTtlSeconds
        // defaults to null, which setCached() below treats as "use the
        // existing global default", exactly the prior behavior.
        if ($cacheKey && $content) {
            $this->setCached($cacheKey, $content, $cacheTtlSeconds);
        }

        // ── Log usage (rate limiting) + telemetry ───────────────────────────────
        $this->logUsage($userEmail);
        $this->recordTelemetry(
            $feature, $userEmail, true, false, $latencyMs,
            $result['promptTokens'] ?? null, $result['completionTokens'] ?? null, $result['totalTokens'] ?? null, null
        );

        return ['success' => true, 'content' => $content, 'cached' => false];
    }

    /**
     * Single-turn call with an image attached (AI Image Analyzer). Same
     * contract as before, plus the optional $feature telemetry tag.
     *
     * Round 95 FIX: api/ai.php's image-classifier call site
     * (`$ai->askWithImage(..., feature: 'image_classifier')`) passes $feature
     * as a NAMED argument, but this method's signature had no $feature
     * parameter at all -- every image-analyzer request was hitting a fatal
     * "Unknown named parameter $feature" error, not just a graceful
     * fallback. Added here, matching call()/ask()'s existing convention.
     *
     * @param string $system      System prompt
     * @param string $userText    Accompanying text instruction
     * @param string $imageB64    Raw base64 image data (no data: prefix)
     * @param string $mimeType    e.g. 'image/jpeg', 'image/png'
     * @param string $userEmail   For rate limiting
     * @param string $feature     Optional telemetry tag, see call().
     */
    public function askWithImage(string $system, string $userText, string $imageB64, string $mimeType, string $userEmail = 'guest', string $feature = ''): array {
        // Round 95 FIX: same root cause as call() above -- see that method's
        // comment for the full story (undefined $this->apiKey/$this->model/
        // self::GEMINI_BASE meant this always silently fell back, never
        // actually reaching the real API).
        if (!$this->provider->isConfigured()) {
            error_log('[TMS AI] Provider "' . $this->provider->name() . '" is not configured -- set its API key in .env (see .env.example).');
            return $this->fallback('The AI assistant is not switched on for this site yet.');
        }
        if ($this->isRateLimited($userEmail)) {
            $this->recordTelemetry($feature, $userEmail, false, false, 0, null, null, null, 'rate_limited');
            return $this->fallback('AI request limit reached. Please try again in a few minutes.');
        }
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            return $this->fallback('Unsupported image type.');
        }

        try {
            $request = $this->provider->buildImageRequest(
                $system, $this->sanitizePromptInput($userText), $imageB64, $mimeType, ['maxTokens' => $this->maxTokens]
            );
        } catch (\RuntimeException $e) {
            return $this->fallback($e->getMessage());
        }

        $startedAt = microtime(true);
        $result    = $this->httpPost($request);
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

        if (!$result['success']) {
            error_log('[TMS AI Vision ' . $this->provider->name() . '] call failed: ' . ($result['error'] ?? 'unknown error'));
            $this->recordTelemetry($feature, $userEmail, false, false, $latencyMs, null, null, null, $result['error'] ?? 'unknown error');
            return $this->fallback($result['error'] ?? 'The AI assistant had a problem answering that. Please try again.');
        }

        $this->logUsage($userEmail);
        $this->recordTelemetry(
            $feature, $userEmail, true, false, $latencyMs,
            $result['promptTokens'] ?? null, $result['completionTokens'] ?? null, $result['totalTokens'] ?? null, null
        );
        return ['success' => true, 'content' => $result['content'] ?? '', 'cached' => false];
    }

    /**
     * Convenience: single-turn user message.
     * Same signature as original AIClient::ask(), plus optional $feature.
     */
    public function ask(string $system, string $userMessage, string $cacheKey = '', string $userEmail = 'guest', int $totalBudgetSec = 22, int $perAttemptSec = 12, string $feature = '', ?int $cacheTtlSeconds = null): array {
        return $this->call($system, [['role' => 'user', 'content' => $userMessage]], $cacheKey, $userEmail, $totalBudgetSec, $perAttemptSec, $feature, $cacheTtlSeconds);
    }

    /**
     * Parse JSON from AI response. Strips markdown fences if present.
     * Identical to original AIClient::parseJson() -- vendor-agnostic, so it
     * never needed to move into the provider classes.
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
     * Generic HTTP retry/backoff loop -- provider-agnostic. This is the same
     * timing behavior AIClient always had (see the FIX comments below, kept
     * verbatim from before this round since the reasoning still applies
     * unchanged); only the per-attempt request/response handling now goes
     * through whichever AIProviderInterface is configured instead of being
     * Gemini-specific.
     *
     * FIX (Round 14): CURLOPT_TIMEOUT was 30s with no connect timeout, inside
     * a 3-attempt retry loop — a worst case of ~91 seconds for an
     * INTERACTIVE chat request. XAMPP's default max_execution_time is 30-60s,
     * so PHP killed the script mid-retry and the browser got an HTML fatal
     * instead of JSON. These values keep the whole operation inside a
     * sensible interactive budget.
     *
     * FIX (live testing, round 19): a full multi-day itinerary is a
     * fundamentally different workload from an interactive chat reply --
     * my-trip.php's own UI copy already promises "Takes about 15-30
     * seconds" -- but every AIClient call shared this same 12s/22s budget,
     * so Gemini would still be generating a large structured JSON response
     * when curl gave up, got treated as a transient network error, retried
     * (wasting more time), and still exhausted the 22s budget before a
     * slow-but-otherwise-successful response ever completed. ItineraryPlanner
     * now passes a longer budget explicitly for this workload; the defaults
     * here are unchanged, so every other caller (chatbot, image recognition,
     * etc.) keeps the original tight interactive timeout.
     *
     * @param array{url:string,headers:array<int,string>,body:string} $request
     *
     * Round 95 FIX: this used to build the Gemini payload AND parse the
     * Gemini-shaped response directly inline (`self::GEMINI_BASE`,
     * `$this->apiKey` -- neither ever existed on this class after the
     * Round 34 provider-abstraction refactor, so this whole method was
     * unreachable dead code; see call()'s comment for the full story). Now
     * takes an already-built provider-agnostic request and hands the raw
     * HTTP outcome to $this->provider->parseResponse() for interpretation,
     * so this loop is genuinely vendor-agnostic and works for Gemini,
     * OpenAI, or any future provider without changes here.
     */
    private function httpPost(array $request, int $totalBudgetSec = 22, int $perAttemptSec = 12): array {
        // FIX (Round 14): CURLOPT_TIMEOUT was 30s with no connect timeout, inside
        // a 3-attempt retry loop — a worst case of ~91 seconds for an
        // INTERACTIVE chat request. XAMPP's default max_execution_time is 30-60s,
        // so PHP killed the script mid-retry and the browser got an HTML fatal
        // instead of JSON. These values keep the whole operation inside a
        // sensible interactive budget.
        $maxAttempts = 3;
        $backoffMs   = 400;
        $startedAt   = microtime(true);

        $lastResult = ['success' => false, 'error' => 'The AI assistant could not be reached. Please try again in a moment.'];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $remaining = $totalBudgetSec - (int)(microtime(true) - $startedAt);
            if ($remaining < 3) break;

            $ch = curl_init($request['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $request['body'],
                CURLOPT_HTTPHEADER     => $request['headers'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => min($perAttemptSec, max($remaining, 3)),
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch) ?: null;
            curl_close($ch);

            $lastResult = $this->provider->parseResponse($httpCode, (string)$response, $curlErr);

            if ($lastResult['success'] || empty($lastResult['retriable'])) {
                return $lastResult; // clean success, or a definitive failure retrying won't fix
            }

            if ($attempt < $maxAttempts) {
                usleep($backoffMs * 1000);
                $backoffMs *= 2;
            }
        }

        if (!$lastResult['success']) {
            error_log('[TMS AI ' . $this->provider->name() . '] call failed after ' . $maxAttempts . ' attempt(s): ' . ($lastResult['error'] ?? 'unknown error'));
        }

        return $lastResult;
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

    /**
     * Round 34 telemetry: one row per call attempt into tbl_ai_telemetry,
     * separate from tbl_ai_usage_log (which exists purely for rate limiting
     * and stays untouched). Best-effort -- a telemetry write must never be
     * able to break an AI response, so failures are swallowed exactly like
     * logUsage() already does.
     */
    private function recordTelemetry(
        string $feature, string $userEmail, bool $success, bool $cached, int $latencyMs,
        ?int $promptTokens, ?int $completionTokens, ?int $totalTokens, ?string $error
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO tbl_ai_telemetry
                    (provider, model, feature, user_email, success, cached, latency_ms, prompt_tokens, completion_tokens, total_tokens, error_message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $this->provider->name(),
                $this->provider->model(),
                $feature !== '' ? $feature : 'unspecified',
                $userEmail !== '' ? $userEmail : 'guest',
                $success ? 1 : 0,
                $cached ? 1 : 0,
                $latencyMs,
                $promptTokens,
                $completionTokens,
                $totalTokens,
                $error !== null ? mb_substr($error, 0, 250) : null,
            ]);
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

    private function setCached(string $key, string $value, ?int $ttlSeconds = null): void {
        $this->cache->set('ai_' . $key, $value, $ttlSeconds ?? $this->cacheTtl);
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
