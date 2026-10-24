<?php
/**
 * Persistent embeddings + hybrid retrieval: mechanics only. The embedder is a tiny deterministic STUB (words -> dimensions), so this proves
 * storage, incremental sync, stale handling, fusion, filters, fallbacks and the avoid-list. It does NOT show that a real embedding model
 * improves answers - that needs a real model and tests/ai/live_quality.php.   php tests/ai/hybrid_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/ToolRegistry.php'; require_once 'backend/ai/ToolRouter.php';
EmbeddingStore::migrate($dbh);
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 82) . $d . "\n"; }
$clean = fn() => $dbh->exec('DELETE FROM tbl_ai_embeddings WHERE PackageId BETWEEN 9001 AND 9010');
$clean();

// stub semantic space: romantic | hills | beach (incl. Bengali words) | museum | constant base
$space = [['honeymoon', 'romantic', 'couples', 'anniversary'], ['hill', 'trek', 'mountain'], ['beach', 'sea', 'সমুদ্র', 'সৈকত'], ['museum', 'gallery', 'history']];
$calls = 0;
$stub = function (array $texts) use ($space, &$calls): array {
    $calls++;
    return array_map(function ($tx) use ($space) {
        $tx = mb_strtolower($tx, 'UTF-8');
        $v = array_map(fn($w) => (float)array_sum(array_map(fn($x) => substr_count($tx, $x), $w)), $space);
        $v[] = 0.1; return $v;
    }, $texts);
};
$row = fn(int $id, string $name, int $price, string $type = '', string $feat = '') => ['PackageId' => $id, 'PackageName' => $name, 'PackageType' => $type, 'PackageLocation' => 'Somewhere', 'Country' => 'Bangladesh', 'PackagePrice' => $price, 'PackageDuration' => '3 Days', 'PackageFetures' => $feat];
$rows = [$row(9001, 'Honeymoon Retreat Cottage', 20000), $row(9002, 'Hill Trek Adventure', 8000), $row(9003, 'Cox Beach Resort', 15000), $row(9004, 'City Museum Tour', 3000)];
$ix = KnowledgeIndex::fromRows($rows);
$docs = []; foreach ($ix->all() as $id => $m) $docs[$id] = EmbeddingStore::textFor($m);
$KEY = 'hyb-test-' . bin2hex(random_bytes(3));

// ── vector helpers ──
$nv = EmbeddingStore::normalize([3.0, 4.0]);
t('normalize: unit length; zero/garbage vector rejected', abs(EmbeddingStore::dot($nv, $nv) - 1.0) < 1e-9 && EmbeddingStore::normalize([0, 0]) === null && EmbeddingStore::normalize(['x', 1]) === null);
$rt = EmbeddingStore::unpack(EmbeddingStore::pack([0.5, -0.25, 1.0]));
t('pack/unpack round-trips float32', count($rt) === 3 && abs($rt[0] - 0.5) < 1e-6 && abs($rt[1] + 0.25) < 1e-6);

// ── incremental sync ──
$r = EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
t('first sync embeds every package', $r['embedded'] === 4 && $r['unchanged'] === 0 && $r['failed'] === 0 && $r['remaining'] === 0 && $r['stopped'] === null);
$calls = 0; $r = EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
t('second sync is a no-op: nothing re-embedded, no embedder call', $r['embedded'] === 0 && $r['unchanged'] === 4 && $calls === 0);
$docs2 = $docs; $docs2[9002] .= ' with new cloud forest section';
$calls = 0; $r = EmbeddingStore::sync($dbh, $docs2, $stub, $KEY, 2);
t('changed text -> only that package is re-embedded', $r['embedded'] === 1 && $r['unchanged'] === 3 && $calls === 1);
$cv = EmbeddingStore::coverage($dbh, $docs, $KEY);
t('coverage reports stale rows (text changed back) instead of trusting them', $cv['indexed'] === 3 && $cv['stale'] === 1 && count(EmbeddingStore::load($dbh, $docs, $KEY)) === 3);
EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
$r = EmbeddingStore::sync($dbh, $docs, $stub, $KEY . '-v2', 2);
t('a different model key re-embeds everything and never mixes old vectors', $r['embedded'] === 4 && EmbeddingStore::coverage($dbh, $docs, $KEY)['indexed'] === 0 && EmbeddingStore::coverage($dbh, $docs, $KEY . '-v2')['indexed'] === 4);
EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
$small = $docs; unset($small[9004]);
$r = EmbeddingStore::sync($dbh, $small, $stub, $KEY, 2);
t('an unpublished/deleted package has its stored vector removed', $r['removed'] === 1 && !isset(EmbeddingStore::load($dbh, $docs, $KEY)[9004]));
$clean();
$r = EmbeddingStore::sync($dbh, $docs, fn($x) => null, $KEY, 2);
t('provider failure: nothing written, reported as provider_error', $r['embedded'] === 0 && $r['failed'] === 2 && $r['stopped'] === 'provider_error' && EmbeddingStore::coverage($dbh, $docs, $KEY)['indexed'] === 0);
$n = 0; $flaky = function ($x) use ($stub, &$n) { return ++$n === 1 ? $stub($x) : null; };
$r = EmbeddingStore::sync($dbh, $docs, $flaky, $KEY, 2);
t('provider dies mid-run: finished batches are kept (resumable), the rest reported', $r['embedded'] === 2 && $r['stopped'] === 'provider_error' && $r['remaining'] === 2);
$r = EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
t('resuming completes the remaining packages only', $r['embedded'] === 2 && $r['unchanged'] === 2 && EmbeddingStore::coverage($dbh, $docs, $KEY)['indexed'] === 4);
$clean();
$r = EmbeddingStore::sync($dbh, $docs, fn($x) => array_map(fn($i) => $i % 2 ? [1.0, 2.0] : [1.0, 2.0, 3.0], array_keys($x)), $KEY, 2);
t('mixed vector sizes in a batch are rejected (nothing stored from it)', $r['stopped'] === 'bad_vectors' && EmbeddingStore::coverage($dbh, $docs, $KEY)['indexed'] === 0);
$r = EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 1, 2);
t('maxDocs limit stops early and says so', $r['embedded'] === 2 && $r['stopped'] === 'limit' && $r['remaining'] === 2);
$r = EmbeddingStore::sync($dbh, $docs, function ($x) use ($stub) { usleep(60000); return $stub($x); }, $KEY . 'd', 1, 0, 0.05);
t('time budget stops a long run', $r['stopped'] === 'deadline' && $r['remaining'] > 0);
$clean(); EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);

// ── hybrid search ──
$packed = EmbeddingStore::load($dbh, $docs, $KEY);
$qv = fn(string $q) => EmbeddingStore::normalize($stub([$q])[0]);
$ids = fn(array $h) => array_column($h, 'id');
$bm = $ix->search('romantic couples getaway', 5);
t('baseline: keyword search alone finds nothing for a query that shares no words with the package', $bm === []);
$h = HybridSearch::search($ix, $packed, $qv('romantic couples getaway'), 'romantic couples getaway', 5, [], 0.5);
t('hybrid recovers the semantically matching package; marked dense-only; real citation', $ids($h) === [9001] && $h[0]['via'] === 'dense' && $h[0]['citation']['id'] === 'pkg:9001' && $h[0]['meta']['title'] === 'Honeymoon Retreat Cottage');
$h = HybridSearch::search($ix, $packed, $qv('সমুদ্র সৈকত'), 'সমুদ্র সৈকত', 5, [], 0.5);
t('a Bengali query finds the English-titled beach package (cross-language through the vectors)', $ids($h) === [9003]);
$h = HybridSearch::search($ix, $packed, $qv('beach resort'), 'beach resort', 5, [], 0.5);
t('keyword and meaning agree -> via=both, ranked first', $ids($h)[0] === 9003 && $h[0]['via'] === 'both' && $h[0]['bm25_rank'] === 1 && $h[0]['dense_rank'] === 1);
$h = HybridSearch::search($ix, $packed, $qv('romantic honeymoon'), 'romantic honeymoon', 5, ['max_price' => 10000], 0.5);
t('price ceiling applies to semantic hits too (20000 package excluded under 10000)', !in_array(9001, $ids($h), true));
$h = HybridSearch::search($ix, $packed, $qv('quantum physics lecture'), 'quantum physics lecture', 5, [], 0.5);
t('an unrelated query returns nothing (minimum similarity), not the "least bad" package', $h === []);
$h = HybridSearch::search($ix, $packed, $qv('romantic'), 'romantic', 5, ['country' => 'Narnia'], 0.5);
t('country filter applies to semantic hits too', $h === []);
$h1 = HybridSearch::search($ix, $packed, $qv('beach hill'), 'beach hill', 5, [], 0.2); $h2 = HybridSearch::search($ix, $packed, $qv('beach hill'), 'beach hill', 5, [], 0.2);
t('results are deterministic', $ids($h1) === $ids($h2) && count($h1) >= 2);
$short = [9001 => EmbeddingStore::pack([1.0, 0.0])];
t('vectors of another dimension are skipped, never compared', HybridSearch::search($ix, $short, $qv('romantic'), 'romantic', 5, [], 0.0) === []);
t('limit k is honoured', count(HybridSearch::search($ix, $packed, $qv('beach hill romantic museum'), 'beach hill romantic museum', 2, [], 0.0)) === 2);

// ── ToolRegistry integration ──
$rr = new EmbeddingReranker($stub, $KEY);
$reg = fn() => new ToolRegistry($ix, $dbh, $rr);
$clean(); $g = $reg(); $res = $g->execute('search_packages', ['query' => 'romantic couples getaway', 'limit' => 5], []);
t('not indexed yet: registry stays on the old path (no hybrid), no crash', $g->lastRetrieval !== 'hybrid' && $res['ok']);
EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2); $g = $reg();
$res = $g->execute('search_packages', ['query' => 'romantic couples getaway', 'limit' => 5], []);
t('fully indexed: registry uses hybrid and returns the semantic match with its citation', $g->lastRetrieval === 'hybrid' && ($res['data'][0]['id'] ?? 0) === 9001 && ($res['data'][0]['citation']['id'] ?? '') === 'pkg:9001');
$dbh->exec('DELETE FROM tbl_ai_embeddings WHERE PackageId IN (9003, 9004)'); $g = $reg();
$g->execute('search_packages', ['query' => 'romantic couples getaway', 'limit' => 5], []);
t('only 50% indexed (< 80%): hybrid is NOT used (never half a catalogue)', $g->lastRetrieval !== 'hybrid');
EmbeddingStore::sync($dbh, $docs, $stub, $KEY, 2);
$rrDead = new EmbeddingReranker(fn($x) => null, $KEY); $g = new ToolRegistry($ix, $dbh, $rrDead);
$res = $g->execute('search_packages', ['query' => 'beach resort', 'limit' => 5], []);
t('embedding service down at query time: falls back to keyword results', $g->lastRetrieval === 'bm25' && ($res['data'][0]['id'] ?? 0) === 9003);
$g = new ToolRegistry($ix, $dbh, null);
$res = $g->execute('search_packages', ['query' => 'beach resort', 'limit' => 5], []);
t('no embedding model configured: exactly the old keyword behaviour', $g->lastRetrieval === 'bm25' && ($res['data'][0]['id'] ?? 0) === 9003);
$g = $reg();
$run = ToolRouter::run($g, [['tool' => 'search_packages', 'args' => ['query' => 'romantic couples getaway', 'limit' => 5]]], [], ['honeymoon']);
t("the user's avoid-list still removes a semantically found package", !str_contains($run['facts'], 'Honeymoon Retreat') && !array_filter($run['citations'], fn($c) => $c['id'] === 'pkg:9001'));
$docs3 = $docs; $docs3[9001] .= ' renovated'; $ix3 = KnowledgeIndex::fromRows(array_map(fn($r) => $r['PackageId'] === 9001 ? ['PackageName' => $r['PackageName'] . ' renovated'] + $r : $r, $rows));
$g = new ToolRegistry($ix3, $dbh, $rr); $g->execute('search_packages', ['query' => 'romantic', 'limit' => 5], []);
t('text changed after indexing: 3 of 4 current (75% < 80%), so hybrid is not trusted', $g->lastRetrieval !== 'hybrid');
$st3 = EmbeddingStore::coverage($dbh, array_map(fn($m) => EmbeddingStore::textFor($m), $ix3->all()), $KEY);
t('...it is counted as stale until the next sync', $st3['stale'] === 1 && $st3['indexed'] === 3);
t('the ToolRegistry schema is unchanged (hybrid is invisible to the model)', array_keys((new ToolRegistry($ix, $dbh, $rr))->execute('search_packages', ['query' => 'beach', 'limit' => 1], [])['data'][0]) === ['id', 'title', 'location', 'country', 'price_bdt', 'duration', 'citation']);
$clean();
foreach (glob(sys_get_temp_dir() . '/tms_emb_*.json') ?: [] as $f) { if (str_contains((string)@file_get_contents($f), '"') && filemtime($f) > time() - 300) @unlink($f); }
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
