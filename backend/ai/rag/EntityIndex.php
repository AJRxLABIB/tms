<?php
/**
 * EntityIndex — local BM25 retrieval over the OTHER catalogue entities the assistant may talk about:
 *   hotel     tblhotels            (Status = 1 only)
 *   activity  tblactivities        (Status = 1 only)
 *   guide     tbl_bd_destination_guides (curated, sourced content; no prices)
 *
 * Why separate from KnowledgeIndex: packages are keyed by integer PackageId and carry package-specific fields; these entities have
 * different ids, price units (per night / per person) and a demo flag. The tokenizer, BM25 maths, Bengali/Banglish handling and the
 * "only approved columns are indexed" rule are the same.
 *
 * What is indexed (an allow-list, nothing else): names, place, category, amenities, description / guide text, price, rating, stars.
 * Never indexed: emails, supplier contact details, bookings, internal notes, API keys.
 *
 * DEMO DATA: tblhotels / tblactivities rows default to IsSampleData = 1 (no real supplier feed). Such rows ARE searchable (so the
 * assistant can say "we list a demonstration hotel called X") but are flagged demo = true and their price is never exposed as a
 * fact: see EntityIndex::publicMeta(). Availability is never inferred from this index for any row.
 *
 * Free text is passed through RetrievedText::clean(): supplier-written descriptions are untrusted.
 */
require_once __DIR__ . '/Tokenizer.php';
require_once __DIR__ . '/RetrievedText.php';

final class EntityIndex
{
    private const K1 = 1.4;
    private const B  = 0.75;
    private const FIELD_WEIGHT = ['name' => 3, 'loc' => 2, 'type' => 2, 'body' => 1];
    public const KINDS = ['hotel', 'activity', 'guide'];

    /** @var array<string,array> key "kind:id" => doc */
    private array $docs = [];
    /** @var array<string,int> */ private array $df = [];
    private float $avgLen = 1.0;

