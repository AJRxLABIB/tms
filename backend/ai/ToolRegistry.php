<?php
/**
 * ToolRegistry — the ONLY things the assistant may do besides write text.
 *
 * All tools are READ-ONLY. There is no tool that books, pays, cancels, edits
 * or deletes. Each call is: validated against a declared schema, executed with
 * the identity taken from the server session (never from arguments or model
 * output), and written to an audit table.
 */
require_once __DIR__ . '/rag/KnowledgeIndex.php';
require_once __DIR__ . '/TripBudget.php';
require_once __DIR__ . '/BookingReadiness.php';
require_once __DIR__ . '/rag/EmbeddingReranker.php';
require_once __DIR__ . '/rag/EmbeddingStore.php';
require_once __DIR__ . '/rag/HybridSearch.php';
require_once __DIR__ . '/rag/EntityIndex.php';
require_once __DIR__ . '/rag/RetrievedText.php';

final class ToolRegistry
{
    private array $schemas;
    private KnowledgeIndex $index;
    private ?PDO $db;
    private ?EmbeddingReranker $reranker;
    private ?EntityIndex $entities;
    /** Packages dropped (no longer published) or re-priced by the live check during this registry's life: analytics and tests. */
    public int $liveDropped = 0, $liveRepriced = 0;

