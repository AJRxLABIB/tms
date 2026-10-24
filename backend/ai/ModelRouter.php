<?php
/**
 * ModelRouter — chooses which locally installed model serves which kind of request, and keeps prompts inside
 * the model's context window.
 *
 * Capabilities: intent_classify, search_interpret, chat, itinerary_plan, summarize.
 *
 * Configuration (all optional, comma-separated lists are tried in order; the default for everything is OLLAMA_MODEL):
 *   AI_MODEL_FAST   -> intent_classify, search_interpret
 *   AI_MODEL_CHAT   -> chat, summarize
 *   AI_MODEL_PLAN   -> itinerary_plan
 *   AI_CONTEXT_TOKENS  context window to assume (default 4096; Ollama's own default is small, so be conservative)
 *   AI_CONTEXT_<CAP>   per-capability override, e.g. AI_CONTEXT_ITINERARY_PLAN=8192
 * Nothing here assumes a bigger model is better: the operator names the model for each job, ideally from the
 * benchmark results in tests/ai/results/. The router only checks what is installed and falls down the chain.
 *
 * route() returns ['model' => null, 'reason' => 'no_model'|'runtime_unreachable'] when nothing usable exists, so a
 * caller can use its deterministic fallback instead of waiting for a call that cannot succeed.
 */
class ModelRouter
{
    public const CAPABILITIES = ['intent_classify', 'search_interpret', 'chat', 'itinerary_plan', 'summarize'];

    private const ENV_FOR = [
        'intent_classify'  => 'AI_MODEL_FAST',
        'search_interpret' => 'AI_MODEL_FAST',
        'chat'             => 'AI_MODEL_CHAT',
        'summarize'        => 'AI_MODEL_CHAT',
        'itinerary_plan'   => 'AI_MODEL_PLAN',
    ];

    /** Per-capability time budgets in seconds: [total, per attempt]. */
    public const BUDGETS = [
        'intent_classify'  => [8, 6],
        'search_interpret' => [10, 8],
        'chat'             => [22, 12],
        'summarize'        => [25, 15],
        'itinerary_plan'   => [60, 45],
    ];

    /** @var object registry with isAvailable(string):bool, reachable():bool, info(string):?array */
    private $registry;
    /** @var array<string,string> explicit config (name => value), mostly for tests; falls back to env */
    private array $config;

    public function __construct($registry, array $config = [])
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    private function cfg(string $name, string $default = ''): string
    {
        if (array_key_exists($name, $this->config)) return (string)$this->config[$name];
        if (function_exists('tms_env')) { $v = tms_env($name, null); if ($v !== null && $v !== false && $v !== true) return (string)$v; }
        $g = getenv($name);
        return ($g !== false && $g !== '') ? (string)$g : $default;
    }

