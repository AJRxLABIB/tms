<?php
/**
 * HybridSearch — fuses keyword (BM25) and semantic (dense) retrieval with Reciprocal Rank Fusion.
 *
 *  - BM25 side: KnowledgeIndex::search (top 20, after price/country filters).
 *  - Dense side: cosine between the query vector and EVERY filtered package's stored vector, so a package that shares no words
 *    with the query (e.g. a Bengali question against English package text, "romantic" vs "honeymoon") can still be found.
 *    A dense-only hit must reach $minSim, so unrelated packages are not returned just for being the "least bad" match.
 *  - Fusion: score = sum over lists of 1/(60 + rank). Order of ties is by package id, so results are deterministic.
 * Filters (price ceiling, country) apply to BOTH sides through KnowledgeIndex::passes(). The caller still applies the user's
 * avoid-list afterwards (ToolRouter::run), exactly as for BM25 results.
 * Whether this improves answers with a real embedding model is NOT measured here; the tests prove the mechanics only.
 */
require_once __DIR__ . '/KnowledgeIndex.php';
require_once __DIR__ . '/EmbeddingStore.php';

final class HybridSearch
{
    public const RRF_K = 60;

    /**
     * @param array<int,string> $packed id => packed normalised vector (EmbeddingStore::load)
     * @param float[] $queryVec already L2-normalised
     * @return array<int,array> hits in KnowledgeIndex shape, plus 'via' (both|bm25|dense), 'bm25_rank', 'dense_rank', 'semantic'
     */
    public static function search(KnowledgeIndex $ix, array $packed, array $queryVec, string $query, int $k, array $filters, float $minSim): array
    {
        $k = max(1, min(20, $k));
        $bm = $ix->search($query, 20, $filters); $bmRank = []; foreach ($bm as $i => $h) $bmRank[$h['id']] = $i + 1;
        $dense = [];
        foreach ($ix->all() as $id => $meta) {
            if (!isset($packed[$id]) || !KnowledgeIndex::passes($meta, $filters)) continue;
            $v = EmbeddingStore::unpack($packed[$id]);
            if (count($v) !== count($queryVec)) continue;           // different dimension: never compare
            $sim = EmbeddingStore::dot($queryVec, $v);
            if ($sim >= $minSim) $dense[$id] = $sim;
        }
        arsort($dense); $dense = array_slice($dense, 0, 20, true);
        $dRank = []; $r = 0; foreach ($dense as $id => $_) $dRank[$id] = ++$r;
        $scores = [];
        foreach ([$bmRank, $dRank] as $list) foreach ($list as $id => $rank) $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (self::RRF_K + $rank);
        uksort($scores, fn($a, $b) => $scores[$b] <=> $scores[$a] ?: $a <=> $b);
        $byId = []; foreach ($bm as $h) $byId[$h['id']] = $h;
        $out = [];
        foreach (array_slice(array_keys($scores), 0, $k) as $id) {
            $h = $byId[$id] ?? null;
            if (!$h) { $m = $ix->get($id); if (!$m) continue; $h = ['id' => $id, 'meta' => $m, 'citation' => $m['citation']]; unset($h['meta']['citation'], $h['meta']['id']); }
            $out[] = ['score' => round($scores[$id], 5), 'id' => $id, 'meta' => $h['meta'], 'citation' => $h['citation'],
                      'via' => isset($bmRank[$id], $dRank[$id]) ? 'both' : (isset($bmRank[$id]) ? 'bm25' : 'dense'),
                      'bm25_rank' => $bmRank[$id] ?? null, 'dense_rank' => $dRank[$id] ?? null, 'semantic' => isset($dense[$id]) ? round($dense[$id], 4) : null];
        }
        return $out;
    }
}
