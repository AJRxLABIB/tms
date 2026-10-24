<?php
/**
 * EmbeddingReranker — OPTIONAL dense re-ranking on top of KnowledgeIndex (BM25).
 *
 * Off by default. Enabled only when AI_EMBED_MODEL is set in .env (for example
 * `ollama pull nomic-embed-text` then AI_EMBED_MODEL=nomic-embed-text). It talks
 * to the same local Ollama server as the chat model; nothing leaves the machine.
 *
 * What it does: takes the top BM25 candidates, embeds the query and each
 * candidate (batched, vectors cached on disk), and blends the normalised BM25
 * score with cosine similarity. It can re-order candidates; it cannot add
 * packages that BM25 did not already retrieve.
 *
 * Failure policy: ANY problem (no model, timeout, bad response, wrong vector
 * size) returns the original BM25 order unchanged, with reranked=false. The
 * assistant must never get worse or slower-to-fail because of this layer.
 *
 * Honest status: the blending and fallback logic is unit-tested with a stub
 * embedder (tests/ai/rerank_test.php). Whether it improves answers with a real
 * embedding model has NOT been measured; that needs a real Ollama run.
 */
final class EmbeddingReranker
{
    /** @var callable(array<int,string>):?array<int,array<int,float>> */
    private $embed;
    private string $cacheFile;
    private string $cacheId;
    /** @var array<string,array<int,float>> */
    private array $cache = [];
    private bool $cacheDirty = false;
    private const MAX_CACHE = 3000;

    /** @param callable $embed takes list<string>, returns list<list<float>> in the same order, or null on failure */
    public function __construct(callable $embed, string $cacheId = 'default')
    {
        $this->embed = $embed;
        $this->cacheId = $cacheId;
        $this->cacheFile = sys_get_temp_dir() . '/tms_emb_' . sha1($cacheId) . '.json';
        $raw = @file_get_contents($this->cacheFile);
        $arr = $raw ? json_decode($raw, true) : null;
        if (is_array($arr)) $this->cache = $arr;
    }

    public function __destruct()
    {
        if (!$this->cacheDirty) return;
        if (count($this->cache) > self::MAX_CACHE) $this->cache = array_slice($this->cache, -self::MAX_CACHE, null, true);
        @file_put_contents($this->cacheFile, json_encode($this->cache), LOCK_EX);
    }

    /** Identifies provider+model; stored with persisted vectors so vectors from another model are never mixed in. */
    public function modelKey(): string { return mb_substr($this->cacheId, 0, 80); }

    /** @return ?array<int,array<int,float>> one vector per text (query embeddings use the local cache), or null on any failure */
    public function embed(array $texts): ?array
    {
        try { return $this->vectorsFor(array_values($texts)); } catch (Throwable $e) { return null; }
    }

    /** The raw embedder (for batch indexing; bypasses the small on-disk cache on purpose). @return callable */
    public function embedder(): callable { return $this->embed; }

    /** Returns a reranker when AI_EMBED_MODEL is configured, otherwise null. */
    public static function fromEnv(int $timeoutSec = 6): ?self
    {
        $model = defined('AI_EMBED_MODEL') ? trim((string)AI_EMBED_MODEL) : '';
        if ($model === '') return null;
        $base = defined('OLLAMA_BASE_URL') ? (string)OLLAMA_BASE_URL : 'http://127.0.0.1:11434';
        return new self(self::ollamaEmbedder($base, $model, $timeoutSec), 'ollama|' . $base . '|' . $model);
    }

