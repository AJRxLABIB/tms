<?php
/**
 * AIProviderResolver — decides which AI provider a site actually uses when AI_PROVIDER is "auto" (the default when the
 * variable is not set). Pure logic: the caller supplies the configuration and a callable that lists locally installed
 * Ollama models, so it is fully testable without a network.
 *
 * Rules (local first, external only when the operator really configured it):
 *   1. AI_PROVIDER explicitly set to ollama / gemini / openai  -> that provider, no questions asked (reason "explicit").
 *   2. auto: a reachable local Ollama with at least one installed CHAT model (embedding-only models do not count)
 *            -> ollama, using OLLAMA_MODEL if it is installed, else the first installed chat model.
 *   3. auto: no usable Ollama, and GEMINI_API_KEY has the shape of a real Google AI Studio key (AIza + 35 chars)
 *            -> gemini. A key that is present but malformed is NOT used (it can only fail), and the reason says so.
 *   4. otherwise -> "none": every AI feature takes its deterministic, catalogue-based path. Nothing is sent anywhere.
 *
 * OpenAI is never auto-selected: it needs an explicit AI_PROVIDER=openai, because that sends customer text to a
 * third party the operator must have chosen on purpose.
 */
final class AIProviderResolver
{
    /** Embedding-only model names: installed, but they cannot chat. */
    private const EMBED_HINTS = ['embed', 'bge-', 'bge:', 'minilm', 'e5-', 'gte-', 'snowflake-arctic-embed', 'mxbai', 'rerank'];

    /** A real Google AI Studio key: "AIza" followed by 35 URL-safe characters. */
    public static function geminiKeyLooksValid(string $key): bool
    {
        return (bool)preg_match('/^AIza[0-9A-Za-z_\-]{35}$/', trim($key));
    }

    public static function isEmbeddingModel(string $name): bool
    {
        $n = strtolower($name);
        foreach (self::EMBED_HINTS as $h) if (strpos($n, $h) !== false) return true;
        return false;
    }

    /**
     * @param array{provider?:string,gemini_key?:string,ollama_model?:string,ollama_model_explicit?:bool} $cfg
     * @param callable():(?array) $installedModels  names of installed Ollama models, or null when the runtime is unreachable
     * @return array{provider:string,model:string,reason:string,detail:string}
     */
    public static function resolve(array $cfg, callable $installedModels): array
    {
        $wanted = strtolower(trim((string)($cfg['provider'] ?? '')));
        $geminiKey = trim((string)($cfg['gemini_key'] ?? ''));
        $preferred = trim((string)($cfg['ollama_model'] ?? ''));

        if (in_array($wanted, ['ollama', 'gemini', 'openai'], true)) {
            return ['provider' => $wanted, 'model' => $wanted === 'ollama' ? $preferred : '', 'reason' => 'explicit', 'detail' => "AI_PROVIDER={$wanted} is set in .env."];
        }
        if ($wanted === 'none' || $wanted === 'off' || $wanted === 'disabled') {
            return ['provider' => 'none', 'model' => '', 'reason' => 'disabled', 'detail' => 'AI_PROVIDER is set to turn AI off.'];
        }
        // '' and 'auto' fall through; anything unrecognised is treated as auto (local first) rather than guessing a cloud vendor.

        $models = null;
        try { $models = $installedModels(); } catch (\Throwable $e) { $models = null; }

        if (is_array($models)) {
            $chat = array_values(array_filter(array_map('strval', $models), fn($m) => $m !== '' && !self::isEmbeddingModel($m)));
            if ($chat) {
                $pick = $chat[0];
                if ($preferred !== '') {
                    foreach ($chat as $m) {
                        if (strcasecmp($m, $preferred) === 0 || strcasecmp($m, $preferred . ':latest') === 0 || strcasecmp(preg_replace('/:latest$/i', '', $m), $preferred) === 0) { $pick = $m; break; }
                    }
                }
                return ['provider' => 'ollama', 'model' => $pick, 'reason' => 'auto_ollama', 'detail' => "Local Ollama is running; using installed model {$pick}."];
            }
            $ollamaNote = 'Ollama is running but has no chat model installed (run: ollama pull qwen2.5:3b-instruct).';
            $ollamaReason = 'ollama_no_model';
        } else {
            $ollamaNote = 'Ollama is not reachable on this machine (start it, or install it from https://ollama.com).';
            $ollamaReason = 'ollama_down';
        }

        if ($geminiKey !== '') {
            if (self::geminiKeyLooksValid($geminiKey)) {
                return ['provider' => 'gemini', 'model' => '', 'reason' => 'auto_gemini', 'detail' => $ollamaNote . ' Using the configured Gemini key (data is sent to Google).'];
            }
            return ['provider' => 'none', 'model' => '', 'reason' => 'invalid_key', 'detail' => $ollamaNote . ' GEMINI_API_KEY is set but is not a valid Google AI Studio key (those start with "AIza" and are 39 characters), so it was not used.'];
        }
        return ['provider' => 'none', 'model' => '', 'reason' => $ollamaReason === 'ollama_no_model' ? 'ollama_no_model' : 'not_configured', 'detail' => $ollamaNote . ' No Gemini key is configured.'];
    }

    /** Short, customer-safe or admin-facing wording for a resolver reason. */
    public static function explain(string $reason, bool $admin): string
    {
        $admin_msgs = [
            'explicit'        => 'The provider named in AI_PROVIDER is used.',
            'auto_ollama'     => 'Using the local Ollama runtime.',
            'auto_gemini'     => 'Ollama is not available; using the configured Gemini key.',
            'disabled'        => 'AI is turned off by AI_PROVIDER.',
            'invalid_key'     => 'AI is off: GEMINI_API_KEY is not a valid Google AI Studio key and Ollama is not running. Start Ollama or paste a real key.',
            'ollama_down'     => 'AI is off: Ollama is not running and no Gemini key is configured.',
            'ollama_no_model' => 'AI is off: Ollama is running but no chat model is installed (ollama pull qwen2.5:3b-instruct).',
            'not_configured'  => 'AI is off: no local model runtime and no API key are configured.',
            'quota'           => 'The provider reported its quota is used up.',
            'config'          => 'The provider rejected the API key or model name.',
            'unavailable'     => 'The provider could not be reached (network, timeout or server error).',
        ];
        if ($admin) return $admin_msgs[$reason] ?? 'The AI provider is not available.';
        return 'Conversational AI is offline right now, so you are seeing verified catalogue information instead.';
    }
}
