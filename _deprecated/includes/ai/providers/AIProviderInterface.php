<?php
/**
 * AIProviderInterface.php — Round 34 (item 21: "AI Provider Abstraction +
 * telemetry")
 *
 * Before this, AIClient.php hardcoded Google Gemini's exact request/response
 * shape directly inside its own retry loop (see the old AIClient.php's
 * httpPost()) -- switching vendors, or adding a second one as a fallback,
 * meant editing AIClient itself. AIClient now owns everything that does NOT
 * vary by vendor -- caching, rate limiting, input sanitization, the
 * retry/backoff loop, and telemetry -- and delegates only the two things
 * that genuinely differ per provider (how to build the outbound HTTP
 * request, how to interpret the HTTP response) to a small object
 * implementing this interface.
 *
 * Every implementation MUST be safe to construct even with no API key set
 * (isConfigured() reports that instead of throwing) so AIClient can give
 * every caller the same clean "AI assistant is not switched on" fallback it
 * always has, regardless of which provider is selected.
 */
interface AIProviderInterface {

    /** Short machine name stored in telemetry rows, e.g. 'gemini', 'openai'. */
    public function name(): string;

    /** The actual model identifier in use, for telemetry/logs (e.g. 'gemini-2.5-flash'). */
    public function model(): string;

    /** True if this provider has the configuration (API key, etc.) it needs to run. */
    public function isConfigured(): bool;

    /**
     * Build the outbound HTTP request for a normal (text-only) call.
     *
     * @param string $system    System prompt / role instruction.
     * @param array  $messages  [['role' => 'user'|'assistant', 'content' => string], ...]
     *                          Already sanitized by AIClient -- providers
     *                          should not re-sanitize.
     * @param array  $options   ['maxTokens' => int, 'temperature' => float]
     * @return array{url:string, headers:array<int,string>, body:string}
     */
    public function buildTextRequest(string $system, array $messages, array $options): array;

    /**
     * Build the outbound HTTP request for a single-turn call with one image
     * attached (used by the AI Image Analyzer feature). Providers that don't
     * support vision may throw \RuntimeException -- AIClient will surface
     * that as a normal fallback message, never a fatal error to the visitor.
     *
     * @return array{url:string, headers:array<int,string>, body:string}
     */
    public function buildImageRequest(string $system, string $userText, string $imageB64, string $mimeType, array $options): array;

    /**
     * Parse one raw HTTP response into a normalized result. Called once per
     * attempt inside AIClient's retry loop, so this is also where each
     * provider classifies ITS OWN vendor-specific error shapes (e.g. Gemini's
     * "per day" quota wording vs OpenAI's `error.type`) as retriable or not --
     * AIClient's loop just trusts the `retriable` flag, it doesn't know or
     * care which vendor produced it.
     *
     * @param int         $httpCode
     * @param string      $rawBody
     * @param string|null $curlError  Set when the HTTP request itself never
     *                                completed (network/timeout) -- httpCode
     *                                will be 0 in that case.
     * @return array{
     *   success: bool,
     *   content?: string,
     *   error?: string,
     *   retriable?: bool,
     *   promptTokens?: int,
     *   completionTokens?: int,
     *   totalTokens?: int
     * }
     */
    public function parseResponse(int $httpCode, string $rawBody, ?string $curlError): array;
}
