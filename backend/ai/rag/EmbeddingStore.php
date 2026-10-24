<?php
/**
 * EmbeddingStore — persistent package embeddings (tbl_ai_embeddings), so semantic retrieval can search the WHOLE catalogue
 * instead of only re-ordering candidates that keyword search already found.
 *
 *  - Vectors are L2-normalised and stored as packed float32 (cosine similarity = dot product).
 *  - Each row records the model key and a hash of the text it was built from. A changed package text or a different model
 *    means the row is stale and is re-embedded; vectors from different models/dimensions are never compared.
 *  - sync() is incremental and resumable (cron or admin button). It embeds in small batches, stops at the first provider
 *    failure or at a time budget, and never deletes a good row because of a failed call.
 *  - Nothing here runs during a customer request except reading vectors.
 */
final class EmbeddingStore
{
    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_ai_embeddings` (
  `PackageId` int(11) NOT NULL,
  `ModelKey` varchar(80) NOT NULL,
  `ContentHash` char(40) NOT NULL,
  `Dim` smallint(6) NOT NULL,
  `Vec` mediumblob NOT NULL,
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PackageId`),
  KEY `idx_emb_model` (`ModelKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function normalize(array $v): ?array
    {
        $n = 0.0; foreach ($v as $x) { if (!is_numeric($x)) return null; $n += $x * $x; }
        if ($n <= 0) return null;
        $n = sqrt($n); return array_map(fn($x) => (float)$x / $n, $v);
    }
    public static function pack(array $v): string { return pack('g*', ...$v); }
    /** @return float[] */
    public static function unpack(string $blob): array { $u = unpack('g*', $blob); return $u ? array_values($u) : []; }
    public static function dot(array $a, array $b): float { $s = 0.0; $n = min(count($a), count($b)); for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i]; return $s; }

    /** Text a package is embedded as (same fields shown to the reranker). */
    public static function textFor(array $meta): string
    {
        return trim(implode('. ', array_filter([$meta['title'] ?? '', $meta['type'] ?? '', $meta['location'] ?? '', $meta['country'] ?? '', $meta['duration'] ?? '', mb_substr((string)($meta['inclusions'] ?? ''), 0, 300)])));
    }

    /**
     * @param array<int,string> $docs package id => text (the full current catalogue)
     * @param callable(array<int,string>):?array<int,array<int,float>> $embed
     * @return array{embedded:int,unchanged:int,failed:int,removed:int,remaining:int,stopped:?string}
     */
    public static function sync(PDO $dbh, array $docs, callable $embed, string $modelKey, int $batch = 16, int $maxDocs = 0, float $deadlineSec = 0.0): array
    {
        $t0 = microtime(true); $modelKey = mb_substr($modelKey, 0, 80);
        $have = [];
        foreach ($dbh->query('SELECT PackageId, ModelKey, ContentHash, Dim FROM tbl_ai_embeddings')->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(int)$r['PackageId']] = $r;
        // rows for packages that no longer exist (unpublished/deleted)
        $removed = 0; $del = $dbh->prepare('DELETE FROM tbl_ai_embeddings WHERE PackageId = ?');
        foreach ($have as $id => $_) if (!isset($docs[$id])) { $del->execute([$id]); $removed++; unset($have[$id]); }
        $todo = [];
        foreach ($docs as $id => $text) {
            $h = sha1($text);
            if (isset($have[$id]) && $have[$id]['ModelKey'] === $modelKey && $have[$id]['ContentHash'] === $h) continue;
            $todo[$id] = $text;
        }
        $unchanged = count($docs) - count($todo);
        $up = $dbh->prepare('INSERT INTO tbl_ai_embeddings (PackageId, ModelKey, ContentHash, Dim, Vec) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE ModelKey = VALUES(ModelKey), ContentHash = VALUES(ContentHash), Dim = VALUES(Dim), Vec = VALUES(Vec)');
        $embedded = 0; $failed = 0; $stopped = null; $dim = null;
        foreach (array_chunk($todo, max(1, $batch), true) as $chunk) {
            if ($maxDocs > 0 && $embedded >= $maxDocs) { $stopped = 'limit'; break; }
            if ($deadlineSec > 0 && microtime(true) - $t0 > $deadlineSec) { $stopped = 'deadline'; break; }
            try { $vecs = $embed(array_values($chunk)); } catch (Throwable $e) { $vecs = null; }
            if (!is_array($vecs) || count($vecs) !== count($chunk)) { $failed += count($chunk); $stopped = 'provider_error'; break; }
            $ids = array_keys($chunk); $rows = [];
            foreach ($vecs as $i => $v) {
                $n = is_array($v) ? self::normalize($v) : null;
                if ($n === null || ($dim !== null && count($n) !== $dim)) { $rows = null; break; }
                $dim ??= count($n); $rows[$ids[$i]] = $n;
            }
            if ($rows === null) { $failed += count($chunk); $stopped = 'bad_vectors'; break; }
            foreach ($rows as $id => $n) { $up->execute([$id, $modelKey, sha1($chunk[$id]), count($n), self::pack($n)]); $embedded++; }
        }
        return ['embedded' => $embedded, 'unchanged' => $unchanged, 'failed' => $failed, 'removed' => $removed, 'remaining' => max(0, count($todo) - $embedded), 'stopped' => $stopped];
    }

    /** @return array{indexed:int,total:int,stale:int} rows usable for $modelKey vs current catalogue */
    public static function coverage(PDO $dbh, array $docs, string $modelKey): array
    {
        $ok = 0;
        try {
            $st = $dbh->prepare('SELECT PackageId, ContentHash FROM tbl_ai_embeddings WHERE ModelKey = ?'); $st->execute([mb_substr($modelKey, 0, 80)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $id = (int)$r['PackageId']; if (isset($docs[$id]) && sha1($docs[$id]) === $r['ContentHash']) $ok++; }
        } catch (Throwable $e) { /* table missing */ }
        return ['indexed' => $ok, 'total' => count($docs), 'stale' => count($docs) - $ok];
    }

    /** @return array<int,string> id => packed normalised vector, only rows for $modelKey whose text is unchanged and with one consistent dimension */
    public static function load(PDO $dbh, array $docs, string $modelKey): array
    {
        $out = []; $dim = null;
        try {
            $st = $dbh->prepare('SELECT PackageId, ContentHash, Dim, Vec FROM tbl_ai_embeddings WHERE ModelKey = ?'); $st->execute([mb_substr($modelKey, 0, 80)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int)$r['PackageId'];
                if (!isset($docs[$id]) || sha1($docs[$id]) !== $r['ContentHash']) continue;
                $d = (int)$r['Dim']; if (strlen($r['Vec']) !== $d * 4) continue;
                $dim ??= $d; if ($d !== $dim) continue;
                $out[$id] = $r['Vec'];
            }
        } catch (Throwable $e) { return []; }
        return $out;
    }

    /** Index the current published catalogue with the configured embedder (cron script and admin button share this). */
    public static function syncCatalogue(PDO $dbh, EmbeddingReranker $rr, int $maxDocs = 0, float $deadlineSec = 0.0): array
    {
        require_once __DIR__ . '/KnowledgeIndex.php';
        $docs = []; foreach (KnowledgeIndex::fromDb($dbh)->all() as $id => $m) $docs[$id] = self::textFor($m);
        return self::sync($dbh, $docs, $rr->embedder(), $rr->modelKey(), 16, $maxDocs, $deadlineSec);
    }

    /** @return array{indexed:int,total:int,stale:int} for the current catalogue and model */
    public static function catalogueCoverage(PDO $dbh, EmbeddingReranker $rr): array
    {
        require_once __DIR__ . '/KnowledgeIndex.php';
        $docs = []; foreach (KnowledgeIndex::fromDb($dbh)->all() as $id => $m) $docs[$id] = self::textFor($m);
        return self::coverage($dbh, $docs, $rr->modelKey());
    }
}