    /** Ordered, de-duplicated model names configured for a capability (specific ones first, then the default). */
    public function chain(string $capability): array
    {
        $names = [];
        $env = self::ENV_FOR[$capability] ?? null;
        if ($env !== null) $names = array_merge($names, $this->split($this->cfg($env)));
        $names = array_merge($names, $this->split($this->cfg('OLLAMA_MODEL')));
        $seen = []; $out = [];
        foreach ($names as $n) { $k = strtolower($n); if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $n; } }
        return $out;
    }

    /**
     * @return array{model:?string,chain:string[],reason:string,capability:string,unavailable:string[]}
     *   reason: 'ok' | 'fallback_model' (first choice missing) | 'no_model' | 'runtime_unreachable' | 'unknown_capability'
     */
    public function route(string $capability): array
    {
        $base = ['model' => null, 'chain' => [], 'reason' => 'unknown_capability', 'capability' => $capability, 'unavailable' => []];
        if (!in_array($capability, self::CAPABILITIES, true)) return $base;

        $chain = $this->chain($capability);
        $base['chain'] = $chain; $base['reason'] = 'no_model';
        if (!$chain) return $base;
        if (!$this->registry->reachable()) { $base['reason'] = 'runtime_unreachable'; return $base; }

        foreach ($chain as $i => $m) {
            if ($this->registry->isAvailable($m)) {
                $base['model'] = $m;
                $base['reason'] = $i === 0 ? 'ok' : 'fallback_model';
                return $base;
            }
            $base['unavailable'][] = $m;
        }
        return $base;
    }

    /** Digest/size of an installed model if the registry already has it in memory (no network). */
    public function registryInfo(string $model): ?array
    {
        return method_exists($this->registry, 'infoIfLoaded') ? $this->registry->infoIfLoaded($model) : null;
    }

    // ── Context window ───────────────────────────────────────────────────────────

    public function contextLimit(string $capability): int
    {
        $v = (int)$this->cfg('AI_CONTEXT_' . strtoupper($capability), '');
        if ($v <= 0) $v = (int)$this->cfg('AI_CONTEXT_TOKENS', '4096');
        return max(1024, min(131072, $v));
    }

    /** Rough token estimate that errs high: Latin text ~1 token per 3.5 chars, every non-ASCII char (Bengali) ~1 token. */
    public static function estimateTokens(string $text): int
    {
        if ($text === '') return 0;
        $total = mb_strlen($text);
        $ascii = preg_match_all('/[\x00-\x7F]/', $text);
        $non = $total - (int)$ascii;
        return (int)ceil(((int)$ascii) / 3.5) + $non;
    }

    /**
     * Fits a conversation into the window without ever cutting the system prompt or tool facts.
     *   - the system prompt is never touched;
     *   - the last message and any message with 'protected' => true (tool facts) are never touched;
     *   - oldest unprotected turns are dropped whole first; if still too long, the oldest remaining unprotected
     *     turns are shortened (head and tail kept) before giving up.
     * If the protected parts alone do not fit, nothing is cut: ok=false, reason='context_too_large' (the caller should
     * use its deterministic fallback or ask a narrower question).
     *
     * @param array<int,array{role:string,content:string,protected?:bool}> $messages
     * @return array{ok:bool,messages:array,dropped:int,shortened:int,tokens:int,limit:int,reason:string}
     */
    public function fit(string $system, array $messages, int $limitTokens, int $reserveForAnswer = 512): array
    {
        $budget = max(256, $limitTokens - max(0, $reserveForAnswer));
        $sys = self::estimateTokens($system);
        $cost = fn(array $m) => self::estimateTokens((string)($m['content'] ?? '')) + 4;
        $n = count($messages);
        $keep = array_fill(0, $n, true);
        $isFixed = fn(int $i) => $i === $n - 1 || !empty($messages[$i]['protected']);

        $total = $sys; foreach ($messages as $m) $total += $cost($m);
        $dropped = 0; $shortened = 0;

        // 1) drop oldest unprotected whole turns
        for ($i = 0; $i < $n && $total > $budget; $i++) {
            if ($isFixed($i) || !$keep[$i]) continue;
            $keep[$i] = false; $total -= $cost($messages[$i]); $dropped++;
        }
        $out = [];
        foreach ($messages as $i => $m) if ($keep[$i]) $out[$i] = $m;

        // 2) shorten oldest remaining unprotected turns (keep head and tail)
        if ($total > $budget) {
            foreach (array_keys($out) as $i) {
                if ($total <= $budget) break;
                if ($isFixed($i)) continue;
                $text = (string)$out[$i]['content'];
                $len = mb_strlen($text);
                if ($len <= 240) continue;
                $before = $cost($out[$i]);
                $out[$i]['content'] = mb_substr($text, 0, 100) . ' […] ' . mb_substr($text, -100);
                $total -= $before - $cost($out[$i]); $shortened++;
            }
        }

        $ok = $total <= $budget;
        return [
            'ok' => $ok, 'messages' => array_values($ok ? $out : $messages), 'dropped' => $ok ? $dropped : 0,
            'shortened' => $ok ? $shortened : 0, 'tokens' => $ok ? $total : self::sumTokens($system, $messages),
            'limit' => $limitTokens, 'reason' => $ok ? 'ok' : 'context_too_large',
        ];
    }

    private static function sumTokens(string $system, array $messages): int
    {
        $t = self::estimateTokens($system);
        foreach ($messages as $m) $t += self::estimateTokens((string)($m['content'] ?? '')) + 4;
        return $t;
    }

    /** @return string[] */
    private function split(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }
}