    public function __construct(KnowledgeIndex $index, ?PDO $db = null, ?EmbeddingReranker $reranker = null, ?EntityIndex $entities = null)
    {
        $this->index = $index; $this->db = $db; $this->reranker = $reranker; $this->entities = $entities;
        $this->schemas = [
            'search_packages' => [
                'auth' => false,
                'params' => [
                    'query'     => ['type' => 'string', 'required' => true, 'max' => 200],
                    'max_price' => ['type' => 'int', 'min' => 0, 'max' => 10000000],
                    'min_price' => ['type' => 'int', 'min' => 0, 'max' => 10000000],
                    'days_min'  => ['type' => 'int', 'min' => 1, 'max' => 60],
                    'days_max'  => ['type' => 'int', 'min' => 1, 'max' => 60],
                    'country'   => ['type' => 'string', 'max' => 60],
                    'limit'     => ['type' => 'int', 'min' => 1, 'max' => 8],
                ],
            ],
            'get_package' => ['auth' => false, 'params' => ['package_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647]]],
            'get_cancellation_policy' => ['auth' => false, 'params' => ['package_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647]]],
            // Pure arithmetic over catalogue price + figures the traveller supplied (see TripBudget). Reads one package; writes nothing.
            'calculate_trip_budget' => ['auth' => false, 'params' => [
                'travelers' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 50],
                'package_id' => ['type' => 'int', 'min' => 1, 'max' => 2147483647],
                'days' => ['type' => 'int', 'min' => 1, 'max' => 60],
                'budget_bdt' => ['type' => 'int', 'min' => 1, 'max' => 1000000000],
                'food_per_person_per_day_bdt' => ['type' => 'int', 'min' => 0, 'max' => 1000000],
                'local_transport_bdt' => ['type' => 'int', 'min' => 0, 'max' => 1000000000],
                'activities_bdt' => ['type' => 'int', 'min' => 0, 'max' => 1000000000],
                'other_bdt' => ['type' => 'int', 'min' => 0, 'max' => 1000000000],
                'contingency_pct' => ['type' => 'int', 'min' => 0, 'max' => 30],
            ]],
            // READ-ONLY pre-booking check using the booking form's own rules (see BookingReadiness). Creates nothing.
            'check_booking_readiness' => ['auth' => false, 'params' => [
                'package_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647],
                'travelers' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 50],
                'start_date' => ['type' => 'string', 'max' => 10],
                'end_date' => ['type' => 'string', 'max' => 10],
            ]],
            'my_bookings' => ['auth' => true, 'params' => []],
            // ONE of the signed-in visitor's own bookings. The identity comes from the server session, never from an argument, and a booking
            // that belongs to someone else is indistinguishable from one that does not exist. Minimal fields only.
            'get_my_booking' => ['auth' => true, 'params' => ['booking_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647]]],
            // Published hotels / activities / curated destination guides (read-only). Demonstration rows are labelled and never priced.
            'search_hotels' => ['auth' => false, 'params' => [
                'query' => ['type' => 'string', 'required' => true, 'max' => 200], 'city' => ['type' => 'string', 'max' => 100], 'country' => ['type' => 'string', 'max' => 60],
                'max_price' => ['type' => 'int', 'min' => 0, 'max' => 10000000], 'min_stars' => ['type' => 'int', 'min' => 1, 'max' => 5], 'limit' => ['type' => 'int', 'min' => 1, 'max' => 8]]],
            'search_activities' => ['auth' => false, 'params' => [
                'query' => ['type' => 'string', 'required' => true, 'max' => 200], 'city' => ['type' => 'string', 'max' => 100], 'country' => ['type' => 'string', 'max' => 60],
                'category' => ['type' => 'string', 'max' => 60], 'max_price' => ['type' => 'int', 'min' => 0, 'max' => 10000000],
                'max_hours' => ['type' => 'int', 'min' => 1, 'max' => 48], 'limit' => ['type' => 'int', 'min' => 1, 'max' => 8]]],
            'get_destination_guide' => ['auth' => false, 'params' => ['query' => ['type' => 'string', 'required' => true, 'max' => 200]]],
            // Package policy (if a package is named) plus the site's own published policy pages. Never invents terms.
            'get_cancellation_info' => ['auth' => false, 'params' => ['package_id' => ['type' => 'int', 'min' => 1, 'max' => 2147483647]]],
            // Side-by-side of 2-4 published packages, from catalogue fields only. Differences are computed in code.
            'compare_packages' => ['auth' => false, 'params' => ['package_ids' => ['type' => 'string', 'required' => true, 'max' => 40]]],
            // Staff-entered, sourced, dated notices only (see TRAVEL_NOTICES.md). Never a live feed.
            'get_travel_notices' => ['auth' => false, 'params' => [
                'query'   => ['type' => 'string', 'required' => true, 'max' => 200],
                'country' => ['type' => 'string', 'max' => 100],
            ]],
        ];
    }

    public function names(): array { return array_keys($this->schemas); }

    /** @throws InvalidArgumentException */
    public function validate(string $tool, array $args): array
    {
        if (!isset($this->schemas[$tool])) throw new InvalidArgumentException("unknown_tool");
        $spec = $this->schemas[$tool]['params']; $clean = [];
        foreach (array_keys($args) as $k) if (!isset($spec[$k])) throw new InvalidArgumentException("unexpected_arg:$k");
        foreach ($spec as $name => $p) {
            if (!array_key_exists($name, $args)) {
                if (!empty($p['required'])) throw new InvalidArgumentException("missing_arg:$name");
                continue;
            }
            $v = $args[$name];
            if ($p['type'] === 'int') {
                if (is_string($v) && preg_match('/^-?\d+$/', $v)) $v = (int)$v;
                if (!is_int($v)) throw new InvalidArgumentException("bad_type:$name");
                if (isset($p['min']) && $v < $p['min']) throw new InvalidArgumentException("out_of_range:$name");
                if (isset($p['max']) && $v > $p['max']) throw new InvalidArgumentException("out_of_range:$name");
            } else {
                if (!is_string($v)) throw new InvalidArgumentException("bad_type:$name");
                $v = trim($v);
                if (!empty($p['required']) && $v === '') throw new InvalidArgumentException("missing_arg:$name");
                if (mb_strlen($v) > ($p['max'] ?? 200)) throw new InvalidArgumentException("too_long:$name");
            }
            $clean[$name] = $v;
        }
        return $clean;
    }

    /**
     * @param array{email?:string,signed_in?:bool} $ctx identity from the SERVER session
     * @return array{ok:bool,data?:mixed,error?:string}
     */
    public function execute(string $tool, array $args, array $ctx): array
    {
        try {
            $a = $this->validate($tool, $args);
            if ($this->schemas[$tool]['auth'] && empty($ctx['signed_in'])) {
                $this->audit($tool, $a, $ctx, 'denied');
                return ['ok' => false, 'error' => 'sign_in_required'];
            }
            $data = match ($tool) {
                'search_packages' => $this->searchPackages($a),
                'get_package' => $this->pkg($a['package_id']) ?? ['not_found' => true],
                'get_cancellation_policy' => $this->policy($a['package_id']),
                'compare_packages' => $this->comparePackages($a),
                'my_bookings' => $this->myBookings((string)($ctx['email'] ?? '')),
                'get_my_booking' => $this->myBooking((string)($ctx['email'] ?? ''), $a['booking_id']),
                'search_hotels' => $this->searchEntities('hotel', $a),
                'search_activities' => $this->searchEntities('activity', $a),
                'get_destination_guide' => $this->destinationGuide($a),
                'get_cancellation_info' => $this->cancellationInfo($a),
                'get_travel_notices' => $this->travelNotices($a),
                'calculate_trip_budget' => $this->tripBudget($a),
                'check_booking_readiness' => $this->bookingReadiness($a),
            };
            $this->audit($tool, $a, $ctx, 'ok');
            return ['ok' => true, 'data' => $data];
        } catch (InvalidArgumentException $e) {
            $this->audit($tool, [], $ctx, 'invalid:' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** How the last search_packages call retrieved: 'hybrid', 'rerank' or 'bm25' (for tests and analytics). */
    public string $lastRetrieval = 'bm25';
    /** @var ?array<int,string> */ private ?array $packedCache = null;

    /** Hybrid (BM25 + stored embeddings) when persistent vectors cover at least 80% of the catalogue; null otherwise. */
    private function hybridHits(string $query, int $limit, array $filters): ?array
    {
        if (!$this->reranker || !$this->db || trim($query) === '') return null;
        $docs = []; foreach ($this->index->all() as $id => $m) $docs[$id] = EmbeddingStore::textFor($m);
        if (!$docs) return null;
        $key = $this->reranker->modelKey();
        if ($this->packedCache === null) $this->packedCache = EmbeddingStore::load($this->db, $docs, $key);
        if (count($this->packedCache) < 0.8 * count($docs)) return null;      // not (yet) indexed well enough: do not mix half a catalogue
        $v = $this->reranker->embed([$query]);
        $q = ($v && isset($v[0]) && is_array($v[0])) ? EmbeddingStore::normalize($v[0]) : null;
        if ($q === null) return null;
        $minSim = defined('AI_EMBED_MIN_SIM') ? (float)AI_EMBED_MIN_SIM : 0.5;
        return HybridSearch::search($this->index, $this->packedCache, $q, $query, $limit, $filters, $minSim);
    }

    private function searchPackages(array $a): array
    {
        $f = [];
        if (isset($a['max_price'])) $f['max_price'] = $a['max_price'];
        if (isset($a['min_price'])) $f['min_price'] = $a['min_price'];
        if (isset($a['days_min']) || isset($a['days_max'])) { $f['days_min'] = $a['days_min'] ?? $a['days_max']; $f['days_max'] = $a['days_max'] ?? $a['days_min']; if ($f['days_min'] > $f['days_max']) [$f['days_min'], $f['days_max']] = [$f['days_max'], $f['days_min']]; }
        if (!empty($a['country'])) $f['country'] = $a['country'];
        $limit = (int)($a['limit'] ?? 5);
        $this->lastSearchMeta = ['filters' => $f, 'relaxed' => [], 'count' => 0, 'browse' => false];
        $rows = $this->searchWith($a, $f, $limit);
        if (!$rows && $f) { $rows = $this->browse($f, $limit); if ($rows) $this->lastSearchMeta['browse'] = true; }
        // Trip length is a soft preference: if nothing is exactly that long, show the closest by the other criteria and SAY so
        // (the model is told through the facts). Budget and country stay hard limits - never show something over budget.
        if (!$rows && isset($f['days_min'])) {
            $g = $f; unset($g['days_min'], $g['days_max']);
            $rows = $this->searchWith($a, $g, $limit);
            if (!$rows && $g) { $rows = $this->browse($g, $limit); if ($rows) $this->lastSearchMeta['browse'] = true; }
            if ($rows) $this->lastSearchMeta['relaxed'] = ['days'];
        }
        $rows = $this->applyLive($rows);
        $this->lastSearchMeta['count'] = count($rows);
        return $rows;
    }

    /** What the last search_packages call applied / relaxed (for the facts block and analytics). @var array{filters:array,relaxed:array,count:int} */
    public array $lastSearchMeta = ['filters' => [], 'relaxed' => [], 'count' => 0, 'browse' => false];

    /** Filter-only listing for requests that are pure constraints ("under 20k for 3 days"): best rated first, then cheapest, then id. */
    private function browse(array $f, int $limit): array
    {
        $m = array_filter($this->index->all(), fn($meta) => KnowledgeIndex::passes($meta, $f));
        uasort($m, fn($x, $y) => [-($x['rating'] ?? 0), $x['price'], $x['id'] ?? 0] <=> [-($y['rating'] ?? 0), $y['price'], $y['id'] ?? 0]);
        $out = [];
        foreach (array_slice($m, 0, max(1, min(8, $limit ?: 5)), true) as $id => $meta) {
            $p = $this->index->get((int)$id); if (!$p) continue;
            $out[] = ['id' => (int)$id, 'title' => $p['title'], 'location' => $p['location'], 'country' => $p['country'], 'price_bdt' => $p['price'], 'duration' => $p['duration'], 'citation' => $p['citation']];
        }
        return $out;
    }

    private function searchWith(array $a, array $f, int $limit): array
    {
        $this->lastRetrieval = 'bm25';
        $hits = null;
        try { $hits = $this->hybridHits((string)$a['query'], $limit ?: 5, $f); } catch (Throwable $e) { error_log('[TourBuddy AI hybrid] ' . $e->getMessage()); }
        if ($hits !== null) { $this->lastRetrieval = 'hybrid'; }
        elseif ($this->reranker) {
            // Optional semantic re-rank of a wider BM25 candidate pool; falls back to BM25 order on any failure.
            $pool = $this->index->search($a['query'], max($limit, 15), $f);
            $rr = $this->reranker->rerank((string)$a['query'], $pool);
            $hits = array_slice($rr['hits'], 0, max(1, min(20, $limit)));
            if (!empty($rr['reranked'])) $this->lastRetrieval = 'rerank';
        } else {
            $hits = $this->index->search($a['query'], $limit ?: 5, $f);
        }
        return array_map(fn($h) => ['id' => $h['id'], 'title' => $h['meta']['title'], 'location' => $h['meta']['location'],
            'country' => $h['meta']['country'], 'price_bdt' => $h['meta']['price'], 'duration' => $h['meta']['duration'],
            'citation' => $h['citation']], $hits);
    }

    private function tripBudget(array $a): array
    {
        $in = ['travelers' => $a['travelers']];
        $notes = [];
        if (isset($a['package_id'])) {
            $p = $this->pkg($a['package_id']);
            if (!$p) return ['error' => 'package_not_found', 'note' => 'That package is not published, so no price can be used.'];
            $in['package_price_per_person'] = (int)$p['price'];
            $in['package_price_basis'] = 'list price';
            $in['package'] = $p['title'];
            if (!isset($a['days']) && preg_match('/\d+/', (string)$p['duration'], $m)) { $in['days'] = (int)$m[0]; $notes[] = 'days taken from the package duration "' . $p['duration'] . '"'; }
        }
        foreach (['days' => 'days', 'budget' => 'budget_bdt', 'food_per_person_per_day' => 'food_per_person_per_day_bdt', 'local_transport' => 'local_transport_bdt',
                  'activities' => 'activities_bdt', 'other' => 'other_bdt', 'contingency_pct' => 'contingency_pct'] as $k => $arg) {
            if (isset($a[$arg])) $in[$k] = $a[$arg];
        }
        $r = TripBudget::compute($in);
        if (!empty($in['package'])) { $r['package'] = $in['package']; $r['citation'] = $this->index->get($a['package_id'])['citation']; }
        if ($notes) $r['assumptions'] = $notes;
        $r['instruction'] = 'Quote these numbers exactly; do not recompute or round differently. Say which lines are catalogue prices, which are the traveller\'s own estimates and which are assumptions, and list not_included as costs that are NOT counted.';
        return $r;
    }

    private function bookingReadiness(array $a): array
    {
        $p = $this->pkg($a['package_id']);
        if (!$p || !$this->db) return ['error' => 'package_not_found', 'note' => 'That package is not published, so it cannot be booked.'];
        return BookingReadiness::check($this->db, $p, $a['travelers'], $a['start_date'] ?? null, $a['end_date'] ?? null);
    }

    private function travelNotices(array $a): array
    {
        $none = ['notices' => [], 'status' => 'no_verified_notice',
            'note' => 'TourBuddy has no staff-verified notice for this. You MUST say you cannot confirm current entry rules, disruptions or advisories, and tell the traveler to check the official government or embassy source. Do not guess.'];
        if (!$this->db) return $none;
        require_once dirname(__DIR__) . '/services/TravelNoticeService.php';
        $country = trim((string)($a['country'] ?? ''));
        if ($country === '') {
            $q = mb_strtolower((string)$a['query'], 'UTF-8');
            foreach (TravelNoticeService::countries($this->db) as $c) {
                if ($c !== '' && mb_strpos($q, mb_strtolower((string)$c, 'UTF-8')) !== false) { $country = (string)$c; break; }
            }
        }
        if ($country === '') return $none;
        $rows = TravelNoticeService::visible($this->db, $country);
        if (!$rows) return $none;
        return ['status' => 'notices_found',
            'note' => 'Staff-entered, NOT live. Quote the checked-on date and source for every statement, and mention any "may_be_out_of_date" flag. Tell the traveler to confirm with the official source.',
            'notices' => array_map(fn($n) => ['type' => $n['Kind'], 'country' => $n['Country'], 'title' => $n['Title'], 'details' => $n['Body'],
                'source' => $n['SourceName'], 'source_url' => $n['SourceUrl'], 'checked_on' => $n['VerifiedOn'], 'shown_until' => $n['ExpiresOn'],
                'may_be_out_of_date' => (bool)$n['stale']], array_slice($rows, 0, 5))];
    }

    private function policy(int $id): array
    {
        $p = $this->pkg($id);
        if (!$p) return ['not_found' => true];
        $txt = trim($p['cancellation']);
        return ['package' => $p['title'], 'policy' => $txt !== '' ? $txt : null,
                'note' => $txt === '' ? 'No package-specific cancellation policy is recorded.' : null, 'citation' => $p['citation']];
    }

    /** Published package meta by id (null when not published); used by callers that need a price without a tool call. */
    public function package(int $id): ?array { return $this->pkg($id); }

    /** @var array<int,?array> per-registry cache of live checks */
    private array $liveCache = [];

    /**
     * Live facts for package ids (null when no database is attached). Every package a tool is about to quote passes through this, so a
     * package that was unpublished or re-priced after the index was built is dropped or shown at its CURRENT price (audit item 19).
     * @param int[] $ids @return ?array<int,array{price:int,duration:string,title:string}>
     */
    private function live(array $ids): ?array
    {
        if (!$this->db || !$this->index->isFromDb()) return null;   // only an index built from the live table can be stale
        $need = array_values(array_filter($ids, fn($i) => !array_key_exists($i, $this->liveCache)));
        if ($need) {
            try { $got = KnowledgeIndex::liveCheck($this->db, $need); }
            catch (Throwable $e) { error_log('[TourBuddy AI live check] ' . $e->getMessage()); return null; }   // cannot verify: keep the indexed value rather than hide the catalogue
            foreach ($need as $i) $this->liveCache[$i] = $got[$i] ?? null;
        }
        $out = []; foreach ($ids as $i) if (($this->liveCache[$i] ?? null) !== null) $out[$i] = $this->liveCache[$i];
        return $out;
    }

    /** Package meta with the live price/duration applied; null when the package is not published right now. */
    private function pkg(int $id): ?array
    {
        $m = $this->index->get($id);
        if (!$m) return null;
        $live = $this->live([$id]);
        if ($live === null) return $m;
        if (!isset($live[$id])) { $this->liveDropped++; return null; }
        if ($live[$id]['price'] !== (int)$m['price'] || $live[$id]['duration'] !== (string)$m['duration']) $this->liveRepriced++;
        $m['price'] = $live[$id]['price']; $m['duration'] = $live[$id]['duration'];
        return $m;
    }

    /** Apply the live check to result rows that carry id/price_bdt/duration. */
    private function applyLive(array $rows): array
    {
        $live = $this->live(array_map(fn($r) => (int)$r['id'], $rows));
        if ($live === null) return $rows;
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (!isset($live[$id])) { $this->liveDropped++; continue; }
            if (isset($r['price_bdt']) && (int)$r['price_bdt'] !== $live[$id]['price']) $this->liveRepriced++;
            $r['price_bdt'] = $live[$id]['price']; $r['duration'] = $live[$id]['duration'];
            $out[] = $r;
        }
        return $out;
    }

    private function comparePackages(array $a): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string)$a['package_ids'], -1, PREG_SPLIT_NO_EMPTY) as $x) {
            if (!ctype_digit($x)) throw new InvalidArgumentException('bad_type:package_ids');
            $x = (int)$x; if ($x > 0 && !in_array($x, $ids, true)) $ids[] = $x;
        }
        if (count($ids) < 2 || count($ids) > 4) throw new InvalidArgumentException('out_of_range:package_ids');
        $items = []; $missing = [];
        foreach ($ids as $id) {
            $p = $this->pkg($id);
            if (!$p) { $missing[] = $id; continue; }
            $pol = trim((string)$p['cancellation']);
            $items[] = ['id' => $id, 'title' => $p['title'], 'location' => $p['location'], 'country' => $p['country'], 'price_bdt' => (int)$p['price'],
                'duration' => $p['duration'], 'rating' => $p['rating'] ?? null, 'cancellation' => $pol !== '' ? mb_substr($pol, 0, 160) : 'none recorded', 'citation' => $p['citation']];
        }
        $out = ['packages' => $items];
        if ($missing) $out['not_published'] = $missing;
        if (count($items) >= 2) {
            $by = $items; usort($by, fn($x, $y) => $x['price_bdt'] <=> $y['price_bdt']);
            if ($by[0]['price_bdt'] < $by[1]['price_bdt']) { $out['cheapest_id'] = $by[0]['id']; $out['cheapest_saves_bdt_vs_next'] = $by[1]['price_bdt'] - $by[0]['price_bdt']; }
            else $out['cheapest_id'] = null;
            $out['note'] = 'Prices are per person and come from the catalogue. Differences listed here are computed by code; do not add facts that are not in this list.';
        }
        return $out;
    }

    private function myBookings(string $email): array
    {
        if ($email === '' || !$this->db) return [];
        $st = $this->db->prepare(
            "SELECT b.BookingId, b.FromDate, b.ToDate, b.status, b.PaymentStatus, p.PackageName
               FROM tblbooking b LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
              WHERE b.UserEmail = :e ORDER BY b.BookingId DESC LIMIT 5");
        $st->execute([':e' => $email]);
        $label = [0 => 'awaiting confirmation', 1 => 'confirmed', 2 => 'cancelled'];
        return array_map(fn($r) => ['booking_id' => (int)$r['BookingId'], 'package' => $r['PackageName'],
            'from' => $r['FromDate'], 'to' => $r['ToDate'], 'status' => $label[(int)$r['status']] ?? 'unknown',
            'payment' => $r['PaymentStatus']], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function entities(): EntityIndex
    {
        if ($this->entities === null) {
            try { $this->entities = $this->db ? EntityIndex::fromDb($this->db) : EntityIndex::fromRows([]); }
            catch (Throwable $e) { error_log('[TourBuddy AI entities] ' . $e->getMessage()); $this->entities = EntityIndex::fromRows([]); }
        }
        return $this->entities;
    }

    /** Hotels / activities: lexical retrieval + structured filters, demo rows labelled and never priced, source label from InventorySource. */
    private function searchEntities(string $kind, array $a): array
    {
        $f = [];
        foreach (['country', 'city', 'category'] as $k) if (!empty($a[$k])) $f[$k] = $a[$k];
        foreach (['max_price', 'min_stars', 'max_hours'] as $k) if (isset($a[$k]) && $a[$k] > 0) $f[$k] = $a[$k];
        $limit = (int)($a['limit'] ?? 5);
        $ix = $this->entities();
        $hits = $ix->search($kind, (string)$a['query'], $limit, $f);
        $browse = false;
        if (!$hits && $f) { $hits = $ix->browse($kind, $limit, $f); $browse = (bool)$hits; }
        $label = null;
        if ($this->db) { try { require_once dirname(__DIR__) . '/services/InventorySource.php'; $label = true; } catch (Throwable $e) { $label = null; } }
        $rows = [];
        foreach ($hits as $h) {
            $m = EntityIndex::publicMeta($h['d']);
            if ($label && class_exists('InventorySource', false)) {
                try { $l = InventorySource::labelFor($this->db, $kind, (int)$h['id']); $m['data_source'] = ['label' => (string)($l['label'] ?? ''), 'live' => (bool)($l['live'] ?? false), 'stale' => (bool)($l['stale'] ?? false)]; } catch (Throwable $e) { /* label is optional */ }
            }
            $rows[] = $m;
        }
        $out = ['results' => $rows, 'filters' => $f, 'count' => count($rows)];
        if ($browse) $out['note'] = 'No name or theme matched, so these are the best-rated entries that satisfy the filters.';
        if (!$rows) $out['note'] = 'No published ' . $kind . ' matches. Say none were found; do not invent one, a price or availability.';
        elseif (array_filter($rows, fn($r) => !empty($r['demo']))) $out['demo_warning'] = 'Entries marked demo are demonstration data: never present them as bookable, priced or available.';
        return $out;
    }

    private function destinationGuide(array $a): array
    {
        $hits = $this->entities()->search('guide', (string)$a['query'], 2);
        if (!$hits) return ['guides' => [], 'note' => 'No curated guide matches. Say you do not have a guide for this place; do not improvise travel logistics, permits or access rules.'];
        return ['guides' => array_map(fn($h) => EntityIndex::publicMeta($h['d']), $hits)];
    }

    /** Package policy (when named) plus the site's own published policy pages (terms / cancellation / refund). Untrusted text is cleaned. */
    private function cancellationInfo(array $a): array
    {
        $out = ['package_policy' => null, 'site_policy' => [], 'rules' => 'Quote only what is written here. If a refund amount or deadline is not stated, say it is not stated and send the traveller to the booking page or support. Never promise a refund.'];
        if (isset($a['package_id'])) $out['package_policy'] = $this->policy((int)$a['package_id']);
        if ($this->db) {
            foreach (['cancellation', 'refund', 'terms'] as $type) {
                try {
                    $st = $this->db->prepare('SELECT detail FROM tblpages WHERE type = ? LIMIT 1');
                    $st->execute([$type]);
                    $d = $st->fetchColumn();
                    $txt = $d === false ? '' : RetrievedText::clean((string)$d, 1500);
                    if ($txt !== '') $out['site_policy'][] = ['page' => $type, 'text' => $txt, 'citation' => ['id' => 'page:' . $type, 'title' => ucfirst($type) . ' page', 'url' => 'page.php?type=' . $type]];
                } catch (Throwable $e) { /* table or row missing: nothing to quote */ }
            }
        }
        if (!$out['site_policy'] && $out['package_policy'] === null) $out['note'] = 'No cancellation or refund policy text is recorded. Say so and refer the traveller to support.';
        return $out;
    }

    /** One of the caller's OWN bookings. The email is the session identity; a booking owned by anyone else yields the same answer as a missing one. */
    private function myBooking(string $email, int $bookingId): array
    {
        if ($email === '' || !$this->db) return ['not_found' => true];
        try {
            $st = $this->db->prepare(
                "SELECT b.BookingId, b.FromDate, b.ToDate, b.status, b.PaymentStatus, b.NoOfPersons, b.TotalCost, b.RefundStatus, p.PackageName
                   FROM tblbooking b LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
                  WHERE b.BookingId = :id AND b.UserEmail = :e LIMIT 1");
            $st->execute([':id' => $bookingId, ':e' => $email]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('[TourBuddy AI get_my_booking] ' . $e->getMessage()); return ['not_found' => true]; }
        if (!$r) return ['not_found' => true];
        $label = [0 => 'awaiting confirmation', 1 => 'confirmed', 2 => 'cancelled'];
        return ['booking_id' => (int)$r['BookingId'], 'package' => $r['PackageName'], 'from' => $r['FromDate'], 'to' => $r['ToDate'],
            'travelers' => (int)$r['NoOfPersons'], 'status' => $label[(int)$r['status']] ?? 'unknown', 'payment' => $r['PaymentStatus'],
            'total_bdt' => $r['TotalCost'] !== null ? (int)$r['TotalCost'] : null, 'refund_status' => $r['RefundStatus'] ?: null,
            'note' => 'Changes or cancellation are not done by the assistant: point to the My Bookings page, where the traveller confirms them.'];
    }

    private function audit(string $tool, array $args, array $ctx, string $result): void
    {
        if (!$this->db) return;
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS tbl_ai_tool_audit (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, tool VARCHAR(40) NOT NULL, args_json VARCHAR(500),
                actor VARCHAR(64) NOT NULL, result VARCHAR(80) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Actor is hashed: the audit trail needs "same user" not the address.
            $actor = !empty($ctx['signed_in']) ? 'u:' . substr(hash('sha256', (string)($ctx['email'] ?? '')), 0, 16) : 'guest';
            $st = $this->db->prepare("INSERT INTO tbl_ai_tool_audit (tool,args_json,actor,result) VALUES (?,?,?,?)");
            $st->execute([$tool, mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 500), $actor, mb_substr($result, 0, 80)]);
        } catch (Throwable $e) { /* auditing must never break answering */ }
    }
}
