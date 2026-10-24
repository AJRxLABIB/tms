<?php
require_once __DIR__ . '/AIProviderInterface.php';

/**
 * OpenAIProvider.php — Round 34 (item 21: "AI Provider Abstraction").
 *
 * Implements AIProviderInterface against OpenAI's Chat Completions API
 * (`POST {base}/chat/completions`), which is also what most
 * OpenAI-compatible third-party endpoints (self-hosted or otherwise) speak --
 * OPENAI_BASE_URL can be pointed at any of them, not just api.openai.com.
 *
 * ⚠️ VERIFICATION NOTE (Rule 4): this session's web access was blocked when
 * I tried to re-confirm the current Chat Completions request/response shape
 * against platform.openai.com/docs before writing this, so it is built from
 * the long-stable, documented shape of that API rather than a freshly
 * checked source. This is why TourBuddy ships with AI_PROVIDER=gemini as the
 * default (unchanged from before this round) and this provider has NOT been
 * exercised against a real OpenAI account in this environment (no
 * OPENAI_API_KEY configured here to test with). Before relying on this in
 * production: set AI_PROVIDER=openai + OPENAI_API_KEY in .env, confirm the
 * request/response shape against https://platform.openai.com/docs/api-reference/chat
 * for the model you configure, and run a real request end-to-end. See
 * SESSION_SUMMARY_INDEX.md #34 for the full "implemented, not live-tested"
 * status.
 */
class OpenAIProvider implements AIProviderInterface {

    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(string $apiKey, string $model, string $baseUrl) {
        $this->apiKey  = $apiKey;
        $this->model   = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function name(): string { return 'openai'; }
    public function model(): string { return $this->model; }
    public function isConfigured(): bool { return $this->apiKey !== ''; }

    public function buildTextRequest(string $system, array $messages, array $options): array {
        $chatMessages = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
            $chatMessages[] = ['role' => $role, 'content' => (string)($msg['content'] ?? '')];
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $chatMessages,
            'max_tokens'  => $options['maxTokens'] ?? 2000,
            'temperature' => 0.7,
        ];

        return $this->buildRequest($payload);
    }

    public function buildImageRequest(string $system, string $userText, string $imageB64, string $mimeType, array $options): array {
        // Multimodal content-array format for vision-capable models
        // (e.g. gpt-4o) -- see the class-level verification note above.
        $payload = [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => [
                        'url' => 'data:' . $mimeType . ';base64,' . $imageB64,
                    ]],
                ]],
            ],
            'max_tokens'  => $options['maxTokens'] ?? 2000,
            'temperature' => 0.4,
        ];

        return $this->buildRequest($payload);
    }

    private function buildRequest(array $payload): array {
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new RuntimeException('Could not encode the request (invalid characters in the message).');
        }
        return [
            'url' => $this->baseUrl . '/chat/completions',
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            'body' => $body,
        ];
    }

    public function parseResponse(int $httpCode, string $rawBody, ?string $curlError): array {
        if ($curlError) {
            return ['success' => false, 'error' => 'The AI assistant could not be reached. Please try again in a moment.', 'kind' => 'unavailable', 'retriable' => true];
        }

        $data = json_decode($rawBody, true);

        if ($httpCode !== 200) {
            $errType = $data['error']['type'] ?? '';
            $errCode = $data['error']['code'] ?? '';
            $detail  = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' — ' . substr($rawBody, 0, 200));
            error_log('[TourBuddy AI OpenAI] API error: HTTP ' . $httpCode . ': ' . $detail);

            if ($httpCode === 401 || $httpCode === 403) {
                return ['success' => false, 'error' => 'The AI assistant is not configured correctly on this site.', 'kind' => 'config', 'retriable' => false];
            }
            if ($httpCode === 429) {
                // OpenAI distinguishes an exhausted billing quota (permanent
                // until the account is topped up -- retrying is pure waste)
                // from a transient per-minute rate limit (worth one retry)
                // via error.code/type, not response wording -- this
                // classification is OpenAI-specific by design, mirroring how
                // GeminiProvider reads Gemini's own wording for the same
                // distinction.
                if ($errCode === 'insufficient_quota' || $errType === 'insufficient_quota') {
                    return ['success' => false, 'error' => 'The AI assistant has reached its usage limit. Please try again later.', 'kind' => 'quota', 'retriable' => false];
                }
                return ['success' => false, 'error' => 'The AI assistant is busy right now. Please try again in a minute.', 'kind' => 'unavailable', 'retriable' => true];
            }
            if (in_array($httpCode, [500, 502, 503, 504], true)) {
                return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.', 'kind' => 'unavailable', 'retriable' => true];
            }
            return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.', 'kind' => 'content', 'retriable' => false];
        }

        $choice       = $data['choices'][0] ?? null;
        $content      = $choice['message']['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? '';

        if ($content === '' || $content === null) {
            if ($finishReason === 'content_filter') {
                return ['success' => false, 'error' => 'The answer was blocked by a safety filter. Try rephrasing your question.', 'kind' => 'content', 'retriable' => false];
            }
            if ($finishReason === 'length') {
                return ['success' => false, 'error' => 'That answer was too long to finish. Try asking something more specific.', 'kind' => 'content', 'retriable' => false];
            }
            error_log('[TourBuddy AI OpenAI] empty response (finish_reason=' . $finishReason . ')');
            return ['success' => false, 'error' => 'The AI assistant returned an empty answer. Please try again.', 'kind' => 'content', 'retriable' => false];
        }

        $usage = $data['usage'] ?? [];
        return [
            'success'          => true,
            'content'          => $content,
            'promptTokens'     => isset($usage['prompt_tokens'])     ? (int)$usage['prompt_tokens']     : null,
            'completionTokens' => isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null,
            'totalTokens'      => isset($usage['total_tokens'])      ? (int)$usage['total_tokens']      : null,
        ];
    }
}
