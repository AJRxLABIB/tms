<?php
require_once __DIR__ . '/AIProviderInterface.php';

/**
 * NoneProvider — "no AI provider is available". Always reports isConfigured() = false, so AIClient returns its normal
 * "AI is not switched on" result without any network call, and every feature takes its deterministic fallback path.
 * `reason()` carries why (see AIProviderResolver), for admin diagnostics only.
 */
class NoneProvider implements AIProviderInterface
{
    private string $reason;
    private string $detail;

    public function __construct(string $reason = 'not_configured', string $detail = '')
    {
        $this->reason = $reason;
        $this->detail = $detail;
    }

    public function reason(): string { return $this->reason; }
    public function detail(): string { return $this->detail; }
    public function name(): string { return 'none'; }
    public function model(): string { return ''; }
    public function isConfigured(): bool { return false; }

    public function buildTextRequest(string $system, array $messages, array $options): array
    {
        throw new RuntimeException('No AI provider is available.');
    }

    public function buildImageRequest(string $system, string $userText, string $imageB64, string $mimeType, array $options): array
    {
        throw new RuntimeException('No AI provider is available.');
    }

    public function parseResponse(int $httpCode, string $rawBody, ?string $curlError): array
    {
        return ['success' => false, 'error' => 'No AI provider is available.', 'kind' => 'config', 'retriable' => false];
    }
}
