<?php
/**
 * Unit tests for EmbeddingReranker using a STUB embedder (no Ollama, no network).
 *   php tests/ai/rerank_test.php
 * Proves the blending and the fail-safe fallbacks. It does NOT prove that a real
 * embedding model improves answers; that needs a live Ollama run.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/ai/rag/KnowledgeIndex.php';
require_once __DIR__ . '/../../backend/ai/rag/EmbeddingReranker.php';
require_once __DIR__ . '/../../backend/ai/ToolRegistry.php';

$pass = 0; $fail = 0;
$t = function (string $name, bool $ok, string $d = '') use (&$pass, &$fail) { $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($name, 34) . $d . "\n"; };

// Tiny deterministic "semantic" space: texts mentioning related words share a dimension.
$space = [['sea','beach','ocean','coast'], ['hill','mountain','trek','cloud'], ['forest','mangrove','tiger','wildlife']];
$stub = function (array $texts) use ($space): array {
    return array_map(function ($tx) use ($space) {
        $tx = strtolower($tx);
        return array_map(function ($words) use ($tx) { $n = 0; foreach ($words as $w) $n += substr_count($tx, $w); return (float)$n; }, $space);
    }, $texts);
};
$hit = fn(int $id, string $title, float $score, string $type = '') => ['id' => $id, 'score' => $score, 'meta' => ['title' => $title, 'type' => $type, 'location' => '', 'country' => '', 'duration' => '', 'inclusions' => ''], 'citation' => ['id' => "pkg:$id"]];
$mk = fn(callable $e) => new EmbeddingReranker($e, 'test-' . bin2hex(random_bytes(4)));

$hits = [$hit(1, 'City Museum Tour', 3.0), $hit(2, 'Ocean Coast Beach Retreat', 2.5), $hit(3, 'Hill Trek', 1.0)];
$r = $mk($stub)->rerank('sea beach holiday', $hits, 0.7);
$t('semantic_promotes_related', $r['reranked'] && $r['hits'][0]['id'] === 2, 'order=' . implode(',', array_column($r['hits'], 'id')));

$r = $mk($stub)->rerank('sea beach holiday', $hits, 0.0);
$t('weight_zero_keeps_bm25', $r['hits'][0]['id'] === 1, 'order=' . implode(',', array_column($r['hits'], 'id')));

$r = $mk(fn($x) => null)->rerank('sea', $hits);
$t('embedder_failure_falls_back', !$r['reranked'] && array_column($r['hits'], 'id') === [1, 2, 3], $r['note']);

$r = $mk(function ($x) { throw new RuntimeException('boom'); })->rerank('sea', $hits);
$t('embedder_exception_falls_back', !$r['reranked'] && array_column($r['hits'], 'id') === [1, 2, 3], $r['note']);

$r = $mk(fn($x) => [[1.0, 2.0]])->rerank('sea', $hits);
$t('wrong_count_falls_back', !$r['reranked'], $r['note']);

$n = 0; $mixed = function ($x) use (&$n) { return array_map(fn($i) => $i === 0 ? [1.0, 0.0] : [1.0, 0.0, 0.0], array_keys($x)); };
$r = $mk($mixed)->rerank('sea', $hits);
$t('mixed_dimensions_fall_back', !$r['reranked'], $r['note']);

$r = $mk($stub)->rerank('sea', [$hit(1, 'Only', 1.0)]);
$t('single_hit_untouched', !$r['reranked'] && count($r['hits']) === 1);

$t('cosine_identity', abs(EmbeddingReranker::cosine([1.0, 2.0], [1.0, 2.0]) - 1.0) < 1e-9);
$t('cosine_zero_vector_safe', EmbeddingReranker::cosine([0.0, 0.0], [1.0, 2.0]) === 0.0);
$t('fromEnv_off_by_default', EmbeddingReranker::fromEnv() === null);

// ToolRegistry integration: a failing reranker must not change results vs. no reranker.
$rows = [
 ['PackageId' => 1, 'PackageName' => 'Beach Retreat', 'PackageType' => 'Beach', 'PackageLocation' => 'Coast', 'Country' => 'Bangladesh', 'PackagePrice' => 10000, 'PackageDuration' => '3 Days', 'PackageFetures' => 'sea beach'],
 ['PackageId' => 2, 'PackageName' => 'Sea Beach Hotel Stay', 'PackageType' => 'Beach', 'PackageLocation' => 'Coast', 'Country' => 'Bangladesh', 'PackagePrice' => 12000, 'PackageDuration' => '2 Days', 'PackageFetures' => 'sea beach hotel'],
];
$ix = KnowledgeIndex::fromRows($rows);
$plain = (new ToolRegistry($ix, null))->execute('search_packages', ['query' => 'sea beach'], []);
$failing = (new ToolRegistry($ix, null, $mk(fn($x) => null)))->execute('search_packages', ['query' => 'sea beach'], []);
$t('registry_same_when_reranker_fails', json_encode($plain['data'] ?? null) === json_encode($failing['data'] ?? null));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
