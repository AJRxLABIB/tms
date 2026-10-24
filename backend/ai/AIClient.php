<?php
/**
 * AIClient.php — vendor-agnostic AI orchestrator for TourBuddy AI Travel Copilot
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
require_once __DIR__ . '/providers/OllamaProvider.php';
require_once __DIR__ . '/providers/NoneProvider.php';
require_once __DIR__ . '/AIProviderResolver.php';
require_once __DIR__ . '/AIConfig.php';
require_once __DIR__ . '/ProviderHealth.php';
require_once __DIR__ . '/ModelRegistry.php';
require_once __DIR__ . '/ModelRouter.php';
require_once __DIR__ . '/StructuredOutput.php';

class AIClient {

    private int    $maxTokens;
    /** Per-call overrides for the next call(s) (maxTokens, json, noThinking, temperature). See setCallOptions(). */
    private array  $callOptions = [];
    private int    $cacheTtl;
    private PDO    $db;
    private CacheService $cache;
    private AIProviderInterface $provider;
    /** @var AIProviderInterface[] providers tried AFTER the primary, in order (see makeFallbackProviders). */
    private array $fallbacks = [];
    /** @var callable|null test seam: fn(array $request, AIProviderInterface $p, int $timeoutSec): array{0:int,1:string,2:?string} */
    private $transport = null;
    private string $lastProviderName = '';
    private ?ModelRouter $router = null;
    /** Model that answered the last gateway call (capability routing), for callers that want to show/log it. */
    private string $lastModelName = '';

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
        $this->provider  = $provider ?? self::makeConfiguredProvider($db);
        // Fallback providers only for the normal configured path; an explicitly injected provider (tests/diagnostics) stands alone.
        if ($provider === null) { $this->fallbacks = self::makeFallbackProviders($this->provider); }
    }

    /** Replace the provider chain (primary first). For tests and diagnostics. */
    public function setProviderChain(array $providers): void {
        $this->provider  = array_shift($providers);
        $this->fallbacks = array_values($providers);
    }

    /** Test seam: replace the HTTP transport. Production never calls this. */
    public function setTransport(?callable $t): void { $this->transport = $t; }

    /**
     * AI_FALLBACK_PROVIDERS="ollama,openai" (comma list, tried in order after AI_PROVIDER). Off by default.
     * Privacy rule: a fallback is used only if it is the local 'ollama' runtime, or the same vendor, or
     * AI_FALLBACK_ALLOW_EXTERNAL=1 explicitly says conversation text may go to that other cloud vendor.
     * Only providers that are actually configured are included.
     */
    public static function makeFallbackProviders(AIProviderInterface $primary): array {
        $raw = defined('AI_FALLBACK_PROVIDERS') ? (string)AI_FALLBACK_PROVIDERS : '';
        $allowExternal = defined('AI_FALLBACK_ALLOW_EXTERNAL') && AI_FALLBACK_ALLOW_EXTERNAL;
        $out = [];
        foreach (array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', $raw))) as $name) {
            if ($name === $primary->name() || isset($out[$name])) continue;
            if ($name !== 'ollama' && !$allowExternal) {
                error_log("[TourBuddy AI] fallback provider '{$name}' ignored: sending data to a second cloud vendor needs AI_FALLBACK_ALLOW_EXTERNAL=1.");
                continue;
            }
            $p = match ($name) {
                'openai' => new OpenAIProvider(defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '', defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini', defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1'),
                'ollama' => new OllamaProvider(defined('OLLAMA_MODEL') ? OLLAMA_MODEL : '', defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'),
                'gemini' => new GeminiProvider(defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '', defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash'),
                default  => null,
            };
            if ($p === null) { error_log("[TourBuddy AI] unknown fallback provider '{$name}' ignored."); continue; }
            if (!$p->isConfigured()) { error_log("[TourBuddy AI] fallback provider '{$name}' is not configured; skipped."); continue; }
            $out[$name] = $p;
        }
        // AI_PROVIDER=auto only: if the local model fails (e.g. Ollama runs but the model was never pulled), use a valid
        // Gemini key rather than showing the offline catalogue. Explicit AI_PROVIDER values never get this implicit hop.
        $mode = defined('AI_PROVIDER') ? strtolower(trim((string)AI_PROVIDER)) : 'auto';
        if (($mode === '' || $mode === 'auto') && $primary->name() === 'ollama' && !isset($out['gemini']) && defined('GEMINI_API_KEY')
            && AIProviderResolver::geminiKeyLooksValid((string)GEMINI_API_KEY)) {
            $out['gemini'] = new GeminiProvider((string)GEMINI_API_KEY, defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash');
        }
        return array_values($out);
    }

    /** Test seam / explicit wiring for capability routing. */
    public function setModelRouter(?ModelRouter $r): void { $this->router = $r; }

    /** Router used when a call names a capability. Built lazily from OLLAMA_BASE_URL so plain calls never touch it. */
    public function modelRouter(): ModelRouter {
        if ($this->router === null) {
            $base = defined('OLLAMA_BASE_URL') ? (string)OLLAMA_BASE_URL : 'http://127.0.0.1:11434';
            $this->router = new ModelRouter(new ModelRegistry($base, null, $this->db));
        }
        return $this->router;
    }

    public function lastModel(): string { return $this->lastModelName; }

    /** Provider that produced the most recent successful answer ('' if none). */
    public function lastProvider(): string { return $this->lastProviderName; }

    /**
     * Picks the provider named by AI_PROVIDER (default 'gemini' -- the only
     * provider TourBuddy had before this round, so an unset/blank AI_PROVIDER
     * changes nothing for an existing deployment). An unrecognized value
     * logs a warning and falls back to Gemini rather than fataling.
     */
    /** @var array<string,array>|null resolved once per request */
    private static ?array $resolved = null;

    /**
     * What AI_PROVIDER means for THIS request. 'auto' (the default when unset) probes the local Ollama runtime
     * (ModelRegistry: 2 s budget, shared 30 s cache) and only then considers a plausible Gemini key; see AIProviderResolver.
     * @return array{provider:string,model:string,reason:string,detail:string}
     */
    public static function resolution(?PDO $db = null): array {
        if (self::$resolved !== null) return self::$resolved;
        $base = defined('OLLAMA_BASE_URL') ? (string)OLLAMA_BASE_URL : 'http://127.0.0.1:11434';
        $envModel = function_exists('tms_env') ? (string)tms_env('OLLAMA_MODEL', '') : '';
        $reg = new ModelRegistry($base, null, $db, 30, 10);
        self::$resolved = AIProviderResolver::resolve([
            'provider'     => defined('AI_PROVIDER') ? (string)AI_PROVIDER : 'auto',
            'gemini_key'   => defined('GEMINI_API_KEY') ? (string)GEMINI_API_KEY : '',
            'ollama_model' => $envModel !== '' ? $envModel : (defined('OLLAMA_MODEL') ? (string)OLLAMA_MODEL : ''),
        ], function () use ($reg) { return $reg->reachable() ? array_map(fn($m) => (string)$m['name'], array_values($reg->list())) : null; });
        return self::$resolved;
    }

    /** Test seam: forget the per-request resolution. */
    public static function resetResolution(): void { self::$resolved = null; }

    private static function makeConfiguredProvider(?PDO $db = null): AIProviderInterface {
        $wanted = defined('AI_PROVIDER') ? strtolower(trim((string)AI_PROVIDER)) : 'auto';

        if (!in_array($wanted, ['openai', 'ollama', 'gemini'], true)) {
            $r = self::resolution($db);
            if ($wanted !== '' && $wanted !== 'auto' && !in_array($wanted, ['none', 'off', 'disabled'], true)) {
                error_log("[TourBuddy AI] AI_PROVIDER='{$wanted}' is not recognized (expected auto, ollama, gemini, openai or none) -- treating as auto.");
            }
            if ($r['provider'] === 'ollama') {
                return new OllamaProvider($r['model'], defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434');
            }
            if ($r['provider'] === 'gemini') {
                return new GeminiProvider(defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '', defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash');
            }
            return new NoneProvider($r['reason'], $r['detail']);
        }

        if ($wanted === 'openai') {
            return new OpenAIProvider(
                defined('OPENAI_API_KEY')  ? OPENAI_API_KEY  : '',
                defined('OPENAI_MODEL')    ? OPENAI_MODEL    : 'gpt-4o-mini',
                defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1'
            );
        }

        if ($wanted === 'ollama') {
            return new OllamaProvider(
                defined('OLLAMA_MODEL')    ? OLLAMA_MODEL    : '',
                defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'
            );
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
        ?int   $cacheTtlSeconds = null,
        ?string $capability = null
    ): array {

        // Gateway capability (optional): picks the locally installed model for this kind of job, applies that job's
        // time budget and keeps the prompt inside the context window. Callers that pass none behave exactly as before.
        if ($capability !== null && !in_array($capability, ModelRouter::CAPABILITIES, true)) { $capability = null; }
        if ($capability !== null) {
            if ($totalBudgetSec === 22) $totalBudgetSec = ModelRouter::BUDGETS[$capability][0];
            if ($perAttemptSec  === 12) $perAttemptSec  = ModelRouter::BUDGETS[$capability][1];
        }
        $this->lastModelName = '';

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
        $chain = AIConfig::failoverEnabled($this->db) ? array_merge([$this->provider], $this->fallbacks) : [$this->provider];
        if (!array_filter($chain, fn($p) => $p->isConfigured())) {
            if ($this->provider->name() !== 'none') { error_log('[TourBuddy AI] Provider "' . $this->provider->name() . '" is not configured -- set its API key in .env (see .env.example).'); }  // 'none' is a normal auto-mode state: no log flood
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

        // ── Try the primary, then each fallback, within ONE overall deadline ──────
        $deadline = microtime(true) + $totalBudgetSec;
        $result = ['success' => false, 'error' => 'The AI assistant could not be reached. Please try again in a moment.'];
        $latencyMs = 0; $used = null; $tried = 0;
        foreach ($chain as $i => $prov) {
            if (!$prov->isConfigured()) continue;
            if (ProviderHealth::isOpen($this->db, $prov->name())) {
                $this->recordTelemetry($feature, $userEmail, false, false, 0, null, null, null, 'circuit_open', $prov);
                continue;
            }
            $remaining = (int)floor($deadline - microtime(true));
            if ($remaining < 3) break;
            $moreAfter = count(array_filter(array_slice($chain, $i + 1), fn($p) => $p->isConfigured() && !ProviderHealth::isOpen($this->db, $p->name())));
            $slice = $moreAfter > 0 ? max(3, (int)ceil($remaining / 2)) : $remaining; // leave time for the next provider
            $provMessages = $messages;
            $reqOptions   = array_merge(['maxTokens' => $this->maxTokens], $this->callOptions);
            $routeInfo    = ['reason' => '', 'dropped' => 0];
            if ($capability !== null && $prov instanceof OllamaProvider) {
                $routed = $this->routeOllama($prov, $capability, $system, $messages);
                if (isset($routed['error'])) {
                    $this->recordModelCall($capability, $feature, $prov, false, $routed['kind'], 0, null, null, $routed['reason'], 0);
                    $result = ['success' => false, 'error' => $routed['error'], 'kind' => $routed['kind'] === 'context' ? 'content' : 'unavailable'];
                    continue;
                }
                $prov = $routed['provider']; $provMessages = $routed['messages']; $reqOptions = $routed['options']; $routeInfo = $routed['info'];
            }
            try {
                $request = $prov->buildTextRequest($system, $provMessages, $reqOptions);
            } catch (\RuntimeException $e) {
                $result = ['success' => false, 'error' => $e->getMessage(), 'kind' => 'content'];
                continue;
            }
            $tried++;
            $t0 = microtime(true);
            $result = $this->httpPost($request, $slice, $perAttemptSec, $prov);
            $latencyMs = (int)round((microtime(true) - $t0) * 1000);
            $kind = ProviderHealth::classify($result);
            if ($capability !== null && $prov instanceof OllamaProvider) {
                $this->recordModelCall($capability, $feature, $prov, !empty($result['success']), $result['success'] ? null : $kind, $latencyMs,
                    $result['promptTokens'] ?? null, $result['completionTokens'] ?? null, $routeInfo['reason'], (int)$routeInfo['dropped']);
            }
            if ($result['success']) {
                ProviderHealth::recordSuccess($this->db, $prov->name());
                $used = $prov;
                break;
            }
            error_log('[TourBuddy AI ' . $prov->name() . '] call failed (' . $kind . '): ' . ($result['error'] ?? 'unknown error'));
            $this->recordTelemetry($feature, $userEmail, false, false, $latencyMs, null, null, null, ($result['error'] ?? 'unknown error') . ' [' . $kind . ']', $prov);
            ProviderHealth::recordFailure($this->db, $prov->name(), $kind);
            if (!ProviderHealth::shouldFailover($kind)) break; // content/safety refusals: another model must not be shopped for
        }

        if ($used === null) {
            if ($tried === 0 && !isset($result['kind'])) {
                $this->recordTelemetry($feature, $userEmail, false, false, 0, null, null, null, 'all_providers_unavailable');
                return $this->fallback('The AI assistant is temporarily unavailable. Please try again in a few minutes.');
            }
            return $this->fallback($result['error'] ?? 'The AI assistant had a problem answering that. Please try again.');
        }
        $this->lastProviderName = $used->name();
        $this->lastModelName = $used->model();

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
            $result['promptTokens'] ?? null, $result['completionTokens'] ?? null, $result['totalTokens'] ?? null, null, $used
        );

        return ['success' => true, 'content' => $content, 'cached' => false, 'provider' => $used->name(), 'model' => $used->model(), 'fallback_used' => $used->name() !== $this->provider->name()];
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
            if ($this->provider->name() !== 'none') { error_log('[TourBuddy AI] Provider "' . $this->provider->name() . '" is not configured -- set its API key in .env (see .env.example).'); }  // 'none' is a normal auto-mode state: no log flood
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
            error_log('[TourBuddy AI Vision ' . $this->provider->name() . '] call failed: ' . ($result['error'] ?? 'unknown error'));
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
    public function ask(string $system, string $userMessage, string $cacheKey = '', string $userEmail = 'guest', int $totalBudgetSec = 22, int $perAttemptSec = 12, string $feature = '', ?int $cacheTtlSeconds = null, ?string $capability = null): array {
        return $this->call($system, [['role' => 'user', 'content' => $userMessage]], $cacheKey, $userEmail, $totalBudgetSec, $perAttemptSec, $feature, $cacheTtlSeconds, $capability);
    }

    /**
     * Gateway call that must return structured data. Calls the model for the given capability, extracts the JSON,
     * validates it against $schema and, if it is invalid, asks the model ONCE to fix its own output. Never throws.
     * Callers should use their deterministic fallback when ok=false.
     *
     * @return array{ok:bool,data:mixed,errors:string[],repaired:bool,attempts:int,error:string,model:string}
     */
    public function callStructured(string $system, array $messages, array $schema, string $capability, string $userEmail = 'guest', string $feature = '', string $cacheKey = ''): array {
        $first = $this->call($system, $messages, $cacheKey, $userEmail, 22, 12, $feature, null, $capability);
        if (empty($first['success'])) {
            return ['ok' => false, 'data' => null, 'errors' => [], 'repaired' => false, 'attempts' => 0, 'error' => (string)($first['error'] ?? 'AI unavailable'), 'model' => ''];
        }
        $model = $this->lastModelName;
        $repair = function (string $errorSummary, string $original) use ($system, $userEmail, $feature, $capability): ?string {
            $fixSystem = 'You repair JSON. Reply with ONLY the corrected JSON value, no commentary, no code fences. Do not add facts that were not in the original.';
            $fixUser = "The JSON below failed validation: " . $errorSummary . "\n\nOriginal:\n" . $original;
            $r = $this->call($fixSystem, [['role' => 'user', 'content' => $fixUser]], '', $userEmail, 22, 12, $feature === '' ? 'json_repair' : $feature . '_repair', null, $capability);
            return !empty($r['success']) ? (string)$r['content'] : null;
        };
        $parsed = StructuredOutput::parse((string)$first['content'], $schema, $repair);
        $parsed['error'] = $parsed['ok'] ? '' : 'The AI answer was not in the expected format.';
        $parsed['model'] = $model;
        return $parsed;
    }

    /**
     * Parse JSON from AI response. Strips markdown fences if present.
     * Identical to original AIClient::parseJson() -- vendor-agnostic, so it
     * never needed to move into the provider classes.
     */
    /** Options for the calls that follow on THIS client: maxTokens (int), json (bool), noThinking (bool), temperature (float). [] resets. */
    public function setCallOptions(array $o): void {
        $keep = [];
        if (isset($o['maxTokens'])) $keep['maxTokens'] = max(256, min(32000, (int)$o['maxTokens']));
        foreach (['json', 'noThinking'] as $k) if (!empty($o[$k])) $keep[$k] = true;
        if (isset($o['temperature'])) $keep['temperature'] = max(0.0, min(1.5, (float)$o['temperature']));
        $this->callOptions = $keep;
    }

    /**
     * Like parseJson(), but when the model's answer was cut off (token limit) it closes the open strings / arrays / objects
     * and returns what was complete instead of nothing. $truncated says whether that repair was needed.
     */
    public function parseJsonLenient(string $content, ?bool &$truncated = null): ?array {
        $truncated = false;
        $ok = $this->parseJson($content);
        if ($ok !== null) return $ok;
        $s = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $s = trim((string)preg_replace('/^```\s*$/m', '', (string)$s));
        $start = strpos($s, '{');
        if ($start === false) return null;
        $s = substr($s, $start);
        $stack = []; $inStr = false; $esc = false; $lastSafe = 0; $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = $s[$i];
            if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{' || $ch === '[') { $stack[] = $ch === '{' ? '}' : ']'; }
            elseif ($ch === '}' || $ch === ']') { array_pop($stack); $lastSafe = $i + 1; }
            elseif ($ch === ',') { $lastSafe = $i; }
        }
        if (!$stack) return null;   // balanced yet invalid: nothing safe to repair
        // cut back to the last complete value, then close everything that is still open
        $cut = substr($s, 0, $lastSafe);
        $stack = []; $inStr = false; $esc = false;
        for ($i = 0, $m = strlen($cut); $i < $m; $i++) {
            $ch = $cut[$i];
            if ($inStr) { if ($esc) $esc = false; elseif ($ch === '\\') $esc = true; elseif ($ch === '"') $inStr = false; continue; }
            if ($ch === '"') $inStr = true;
            elseif ($ch === '{') $stack[] = '}'; elseif ($ch === '[') $stack[] = ']';
            elseif ($ch === '}' || $ch === ']') array_pop($stack);
        }
        $fixed = rtrim($cut, ", \n\r\t") . implode('', array_reverse($stack));
        $d = json_decode($fixed, true);
        if (!is_array($d)) return null;
        $truncated = true;
        return $d;
    }

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
    private function httpPost(array $request, int $totalBudgetSec = 22, int $perAttemptSec = 12, ?AIProviderInterface $prov = null): array {
        $prov = $prov ?? $this->provider;
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

            $timeout = min($perAttemptSec, max($remaining, 3));
            if ($this->transport !== null) {
                [$httpCode, $response, $curlErr] = ($this->transport)($request, $prov, $timeout);
            } else {
                $ch = curl_init($request['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $request['body'],
                    CURLOPT_HTTPHEADER     => $request['headers'],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch) ?: null;
                curl_close($ch);
            }

            $lastResult = $prov->parseResponse((int)$httpCode, (string)$response, $curlErr);

            if ($lastResult['success'] || empty($lastResult['retriable'])) {
                return $lastResult; // clean success, or a definitive failure retrying won't fix
            }

            if ($attempt < $maxAttempts) {
                usleep(($backoffMs + random_int(0, 150)) * 1000); // exponential backoff with jitter
                $backoffMs *= 2;
            }
        }

        if (!$lastResult['success']) {
            error_log('[TourBuddy AI ' . $prov->name() . '] call failed after ' . $maxAttempts . ' attempt(s): ' . ($lastResult['error'] ?? 'unknown error'));
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
            // Fail closed: if the limiter cannot count, refuse AI calls rather than allow unlimited use.
            // Exception: the usage table not existing yet (42S02, migration not run) must not take AI down.
            $missingTable = $e instanceof PDOException && (string)$e->getCode() === '42S02';
            error_log('[TourBuddy AI] rate-limit check failed (' . ($missingTable ? 'table missing, allowing' : 'blocking') . '): ' . $e->getMessage());
            return !$missingTable;
        }
    }

    /**
     * Picks the installed model for $capability and fits the conversation into its context window.
     * @return array{error:string,kind:string,reason:string}|array{provider:OllamaProvider,messages:array,options:array,info:array}
     */
    private function routeOllama(OllamaProvider $prov, string $capability, string $system, array $messages): array {
        $router = $this->modelRouter();
        $route = $router->route($capability);
        if ($route['model'] === null) {
            error_log('[TourBuddy AI] no usable local model for "' . $capability . '" (' . $route['reason'] . '); caller falls back to its non-AI path.');
            return ['error' => 'The AI assistant is not available right now.', 'kind' => 'unavailable', 'reason' => $route['reason']];
        }
        $limit = $router->contextLimit($capability);
        $fit = $router->fit($system, $messages, $limit, min($this->maxTokens, intdiv($limit, 2)));
        if (!$fit['ok']) {
            return ['error' => 'That request is too long for the AI assistant. Please shorten it and try again.', 'kind' => 'context', 'reason' => 'context_too_large'];
        }
        return [
            'provider' => $prov->withModel($route['model']),
            'messages' => $fit['messages'],
            'options'  => ['maxTokens' => (int)($this->callOptions['maxTokens'] ?? $this->maxTokens), 'numCtx' => $limit],
            'info'     => ['reason' => $route['reason'], 'dropped' => $fit['dropped'] + $fit['shortened']],
        ];
    }

    /** One row per gateway call in tbl_ai_model_calls: model name, digest and size, never prompt or answer text. Best effort. */
    private function recordModelCall(string $capability, string $feature, AIProviderInterface $prov, bool $success, ?string $errorKind, int $latencyMs, ?int $promptTokens, ?int $completionTokens, string $routeReason, int $dropped): void {
        try {
            $info = null;
            if ($prov instanceof OllamaProvider) {
                $info = $this->modelRouter()->registryInfo($prov->model());
            }
            $this->db->prepare(
                "INSERT INTO tbl_ai_model_calls (capability, feature, provider, model, model_digest, model_size, route_reason, success, error_kind, latency_ms, prompt_tokens, completion_tokens, context_dropped)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $capability, mb_substr($feature, 0, 60), $prov->name(), mb_substr($prov->model(), 0, 120),
                $info['digest'] ?? null, $info['size'] ?? null, mb_substr($routeReason, 0, 30), $success ? 1 : 0,
                $errorKind !== null ? mb_substr($errorKind, 0, 20) : null, $latencyMs, $promptTokens, $completionTokens, min(32000, $dropped),
            ]);
        } catch (\Throwable $e) { /* table not migrated yet, or DB busy: telemetry must never break an answer */ }
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
        ?int $promptTokens, ?int $completionTokens, ?int $totalTokens, ?string $error, ?AIProviderInterface $prov = null
    ): void {
        $prov = $prov ?? $this->provider;
        try {
            $this->db->prepare(
                "INSERT INTO tbl_ai_telemetry
                    (provider, model, feature, user_email, success, cached, latency_ms, prompt_tokens, completion_tokens, total_tokens, error_message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $prov->name(),
                $prov->model(),
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
            // Remove real markup only. strip_tags() also ate text after a bare
            // "<" ("budget < 20000 and 3 days" lost everything after the "<").
            $input = preg_replace('/<\s*\/?\s*[a-z][^>]*>/i', '', $input) ?? $input;
        }
        return mb_substr($input, 0, 4000);
    }

    private function fallback(string $reason): array {
        return ['success' => false, 'content' => '', 'error' => $reason, 'cached' => false];
    }
}
