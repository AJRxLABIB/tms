<?php
require_once __DIR__ . '/AIProviderInterface.php';

/**
 * GeminiProvider.php — Round 34 (item 21). Google Gemini implementation of
 * AIProviderInterface.
 *
 * This is a straight extraction of the request-building and response-parsing
 * logic that used to live directly inside AIClient.php's call()/askWithImage()/
 * httpPost() -- same payload shapes, same safety-setting thresholds, same
 * error-message wording for each case, so existing behavior for every
 * current deployment (which all run Gemini, the only provider before this
 * round) is unchanged byte-for-byte. Only the retry/backoff loop itself
 * moved out to AIClient, generalized to work with any provider.
 */
class GeminiProvider implements AIProviderInterface {

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model) {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function name(): string { return 'gemini'; }
    public function model(): string { return $this->model; }
    public function isConfigured(): bool {
        return $this->apiKey !== '' && $this->apiKey !== 'YOUR_GEMINI_API_KEY_HERE';
    }

    public function buildTextRequest(string $system, array $messages, array $options): array {
        $geminiContents = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
            $geminiContents[] = [
                'role'  => $role,
                'parts' => [['text' => (string)($msg['content'] ?? '')]],
            ];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $geminiContents,
            'generationConfig'   => [
                'maxOutputTokens' => $options['maxTokens'] ?? 2000,
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

        return $this->buildRequest($payload);
    }

    public function buildImageRequest(string $system, string $userText, string $imageB64, string $mimeType, array $options): array {
        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['text' => $userText],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageB64]],
                ],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => $options['maxTokens'] ?? 2000,
                'temperature'     => 0.4, // lower than chat -- this is classification, not creative writing
                'topP'            => 0.9,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ],
        ];

        return $this->buildRequest($payload);
    }

    private function buildRequest(array $payload): array {
        // Encoding can fail on invalid UTF-8 (easy to send in a raw POST) --
        // surfaced as a RuntimeException so AIClient can turn it into the
        // same "could not encode" fallback it always has, rather than
        // silently posting an empty body.
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            throw new RuntimeException('Could not encode the request (invalid characters in the message).');
        }
        return [
            'url' => self::BASE_URL . $this->model . ':generateContent',
            // Round 14: the key used to travel in the query string, which
            // lands in every proxy access log. Gemini accepts it as a header.
            'headers' => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
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
            $detail = $data['error']['message'] ?? ('HTTP ' . $httpCode . ' — ' . substr($rawBody, 0, 200));
            error_log('[TourBuddy AI Gemini] API error: HTTP ' . $httpCode . ': ' . $detail);

            if ($httpCode === 401 || $httpCode === 403) {
                return ['success' => false, 'error' => 'The AI assistant is not configured correctly on this site.', 'kind' => 'config', 'retriable' => false];
            }
            if ($httpCode === 429) {
                // A 429 is either a per-minute burst (worth one short retry) or
                // the daily free-tier quota being spent (retrying is pure
                // waste and just hammers the API). Tell them apart from the
                // response body -- this is Gemini-specific wording, which is
                // exactly why this classification lives in the provider and
                // not in AIClient's generic retry loop.
                //
                // FIX (Round 35 -- found by actually running the new AI Eval
                // Suite against a real, now-exhausted free-tier key): this
                // used to check the raw body for the literal substring
                // "per day". A real exhausted-quota response's quotaId is
                // "GenerateRequestsPerDayPerProjectPerModel-FreeTier" --
                // "PerDay" with no space -- so that check silently never
                // matched a real daily quota error and every one came back
                // as the wrong, retriable "busy right now" message instead
                // of the correct, non-retriable daily-limit one. Parsing the
                // actual quotaId out of error.details[].violations[] is both
                // more correct and more robust than sniffing wording that
                // could change.
                $isDailyQuota = false;
                foreach (($data['error']['details'] ?? []) as $detail) {
                    foreach (($detail['violations'] ?? []) as $violation) {
                        if (stripos($violation['quotaId'] ?? '', 'PerDay') !== false) {
                            $isDailyQuota = true;
                            break 2;
                        }
                    }
                }
                if ($isDailyQuota) {
                    return ['success' => false, 'error' => 'The AI assistant has reached today\'s usage limit. Please try again tomorrow.', 'kind' => 'quota', 'retriable' => false];
                }
                return ['success' => false, 'error' => 'The AI assistant is busy right now. Please try again in a minute.', 'kind' => 'unavailable', 'retriable' => true];
            }
            if (in_array($httpCode, [500, 502, 503, 504], true)) {
                return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.', 'kind' => 'unavailable', 'retriable' => true];
            }
            return ['success' => false, 'error' => 'The AI assistant had a problem answering that. Please try again.', 'kind' => 'content', 'retriable' => false];
        }

        // A prompt blocked BEFORE generation is reported at the top level,
        // not on the candidate -- used to fall through to the generic
        // "Empty response from Gemini API."
        $blockReason = $data['promptFeedback']['blockReason'] ?? '';
        if ($blockReason !== '') {
            return ['success' => false, 'error' => 'That message could not be processed. Please rephrase it and try again.', 'kind' => 'content', 'retriable' => false];
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ($content === '') {
            $finishReason = $data['candidates'][0]['finishReason'] ?? '';
            if ($finishReason === 'SAFETY') {
                return ['success' => false, 'error' => 'The answer was blocked by a safety filter. Try rephrasing your question.', 'kind' => 'content', 'retriable' => false];
            }
            if ($finishReason === 'MAX_TOKENS') {
                return ['success' => false, 'error' => 'That answer was too long to finish. Try asking something more specific.', 'kind' => 'content', 'retriable' => false];
            }
            error_log('[TourBuddy AI Gemini] empty response (finishReason=' . $finishReason . ')');
            return ['success' => false, 'error' => 'The AI assistant returned an empty answer. Please try again.', 'kind' => 'content', 'retriable' => false];
        }

        $usage = $data['usageMetadata'] ?? [];
        return [
            'success'           => true,
            'content'           => $content,
            'promptTokens'      => isset($usage['promptTokenCount'])     ? (int)$usage['promptTokenCount']     : null,
            'completionTokens'  => isset($usage['candidatesTokenCount']) ? (int)$usage['candidatesTokenCount'] : null,
            'totalTokens'       => isset($usage['totalTokenCount'])      ? (int)$usage['totalTokenCount']      : null,
        ];
    }
}
