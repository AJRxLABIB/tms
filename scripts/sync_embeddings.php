<?php
/**
 * scripts/sync_embeddings.php — store embeddings for every published package so search can combine keywords and meaning.
 * CLI/cron only. Incremental and safe to re-run: only new or changed packages (or a changed AI_EMBED_MODEL) are embedded.
 *
 *   php scripts/sync_embeddings.php [--limit=200] [--seconds=240]
 *
 * Needs AI_EMBED_MODEL in .env and a running Ollama (see .env.example). Without them it says so and exits without changing anything.
 * Run it once after enabling, then daily (or whenever packages change), e.g. Windows Task Scheduler: php.exe "D:\XAMPP\htdocs\tms\scripts\sync_embeddings.php"
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }
require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/ai/rag/EmbeddingReranker.php';
require_once __DIR__ . '/../backend/ai/rag/EmbeddingStore.php';
$limit = 0; $secs = 240.0;
foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1]; elseif (preg_match('/^--seconds=(\d+)$/', $a, $m)) $secs = (float)$m[1]; }
$rr = EmbeddingReranker::fromEnv(60);
if (!$rr) { echo "AI_EMBED_MODEL is not set in .env: nothing to do (search stays keyword-only).\n"; exit(0); }
EmbeddingStore::migrate($dbh);
$r = EmbeddingStore::syncCatalogue($dbh, $rr, $limit, $secs);
$c = EmbeddingStore::catalogueCoverage($dbh, $rr);
echo "Model: " . $rr->modelKey() . "\n";
echo "Embedded {$r['embedded']}, unchanged {$r['unchanged']}, removed {$r['removed']}, failed {$r['failed']}, remaining {$r['remaining']}" . ($r['stopped'] ? " (stopped: {$r['stopped']})" : '') . "\n";
echo "Coverage: {$c['indexed']} of {$c['total']} packages indexed" . ($c['total'] > 0 && $c['indexed'] < 0.8 * $c['total'] ? " - hybrid search stays OFF until 80% is reached" : '') . "\n";
exit($r['stopped'] === 'provider_error' || $r['stopped'] === 'bad_vectors' ? 1 : 0);
