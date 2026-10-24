<?php
/**
 * AIDiagnostics — one place that answers "why is the AI not working?" for both the CLI (scripts/ai_setup_check.php) and the
 * admin page (admin/ai-setup.php). It never prints a secret: API keys are reported only as "set / valid format", and any
 * provider error text is passed through redact() first.
 *
 * state() inspects configuration and the local Ollama runtime. probe() sends one tiny real request to the selected provider
 * (the only part that costs anything or leaves the machine, so callers run it only on request). nextSteps() turns the state
 * into exact, ordered instructions and is pure, so it is unit-tested.
 */
require_once __DIR__ . '/AIProviderResolver.php';

final class AIDiagnostics
{
    /** Remove anything that looks like a credential, strip markup, collapse whitespace, bound the length. */
    public static function redact(string $s, int $max = 300): string
    {
        $s = strip_tags($s);
        $s = preg_replace('/(?:AIza|AQ\.)[0-9A-Za-z_\-]{20,}/', '[redacted-key]', $s);
        $s = preg_replace('/\bsk-[A-Za-z0-9_\-]{10,}/', '[redacted-key]', $s);
        $s = preg_replace('/(Bearer\s+|key=|api[_-]?key["\']?\s*[:=]\s*["\']?)[A-Za-z0-9_\-\.]{12,}/i', '$1[redacted]', $s);
        $s = preg_replace('/\s+/', ' ', (string)$s);
        $s = trim((string)$s);
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 1, 'UTF-8') . '…' : $s;
    }

    /**
     * @param array<string,mixed> $cfg provider, gemini_key, ollama_base, ollama_model, embed_model (defaults read from constants)
     * @param ?callable $installed  fn(): ?array of ['name'=>..,'size'=>..] rows (null = unreachable); default queries Ollama
     * @return array<string,mixed>
     */
    public static function state(array $cfg = [], ?callable $installed = null): array
    {
        $provider = strtolower(trim((string)($cfg['provider'] ?? (defined('AI_PROVIDER') ? AI_PROVIDER : 'auto'))));
        $key      = (string)($cfg['gemini_key'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ''));
        $base     = (string)($cfg['ollama_base'] ?? (defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'));
        $model    = (string)($cfg['ollama_model'] ?? (defined('OLLAMA_MODEL') ? OLLAMA_MODEL : ''));
        $embed    = (string)($cfg['embed_model'] ?? (defined('AI_EMBED_MODEL') ? AI_EMBED_MODEL : ''));

        $rows = null;
        try { $rows = $installed ? $installed() : self::fetchOllama($base); } catch (\Throwable $e) { $rows = null; }
        $names = is_array($rows) ? array_values(array_map(fn($r) => (string)($r['name'] ?? ''), $rows)) : null;

        $res = AIProviderResolver::resolve(['provider' => $provider, 'gemini_key' => $key, 'ollama_model' => $model], fn() => $names);
        $chat = $names === null ? [] : array_values(array_filter($names, fn($n) => $n !== '' && !AIProviderResolver::isEmbeddingModel($n)));
        $emb  = $names === null ? [] : array_values(array_filter($names, fn($n) => $n !== '' && AIProviderResolver::isEmbeddingModel($n)));
        $has = function (array $list, string $m): bool {
            foreach ($list as $n) if ($m !== '' && (strcasecmp($n, $m) === 0 || strcasecmp($n, $m . ':latest') === 0 || strcasecmp(preg_replace('/:latest$/i', '', $n), $m) === 0)) return true;
            return false;
        };
        return [
            'provider_setting'  => $provider === '' ? 'auto' : $provider,
            'selected'          => $res,
            'gemini_key_set'    => trim($key) !== '',
            'gemini_key_valid'  => AIProviderResolver::geminiKeyLooksValid($key),
            'ollama_base'       => $base,
            'ollama_reachable'  => $names !== null,
            'ollama_models'     => $rows ?? [],
            'ollama_chat'       => $chat,
            'ollama_embed'      => $emb,
            'wanted_model'      => $model,
            'wanted_installed'  => $has($chat, $model),
            'embed_model'       => $embed,
            'embed_installed'   => $embed === '' ? null : $has($emb, $embed) || $has($names ?? [], $embed),
        ];
    }

    /** Ordered, concrete instructions for the operator. Pure. @return string[] */
    public static function nextSteps(array $s): array
    {
        $steps = [];
        $sel = $s['selected'] ?? [];
        $provider = (string)($sel['provider'] ?? 'none');
        $reason = (string)($sel['reason'] ?? '');
        $explicit = ($s['provider_setting'] ?? 'auto');

        if ($provider === 'none') {
            if (empty($s['ollama_reachable'])) {
                $steps[] = 'Install Ollama (https://ollama.com/download/windows), start it (it runs in the system tray), then reload this page. Check http://127.0.0.1:11434 shows "Ollama is running".';
            }
            if (!empty($s['ollama_reachable']) && empty($s['ollama_chat'])) {
                $steps[] = 'Ollama is running but has no chat model. In a terminal run: ollama pull qwen2.5:3b-instruct';
            }
            if (!empty($s['gemini_key_set']) && empty($s['gemini_key_valid'])) {
                $steps[] = 'GEMINI_API_KEY in .env is not a valid Google AI Studio key (they start with "AIza" or "AQ."). Fix it, or delete the line to use only local AI. Never paste a key anywhere except .env.';
            }
            if ($explicit === 'auto' && empty($s['gemini_key_set'])) {
                $steps[] = 'Optional cloud fallback: put a free key from https://aistudio.google.com in .env as GEMINI_API_KEY=... (customer text would then be sent to Google when Ollama is down).';
            }
            $steps[] = 'Until then the site keeps working: chat and Explore answer from the catalogue only.';
        } elseif ($provider === 'ollama') {
            if (empty($s['wanted_installed']) && ($s['wanted_model'] ?? '') !== '' && $explicit !== 'auto') {
                $steps[] = 'OLLAMA_MODEL=' . $s['wanted_model'] . ' is not installed. Run: ollama pull ' . $s['wanted_model'];
            }
            if (($s['wanted_model'] ?? '') !== '' && empty($s['wanted_installed']) && $explicit === 'auto') {
                $steps[] = 'The default model ' . $s['wanted_model'] . ' is not installed, so the first installed chat model (' . ($sel['model'] ?? '?') . ') is used. For best results run: ollama pull ' . $s['wanted_model'];
            }
            if (($s['embed_model'] ?? '') === '') {
                $steps[] = 'Optional (better search, Bengali too): ollama pull bge-m3, then set AI_EMBED_MODEL=bge-m3 in .env and run: php scripts/sync_embeddings.php';
            } elseif (empty($s['embed_installed'])) {
                $steps[] = 'AI_EMBED_MODEL=' . $s['embed_model'] . ' is not installed. Run: ollama pull ' . $s['embed_model'];
            }
            $steps[] = 'First answers after a restart are slow while the model loads into memory (keep_alive keeps it loaded afterwards). A 3B model on CPU takes several seconds per answer; a smaller model (qwen2.5:1.5b-instruct) is faster.';
        } elseif ($provider === 'gemini') {
            $steps[] = 'Gemini is in use, so customer chat text is sent to Google. For a self-hosted setup install Ollama and set AI_PROVIDER=ollama.';
            if (empty($s['gemini_key_valid'])) $steps[] = 'The configured key does not have the shape of a Google AI Studio key; expect "invalid key" errors.';
        }
        if ($reason === 'explicit' && $provider === 'ollama' && empty($s['ollama_reachable'])) {
            $steps[] = 'AI_PROVIDER=ollama is set but Ollama is not reachable at ' . ($s['ollama_base'] ?? '') . '. Start Ollama, or set AI_PROVIDER=auto.';
        }
        return $steps;
    }

    /** One real, minimal request to the selected provider. Never returns secrets. @return array{ok:bool,provider:string,model:string,http:int,latency_ms:int,error:string,reply:string} */
    public static function probe(?PDO $db = null, int $timeoutSec = 25): array
    {
        require_once __DIR__ . '/AIClient.php';
        AIClient::resetResolution();
        $r = AIClient::resolution($db);
        $out = ['ok' => false, 'provider' => $r['provider'], 'model' => $r['model'], 'http' => 0, 'latency_ms' => 0, 'error' => '', 'reply' => ''];
        if ($r['provider'] === 'none') { $out['error'] = self::redact($r['detail']); return $out; }

        $p = match ($r['provider']) {
            'ollama' => new OllamaProvider($r['model'] !== '' ? $r['model'] : (defined('OLLAMA_MODEL') ? (string)OLLAMA_MODEL : ''), defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'),
            'gemini' => new GeminiProvider(defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '', defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-2.5-flash'),
            'openai' => new OpenAIProvider(defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '', defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini', defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1'),
            default  => null,
        };
        if ($p === null) { $out['error'] = 'Unknown provider.'; return $out; }
        $out['model'] = $p->model();
        if (!$p->isConfigured()) { $out['error'] = 'The provider is selected but not configured (missing key or model name).'; return $out; }
        if (!function_exists('curl_init')) { $out['error'] = 'The PHP curl extension is not enabled (enable extension=curl in php.ini).'; return $out; }

        try { $req = $p->buildTextRequest('You are a connectivity test. Reply with exactly: OK', [['role' => 'user', 'content' => 'ping']], ['maxTokens' => 16]); }
        catch (\Throwable $e) { $out['error'] = self::redact($e->getMessage()); return $out; }

        $t0 = microtime(true);
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $req['body'], CURLOPT_HTTPHEADER => $req['headers'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => $timeoutSec]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out['latency_ms'] = (int)round((microtime(true) - $t0) * 1000);
        $out['http'] = $code;

        if ($body === false || $code === 0) {
            $out['error'] = 'Could not connect: ' . self::redact($err !== '' ? $err : 'no response') . ($r['provider'] === 'ollama' ? ' (is Ollama running? is the model still loading? first call can exceed ' . $timeoutSec . 's)' : '');
            return $out;
        }
        $parsed = $p->parseResponse($code, (string)$body, null);
        if (!empty($parsed['success'])) { $out['ok'] = true; $out['reply'] = self::redact((string)($parsed['content'] ?? ''), 80); return $out; }
        // Real upstream detail: the vendor's own error message when it sent JSON, else the first bytes of the body.
        $j = json_decode((string)$body, true);
        $detail = is_array($j) ? (string)($j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '')) : '';
        $out['error'] = 'HTTP ' . $code . ': ' . self::redact($detail !== '' ? $detail : (string)$body)
            . ' [' . ($parsed['kind'] ?? ProviderHealth::classify($parsed)) . ']';
        return $out;
    }

    /** @return ?array<int,array{name:string,size:int}> null when Ollama cannot be reached */
    public static function fetchOllama(string $base): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init(rtrim($base, '/') . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 3]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) return null;
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['models']) || !is_array($j['models'])) return null;
        $rows = [];
        foreach ($j['models'] as $m) if (is_array($m) && isset($m['name'])) $rows[] = ['name' => (string)$m['name'], 'size' => (int)($m['size'] ?? 0)];
        return $rows;
    }
}