    /** Normalise one raw DB row to the common document shape. Pure. @return ?array */
    public static function normalize(string $kind, array $r): ?array
    {
        $s = fn(string $k, int $max = 4000) => RetrievedText::clean($r[$k] ?? '', $max);
        $plain = fn(string $k) => trim((string)($r[$k] ?? ''));
        if ($kind === 'hotel') {
            $id = (string)(int)($r['HotelId'] ?? 0); if ($id === '0') return null;
            return ['kind' => 'hotel', 'id' => $id, 'title' => $plain('HotelName'), 'city' => $plain('City'), 'country' => $plain('Country'),
                'type' => ((int)($r['StarRating'] ?? 0) > 0 ? (int)$r['StarRating'] . ' star hotel' : 'hotel'),
                'price' => isset($r['PricePerNight']) ? (int)$r['PricePerNight'] : null, 'unit' => 'per_night',
                'stars' => (int)($r['StarRating'] ?? 0), 'hours' => null, 'rating' => (float)($r['AvgRating'] ?? 0),
                'demo' => !array_key_exists('IsSampleData', $r) || (int)$r['IsSampleData'] === 1,
                'text' => trim($s('Amenities', 500) . ' ' . $s('Description')), 'category' => 'hotel'];
        }
        if ($kind === 'activity') {
            $id = (string)(int)($r['ActivityId'] ?? 0); if ($id === '0') return null;
            return ['kind' => 'activity', 'id' => $id, 'title' => $plain('ActivityName'), 'city' => $plain('City'), 'country' => $plain('Country'),
                'type' => $plain('Category'),
                'price' => isset($r['PricePerPerson']) ? (int)$r['PricePerPerson'] : null, 'unit' => 'per_person',
                'stars' => 0, 'hours' => isset($r['DurationHours']) ? (float)$r['DurationHours'] : null, 'rating' => (float)($r['AvgRating'] ?? 0),
                'demo' => !array_key_exists('IsSampleData', $r) || (int)$r['IsSampleData'] === 1,
                'text' => $s('Description'), 'category' => mb_strtolower($plain('Category'), 'UTF-8')];
        }
        if ($kind === 'guide') {
            $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($plain('slug'))); if ($slug === '') return null;
            return ['kind' => 'guide', 'id' => $slug, 'title' => $plain('name'), 'city' => $plain('name'), 'country' => 'Bangladesh', 'type' => 'destination guide',
                'price' => null, 'unit' => null, 'stars' => 0, 'hours' => null, 'rating' => 0.0, 'demo' => false,
                'text' => trim($s('known_for', 1500) . ' ' . $s('best_time', 800) . ' ' . $s('how_to_get_there', 1500) . ' ' . $s('practical_note', 1500)),
                'category' => 'guide', 'researched_at' => $plain('researched_at'),
                'best_time' => $s('best_time', 600), 'getting_there' => $s('how_to_get_there', 900), 'known_for' => $s('known_for', 700), 'practical_note' => $s('practical_note', 900)];
        }
        return null;
    }

    /** Build from already-normalised or raw rows of one kind. Pure; no DB. */
    public static function fromRows(array $byKind): self
    {
        $ix = new self();
        foreach (self::KINDS as $k) foreach ($byKind[$k] ?? [] as $row) { $d = self::normalize($k, $row); if ($d) $ix->add($d); }
        $ix->finalize();
        return $ix;
    }

    /** Live DB, published rows only. A missing table simply contributes nothing. Cached 10 min under a content fingerprint. */
    public static function fromDb(PDO $db, int $limit = 2000): self
    {
        global $tmsCache;
        $key = 'rag_entities_v1_' . self::fingerprint($db);
        if (isset($tmsCache)) {
            $hit = $tmsCache->get($key);
            if (is_string($hit) && $hit !== '') { $o = @unserialize($hit, ['allowed_classes' => [self::class]]); if ($o instanceof self) return $o; }
        }
        $rows = [];
        $q = [
            'hotel' => "SELECT HotelId, HotelName, City, Country, StarRating, PricePerNight, Amenities, Description, AvgRating, IsSampleData FROM tblhotels WHERE Status = 1 ORDER BY HotelId LIMIT ",
            'activity' => "SELECT ActivityId, ActivityName, City, Country, Category, PricePerPerson, DurationHours, Description, AvgRating, IsSampleData FROM tblactivities WHERE Status = 1 ORDER BY ActivityId LIMIT ",
            'guide' => "SELECT slug, name, known_for, best_time, how_to_get_there, practical_note, researched_at FROM tbl_bd_destination_guides ORDER BY name LIMIT ",
        ];
        foreach ($q as $kind => $sql) {
            try { $rows[$kind] = $db->query($sql . (int)$limit)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
            catch (Throwable $e) { $rows[$kind] = []; error_log('[TourBuddy AI entities] ' . $kind . ': ' . $e->getMessage()); }
        }
        $ix = self::fromRows($rows);
        if (isset($tmsCache)) { try { $tmsCache->set($key, serialize($ix), 600); } catch (Throwable $e) {} }
        return $ix;
    }

    public static function fingerprint(PDO $db): string
    {
        $parts = [];
        foreach (["SELECT COUNT(*), COALESCE(SUM(PricePerNight),0), COALESCE(SUM(Status),0), COALESCE(SUM(IsSampleData),0) FROM tblhotels",
                  "SELECT COUNT(*), COALESCE(SUM(PricePerPerson),0), COALESCE(SUM(Status),0), COALESCE(SUM(IsSampleData),0) FROM tblactivities",
                  "SELECT COUNT(*), COALESCE(MAX(researched_at),'') FROM tbl_bd_destination_guides"] as $sql) {
            try { $parts[] = implode(',', $db->query($sql)->fetch(PDO::FETCH_NUM) ?: []); } catch (Throwable $e) { $parts[] = 'x'; }
        }
        return substr(md5(implode('|', $parts)), 0, 12);
    }

    private function add(array $d): void
    {
        $tf = []; $len = 0;
        $fields = ['name' => $d['title'], 'loc' => trim($d['city'] . ' ' . $d['country']), 'type' => $d['type'], 'body' => $d['text']];
        foreach ($fields as $fname => $text) {
            foreach (RagTokenizer::tokens((string)$text, false) as $t) {
                $tf[$t] = ($tf[$t] ?? 0) + self::FIELD_WEIGHT[$fname];
                $len += self::FIELD_WEIGHT[$fname];
            }
        }
        $key = $d['kind'] . ':' . $d['id'];
        $this->docs[$key] = ['key' => $key, 'tf' => $tf, 'len' => max(1, $len), 'd' => $d];
    }

    private function finalize(): void
    {
        $total = 0;
        foreach ($this->docs as $x) { $total += $x['len']; foreach ($x['tf'] as $t => $_) $this->df[$t] = ($this->df[$t] ?? 0) + 1; }
        $this->avgLen = $this->docs ? $total / count($this->docs) : 1.0;
    }

    public function size(?string $kind = null): int
    {
        if ($kind === null) return count($this->docs);
        return count(array_filter($this->docs, fn($x) => $x['d']['kind'] === $kind));
    }

    /** Filters: country, city, max_price, min_stars, max_hours, category. All case-insensitive substring / numeric. */
    public static function passes(array $d, array $f): bool
    {
        if (!empty($f['country']) && stripos($d['country'], (string)$f['country']) === false) return false;
        if (!empty($f['city']) && stripos($d['city'], (string)$f['city']) === false) return false;
        if (!empty($f['category']) && stripos((string)$d['category'], (string)$f['category']) === false && stripos((string)$d['type'], (string)$f['category']) === false) return false;
        if (isset($f['max_price']) && $f['max_price'] > 0 && $d['price'] !== null && $d['price'] > $f['max_price']) return false;
        if (isset($f['min_stars']) && $f['min_stars'] > 0 && $d['stars'] < $f['min_stars']) return false;
        if (isset($f['max_hours']) && $f['max_hours'] > 0 && $d['hours'] !== null && $d['hours'] > $f['max_hours']) return false;
        return true;
    }

    /**
     * @return array<int,array> hits: ['score','kind','id','d' => doc]. Deterministic order: score desc, rating desc, key asc.
     */
    public function search(string $kind, string $query, int $k = 5, array $filters = []): array
    {
        $q = RagTokenizer::tokens($query, true);
        if (!$q || !$this->docs) return [];
        $n = count($this->docs); $hits = [];
        foreach ($this->docs as $x) {
            $d = $x['d'];
            if ($d['kind'] !== $kind || !self::passes($d, $filters)) continue;
            $score = 0.0;
            foreach (array_unique($q) as $t) {
                $f = $x['tf'][$t] ?? 0; if (!$f) continue;
                $idf = log(1 + ($n - ($this->df[$t] ?? 0) + 0.5) / (($this->df[$t] ?? 0) + 0.5));
                $score += $idf * ($f * (self::K1 + 1)) / ($f + self::K1 * (1 - self::B + self::B * $x['len'] / $this->avgLen));
            }
            if ($score > 0) $hits[] = ['score' => round($score, 4), 'kind' => $kind, 'id' => $d['id'], 'd' => $d];
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score'] ?: $b['d']['rating'] <=> $a['d']['rating'] ?: strcmp($a['kind'] . $a['id'], $b['kind'] . $b['id']));
        return array_slice($hits, 0, max(1, min(20, $k)));
    }

    /** Filter-only listing (no topical words in the query): best rated first, then cheapest, then id. */
    public function browse(string $kind, int $k = 5, array $filters = []): array
    {
        $hits = [];
        foreach ($this->docs as $x) if ($x['d']['kind'] === $kind && self::passes($x['d'], $filters)) $hits[] = ['score' => 0.0, 'kind' => $kind, 'id' => $x['d']['id'], 'd' => $x['d']];
        usort($hits, fn($a, $b) => $b['d']['rating'] <=> $a['d']['rating'] ?: ($a['d']['price'] ?? PHP_INT_MAX) <=> ($b['d']['price'] ?? PHP_INT_MAX) ?: strcmp($a['id'], $b['id']));
        return array_slice($hits, 0, max(1, min(20, $k)));
    }

    public function get(string $kind, string $id): ?array
    {
        return $this->docs[$kind . ':' . $id]['d'] ?? null;
    }

    public static function citation(array $d): array
    {
        return match ($d['kind']) {
            'hotel' => ['id' => 'hotel:' . $d['id'], 'title' => $d['title'], 'url' => 'hotel-details.php?id=' . $d['id']],
            'activity' => ['id' => 'activity:' . $d['id'], 'title' => $d['title'], 'url' => 'activity-details.php?id=' . $d['id']],
            default => ['id' => 'guide:' . $d['id'], 'title' => $d['title'], 'url' => 'bd-guide.php'],
        };
    }

    /**
     * What the model is allowed to see about one entity. Demo rows: price withheld, flagged, with an explicit note. Availability is never
     * stated here for any row.
     */
    public static function publicMeta(array $d): array
    {
        $out = ['kind' => $d['kind'], 'id' => $d['id'], 'title' => $d['title'], 'citation' => self::citation($d)];
        if ($d['kind'] === 'guide') {
            return $out + ['place' => $d['title'], 'known_for' => $d['known_for'], 'best_time' => $d['best_time'], 'getting_there' => $d['getting_there'],
                'practical_note' => $d['practical_note'] !== '' ? $d['practical_note'] : null, 'researched_on' => $d['researched_at'],
                'note' => 'Curated guide, not a live feed: access rules, permits and schedules can change. Say the research date and advise confirming time-sensitive details.'];
        }
        $out += ['city' => $d['city'], 'country' => $d['country'], 'type' => $d['type'], 'rating' => $d['rating'] > 0 ? $d['rating'] : null,
                 'demo' => (bool)$d['demo'], 'availability' => 'not_confirmed'];
        if ($d['kind'] === 'hotel') $out['stars'] = $d['stars'] > 0 ? $d['stars'] : null;
        if ($d['kind'] === 'activity') $out['duration_hours'] = $d['hours'];
        $excerpt = mb_substr($d['text'], 0, 240, 'UTF-8');
        if ($excerpt !== '') $out['summary'] = $excerpt;
        if ($d['demo']) {
            $out['price_bdt'] = null;
            $out['note'] = 'DEMONSTRATION ENTRY, not a real supplier listing: do not quote a price, availability or booking terms for it; say it is sample data.';
        } else {
            $out[$d['unit'] === 'per_night' ? 'price_per_night_bdt' : 'price_per_person_bdt'] = $d['price'];
            $out['note'] = 'Price is the listed price. Availability is NOT confirmed here: tell the traveller to check the booking page for dates.';
        }
        return $out;
    }
}
