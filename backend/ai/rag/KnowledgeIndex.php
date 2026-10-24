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
require_once __DIR__ . '/RetrievedText.php';

final class KnowledgeIndex
{
    private const K1 = 1.4;
    private const B  = 0.75;
    private const FIELD_WEIGHT = ['name' => 3, 'loc' => 2, 'type' => 2, 'body' => 1];

    /** @var array<int,array> */ private array $docs = [];
    /** @var array<string,int> */ private array $df = [];
    private float $avgLen = 1.0;
    /** True only for an index built from the live table (fromDb). Fixture indexes built with fromRows() have no live table to re-check. */
    private bool $fromDb = false;

    public function isFromDb(): bool { return $this->fromDb; }

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
        // Built index is cached for 10 minutes (shared CacheService when present) so a chat burst does not re-tokenise the whole
        // catalogue per message. The cache key carries a cheap fingerprint of the live table (row count, price sum, published
        // count, highest id), so a re-priced, unpublished or newly published package changes the key and the stale index is never
        // served (audit item 19). ToolRegistry additionally re-checks every package it is about to quote against the live table.
        global $tmsCache;
        $key = 'rag_index_v2_' . self::fingerprint($db);
        if (isset($tmsCache)) {
            $hit = $tmsCache->get($key);
            if (is_string($hit) && $hit !== '') {
                $obj = @unserialize($hit, ['allowed_classes' => [self::class]]);
                if ($obj instanceof self) return $obj;
            }
        }
        $ix = self::buildFromDb($db, $limit);
        if (isset($tmsCache)) { try { $tmsCache->set($key, serialize($ix), 600); } catch (Throwable $e) {} }
        return $ix;
    }

    /** Short hash that changes whenever a package is added, removed, (un)published, approved or re-priced. */
    public static function fingerprint(PDO $db): string
    {
        try {
            $r = $db->query("SELECT COUNT(*), COALESCE(SUM(PackagePrice),0), COALESCE(SUM(Status),0), COALESCE(MAX(PackageId),0) FROM tbltourpackages")->fetch(PDO::FETCH_NUM) ?: [];
            $a = '';
            try { $a = (string)$db->query("SELECT COUNT(*) FROM tbltourpackages WHERE Status = 1 AND (ApprovalStatus IS NULL OR ApprovalStatus = 'approved')")->fetchColumn(); } catch (Throwable $e) { $a = 'noapproval'; }
            return substr(md5(implode('|', $r) . '|' . $a), 0, 12);
        } catch (Throwable $e) { return 'nofp'; }
    }

    /**
     * Live check of the packages a tool is about to quote: only ids that are published AND approved right now are returned, with their
     * CURRENT price, duration and name. Anything else (unpublished, rejected, deleted) is absent, so it cannot be quoted.
     * @param int[] $ids
     * @return array<int,array{price:int,duration:string,title:string}>
     */
    public static function liveCheck(PDO $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("SELECT PackageId, PackageName, PackagePrice, PackageDuration FROM tbltourpackages WHERE PackageId IN ($in) AND Status = 1 AND (ApprovalStatus IS NULL OR ApprovalStatus = 'approved')");
            $st->execute($ids);
        } catch (Throwable $e) {
            // No ApprovalStatus column (very old install): published = Status 1.
            $st = $db->prepare("SELECT PackageId, PackageName, PackagePrice, PackageDuration FROM tbltourpackages WHERE PackageId IN ($in) AND Status = 1");
            $st->execute($ids);
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['PackageId']] = ['price' => (int)$r['PackagePrice'], 'duration' => (string)$r['PackageDuration'], 'title' => (string)$r['PackageName']];
        return $out;
    }

    private static function buildFromDb(PDO $db, int $limit): self
    {
        $cols = "PackageId, PackageName, PackageType, PackageLocation, Country, Region, PackagePrice,
                    PackageDuration, PackageFetures, PackageDescription, Inclusions, Exclusions,
                    CancellationPolicy, AvgRating";
        $variants = [
            [$cols . ", MealPlan, HotelRating", true],   // extra signals used only for deterministic re-ranking
            [$cols, true],
            [$cols, false],                               // install without ApprovalStatus
        ];
        $lastErr = null;
        foreach ($variants as [$c, $withApproval]) {
            try {
                $where = $withApproval ? "Status = 1 AND (ApprovalStatus IS NULL OR ApprovalStatus = 'approved')" : "Status = 1";
                $st = $db->prepare("SELECT $c FROM tbltourpackages WHERE $where ORDER BY PackageId LIMIT " . (int)$limit);
                $st->execute();
                $ix = self::fromRows($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
                $ix->fromDb = true;
                return $ix;
            } catch (Throwable $e) { $lastErr = $e; }
        }
        throw $lastErr ?? new RuntimeException('package index could not be built');
    }

    private function add(array $r): void
    {
        $id = (int)($r['PackageId'] ?? 0);
        if ($id <= 0) return;
        $f = fn(string $k) => trim((string)($r[$k] ?? ''));
        // Free text written by suppliers/staff is untrusted: control characters stripped, length capped, and a description that
        // contains instruction-like text is withheld (structured fields such as name, place and price are unaffected).
        $tx = fn(string $k, int $max = 4000) => RetrievedText::clean($r[$k] ?? '', $max);
        $fields = [
            'name' => $f('PackageName'),
            'loc'  => trim($f('PackageLocation') . ' ' . $f('Country') . ' ' . $f('Region')),
            'type' => $f('PackageType'),
            'body' => trim($tx('PackageFetures') . ' ' . $tx('PackageDescription') . ' ' . $tx('Inclusions') . ' ' . $f('PackageDuration')),
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
                'cancellation' => $tx('CancellationPolicy', 1500),
                'inclusions' => $tx('Inclusions', 600), 'exclusions' => $tx('Exclusions', 600),
                'meal_plan' => $f('MealPlan'), 'hotel_rating' => (float)($r['HotelRating'] ?? 0),
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
        if (isset($filters['days_min'], $filters['days_max']) && !self::durationOverlaps((string)($meta['duration'] ?? ''), (int)$filters['days_min'], (int)$filters['days_max'])) return false;
        return true;
    }

    /**
     * Does the package's free-text length ("3 Days/2 Nights", "3-4 Days", "Half Day") overlap the requested day range? Same rule as
     * NLSearchParser::durationOverlaps (kept here so retrieval has no dependency on the search-page parser).
     */
    public static function durationOverlaps(string $text, int $reqMin, int $reqMax): bool
    {
        preg_match_all('/\d+/', $text, $m); $n = array_map('intval', $m[0] ?? []);
        if (!$n) { $lo = 0; $hi = 1; }                       // "Half Day"
        elseif (preg_match('/(\d+)\s*days?\s*[\/,&-]?\s*(\d+)\s*nights?/i', $text, $dn)) { $lo = $hi = (int)$dn[1]; }   // "3 Days/2 Nights" is a 3-day trip
        else { $lo = min($n); $hi = max($n); }
        return $lo <= $reqMax && $hi >= $reqMin;
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