    /** Embedder against Ollama's POST /api/embed (batched input). */
    public static function ollamaEmbedder(string $baseUrl, string $model, int $timeoutSec = 6): callable
    {
        return function (array $texts) use ($baseUrl, $model, $timeoutSec): ?array {
            if (!$texts || !function_exists('curl_init')) return null;
            $ch = curl_init(rtrim($baseUrl, '/') . '/api/embed');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'input' => array_values($texts)]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => $timeoutSec,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200) return null;
            $j = json_decode((string)$body, true);
            return (is_array($j) && isset($j['embeddings']) && is_array($j['embeddings'])) ? $j['embeddings'] : null;
        };
    }

    /** Text used to represent a KnowledgeIndex hit. */
    public static function hitText(array $hit): string
    {
        $m = $hit['meta'] ?? [];
        return trim(implode('. ', array_filter([
            $m['title'] ?? '', $m['type'] ?? '', $m['location'] ?? '', $m['country'] ?? '',
            $m['duration'] ?? '', mb_substr((string)($m['inclusions'] ?? ''), 0, 300),
        ])));
    }

    public static function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) return 0.0;
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) { $dot += $a[$i] * $b[$i]; $na += $a[$i] * $a[$i]; $nb += $b[$i] * $b[$i]; }
        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /**
     * @param array<int,array> $hits BM25 hits (each with 'score','id','meta'), best first
     * @param float $weight 0..1, share of the final score taken from semantic similarity
     * @return array{hits:array,reranked:bool,note:string}
     */
    public function rerank(string $query, array $hits, float $weight = 0.5): array
    {
        if (count($hits) < 2 || trim($query) === '') return ['hits' => $hits, 'reranked' => false, 'note' => 'nothing to rerank'];
        $weight = max(0.0, min(1.0, $weight));
        try {
            $texts = [$query];
            foreach ($hits as $h) $texts[] = self::hitText($h);
            $vecs = $this->vectorsFor($texts);
            if ($vecs === null) return ['hits' => $hits, 'reranked' => false, 'note' => 'embedding unavailable; kept BM25 order'];
            $q = $vecs[0];
            $maxBm = max(array_map(fn($h) => (float)$h['score'], $hits)) ?: 1.0;
            $out = [];
            foreach ($hits as $i => $h) {
                $sim = self::cosine($q, $vecs[$i + 1]);
                $sim01 = max(0.0, min(1.0, ($sim + 1) / 2)); // cosine -1..1 -> 0..1
                $h['bm25'] = $h['score'];
                $h['semantic'] = round($sim, 4);
                $h['score'] = round((1 - $weight) * ((float)$h['bm25'] / $maxBm) + $weight * $sim01, 4);
                $out[] = $h;
            }
            usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
            return ['hits' => $out, 'reranked' => true, 'note' => 'blended BM25 + embedding similarity'];
        } catch (Throwable $e) {
            return ['hits' => $hits, 'reranked' => false, 'note' => 'rerank error; kept BM25 order'];
        }
    }

    /** @return ?array<int,array<int,float>> vectors aligned with $texts, or null if any could not be obtained */
    private function vectorsFor(array $texts): ?array
    {
        $keys = array_map(fn($t) => sha1($t), $texts);
        $missing = [];
        foreach ($texts as $i => $t) if (!isset($this->cache[$keys[$i]])) $missing[$keys[$i]] = $t;
        if ($missing) {
            $got = ($this->embed)(array_values($missing));
            if (!is_array($got) || count($got) !== count($missing)) return null;
            $dim = null; $i = 0;
            foreach ($missing as $k => $_) {
                $v = $got[$i++] ?? null;
                if (!is_array($v) || !$v) return null;
                $v = array_map('floatval', $v);
                if ($dim === null) $dim = count($v);
                if (count($v) !== $dim) return null;
                $this->cache[$k] = $v;
            }
            $this->cacheDirty = true;
        }
        $res = [];
        $dim = null;
        foreach ($keys as $k) {
            $v = $this->cache[$k] ?? null;
            if (!$v) return null;
            if ($dim === null) $dim = count($v);
            if (count($v) !== $dim) return null; // mixed sizes (e.g. model changed): refuse rather than guess
            $res[] = $v;
        }
        return $res;
    }
}
