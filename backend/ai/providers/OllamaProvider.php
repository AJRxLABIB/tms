<?php
require_once __DIR__ . '/AIProviderInterface.php';

/**
 * OllamaProvider.php — self-hosted inference (owner-controlled infrastructure).
 *
 * Talks to a local Ollama runtime through its native, non-streaming chat
 * endpoint (`POST {base}/api/chat`, stream=false). No API key and no
 * commercial LLM API is involved. The model is whatever open-weight model the
 * operator has pulled (`ollama pull <model>`); it is configuration only
 * (OLLAMA_MODEL), so models can be swapped without touching business logic.
 *
 * Security notes:
 *  - The runtime must stay bound to localhost/private network. This class
 *    only ever sends to OLLAMA_BASE_URL; the browser never sees that URL.
 *  - Output is untrusted text. It is returned as a string to AIClient and
 *    callers must never execute it or interpolate it into SQL/shell/HTML.
 *  - Vision is not supported here (throws, which AIClient surfaces as a
 *    normal fallback message).
 *
 * STATUS: written against Ollama's documented /api/chat shape; NOT yet run
 * against a live Ollama instance in this environment. Verify with
 * tests/unit/OllamaProviderTest.php (parsing) and a real `ollama serve`.
 */
class OllamaProvider implements AIProviderInterface {

    private string $model;
    private string $baseUrl;
    private float $temperature;

    public function __construct(string $model, string $baseUrl = 'http://127.0.0.1:11434', float $temperature = 0.4) {
        $this->model       = $model;
        $this->baseUrl     = rtrim($baseUrl, '/');
        $this->temperature = $temperature;
    }

    public function name(): string { return 'ollama'; }
    public function model(): string { return $this->model; }
    public function isConfigured(): bool { return $this->model !== '' && $this->baseUrl !== ''; }

    public function buildTextRequest(string $system, array $messages, array $options): array {
        $chat = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $msg) {
            $role = (($msg['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $chat[] = ['role' => $role, 'content' => (string)($msg['content'] ?? '')];
        }

        $payload = [
            'model'    => $this->model,
            'messages' => $chat,
            'stream'   => false,
            'options'  => [
                'num_predict' => (int)($options['maxTokens'] ?? 1024),
                'temperature' => $this->temperature,
            ],
        ];

        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new RuntimeException('Could not encode the request (invalid characters in the message).');
        }
        return [
            'url'     => $this->baseUrl . '/api/chat',
            'headers' => ['Content-Type: application/json'],
            'body'    => $body,
        ];
    }

    public function buildImageRequest(string $system, string $userText, string $imageB64, string $mimeType, array $options): array {
        throw new RuntimeException('Image analysis is not supported by the self-hosted text model.');
    }

    public function parseResponse(int $httpCode, string $rawBody, ?string $curlError): array {
        if ($curlError || $httpCode === 0) {
            // Runtime not running / unreachable / timed out loading the model.
            return ['success' => false, 'error' => 'The AI assistant is offline right now. Please try again later.', 'kind' => 'unavailable', 'retriable' => false];
        }

        $data = json_decode($rawBody, true);

        if ($httpCode !== 200) {
            $detail = is_array($data) ? (string)($data['error'] ?? '') : '';
            error_log('[TMS AI Ollama] HTTP ' . $httpCode . ': ' . substr($detail !== '' ? $detail : $rawBody, 0, 200));
            if ($httpCode === 404) { // model not pulled
                return ['success' => false, 'error' => 'The AI assistant is not set up on this site yet.', 'kind' => 'config', 'retriable' => false];
            }
            return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.', 'kind' => in_array($httpCode, [500, 502, 503, 504], true) ? 'unavailable' : 'content', 'retriable' => in_array($httpCode, [500, 502, 503, 504], true)];
        }

        $content = is_array($data) ? (string)($data['message']['content'] ?? '') : '';
        if (trim($content) === '') {
            return ['success' => false, 'error' => 'The AI assistant returned an empty answer. Please try again.', 'kind' => 'content', 'retriable' => false];
        }

        $prompt = isset($data['prompt_eval_count']) ? (int)$data['prompt_eval_count'] : null;
        $comp   = isset($data['eval_count']) ? (int)$data['eval_count'] : null;
        return [
            'success'          => true,
            'content'          => $content,
            'promptTokens'     => $prompt,
            'completionTokens' => $comp,
            'totalTokens'      => ($prompt !== null && $comp !== null) ? $prompt + $comp : null,
        ];
    }
}
