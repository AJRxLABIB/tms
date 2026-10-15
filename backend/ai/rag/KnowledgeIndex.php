<?php
/**
 * KnowledgeIndex — local, permission-aware retrieval over the REAL catalogue.
 *
 * Retrieval is BM25 over documents built from published packages
 * (tbltourpackages.Status = 1 only). It needs no embeddings model, so it runs
 * on a CPU-only dev PC and is deterministic and unit-testable. A dense
 * embedding re-ranker (Ollama /api/embeddings) can later be layered on top of
 * the same documents; the document shape does not change.
 *
 * Permissions: only published, public package data is indexed. Anything that
 * belongs to a user (bookings, wallet) is NEVER in this index — it is only
 * reachable through ToolRegistry::my_bookings, which takes the identity from
 * the server-side session, never from model output.
 *
 * Every hit carries a citation (id, title, URL) so answers can point to the
 * exact package page.
 */
require_once __DIR__ . '/Tokenizer.php';

final class KnowledgeIndex
{
    private const K1 = 1.4;
    private const B  = 0.75;
    private const FIELD_WEIGHT = ['name' => 3, 'loc' => 2, 'type' => 2, 'body' => 1];

    /** @var array<int,array> */ private array $docs = [];
    /** @var array<string,int> */ private array $df = [];
    private float $avgLen = 1.0;

    /** Build from an array of package rows (assoc). Pure; no DB. */
    public static function fromRows(array $rows): self
    {
        $ix = new self();
        foreach ($rows as $r) $ix->add($r);
        $ix->finalize();
        return $ix;
    }

    /** Build from the live DB (published packages only). */
    public static function fromDb(PDO $db, int $limit = 5000): self
    {
        // Built index is cached for 10 minutes (shared CacheService when present)
        // so a chat burst does not re-tokenise the whole catalogue per message.
        global $tmsCache;
        if (isset($tmsCache)) {
            $hit = $tmsCache->get('rag_index_v1');
            if (is_string($hit) && $hit !== '') {
                $obj = @unserialize($hit, ['allowed_classes' => [self::class]]);
                if ($obj instanceof self) return $obj;
            }
        }
        $ix = self::buildFromDb($db, $limit);
        if (isset($tmsCache)) { try { $tmsCache->set('rag_index_v1', serialize($ix), 600); } catch (Throwable $e) {} }
        return $ix;
    }

    private static function buildFromDb(PDO $db, int $limit): self
    {
        $st = $db->prepare(
            "SELECT PackageId, PackageName, PackageType, PackageLocation, Country, Region, PackagePrice,
                    PackageDuration, PackageFetures, PackageDescription, Inclusions, Exclusions,
                    CancellationPolicy, AvgRating
               FROM tbltourpackages WHERE Status = 1 ORDER BY PackageId LIMIT " . (int)$limit
        );
        $st->execute();
        return self::fromRows($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function add(array $r): void
    {
        $id = (int)($r['PackageId'] ?? 0);
        if ($id <= 0) return;
        $f = fn(string $k) => trim((string)($r[$k] ?? ''));
        $fields = [
            'name' => $f('PackageName'),
            'loc'  => trim($f('PackageLocation') . ' ' . $f('Country') . ' ' . $f('Region')),
            'type' => $f('PackageType'),
            'body' => trim($f('PackageFetures') . ' ' . $f('PackageDescription') . ' ' . $f('Inclusions') . ' ' . $f('PackageDuration')),
        ];
        $tf = []; $len = 0;
        foreach ($fields as $fname => $text) {
            foreach (RagTokenizer::tokens($text, false) as $t) {
                $tf[$t] = ($tf[$t] ?? 0) + self::FIELD_WEIGHT[$fname];
                $len += self::FIELD_WEIGHT[$fname];
            }
        }
        $this->docs[$id] = [
            'id' => $id, 'tf' => $tf, 'len' => max(1, $len),
            'meta' => [
                'title' => $f('PackageName'), 'location' => $f('PackageLocation'), 'country' => $f('Country'),
                'type' => $f('PackageType'), 'price' => (int)($r['PackagePrice'] ?? 0),
                'duration' => $f('PackageDuration'), 'rating' => (float)($r['AvgRating'] ?? 0),
                'cancellation' => $f('CancellationPolicy'),
                'inclusions' => $f('Inclusions'), 'exclusions' => $f('Exclusions'),
            ],
        ];
    }

    private function finalize(): void
    {
        $total = 0;
        foreach ($this->docs as $d) {
            $total += $d['len'];
            foreach ($d['tf'] as $t => $_) $this->df[$t] = ($this->df[$t] ?? 0) + 1;
        }
        $this->avgLen = $this->docs ? $total / count($this->docs) : 1.0;
    }

    public function size(): int { return count($this->docs); }

    /** Same filter rules for every retrieval path (BM25 and dense). */
    public static function passes(array $meta, array $filters): bool
    {
        if (isset($filters['max_price']) && $filters['max_price'] > 0 && $meta['price'] > $filters['max_price']) return false;
        if (isset($filters['min_price']) && $filters['min_price'] > 0 && $meta['price'] < $filters['min_price']) return false;
        if (!empty($filters['country']) && stripos($meta['country'], (string)$filters['country']) === false) return false;
        return true;
    }

    /** @return array<int,array> id => meta, for every published package (used to build embeddings and dense retrieval) */
    public function all(): array
    {
        $o = []; foreach ($this->docs as $id => $d) $o[$id] = $d['meta']; return $o;
    }

    /**
     * @param array{max_price?:int,min_price?:int,country?:string} $filters
     * @return array<int,array> hits sorted by score, each with citation
     */
    public function search(string $query, int $k = 5, array $filters = []): array
    {
        $q = RagTokenizer::tokens($query, true);
        if (!$q || !$this->docs) return [];
        $n = count($this->docs);
        $hits = [];
        foreach ($this->docs as $d) {
            $m = $d['meta'];
            if (!self::passes($m, $filters)) continue;
            $score = 0.0;
            foreach (array_unique($q) as $t) {
                $f = $d['tf'][$t] ?? 0;
                if (!$f) continue;
                $idf = log(1 + ($n - ($this->df[$t] ?? 0) + 0.5) / (($this->df[$t] ?? 0) + 0.5));
                $score += $idf * ($f * (self::K1 + 1)) / ($f + self::K1 * (1 - self::B + self::B * $d['len'] / $this->avgLen));
            }
            if ($score > 0) {
                $hits[] = ['score' => round($score, 4), 'id' => $d['id'], 'meta' => $m,
                           'citation' => ['id' => 'pkg:' . $d['id'], 'title' => $m['title'], 'url' => 'package-details.php?pkgid=' . $d['id']]];
            }
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        return array_slice($hits, 0, max(1, min(20, $k)));
    }

    public function get(int $id): ?array
    {
        if (!isset($this->docs[$id])) return null;
        $m = $this->docs[$id]['meta'];
        return $m + ['id' => $id, 'citation' => ['id' => 'pkg:' . $id, 'title' => $m['title'], 'url' => 'package-details.php?pkgid=' . $id]];
    }
}
