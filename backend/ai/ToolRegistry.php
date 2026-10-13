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
require_once __DIR__ . '/rag/EmbeddingReranker.php';

final class ToolRegistry
{
    private array $schemas;
    private KnowledgeIndex $index;
    private ?PDO $db;
    private ?EmbeddingReranker $reranker;

    public function __construct(KnowledgeIndex $index, ?PDO $db = null, ?EmbeddingReranker $reranker = null)
    {
        $this->index = $index; $this->db = $db; $this->reranker = $reranker;
        $this->schemas = [
            'search_packages' => [
                'auth' => false,
                'params' => [
                    'query'     => ['type' => 'string', 'required' => true, 'max' => 200],
                    'max_price' => ['type' => 'int', 'min' => 0, 'max' => 10000000],
                    'country'   => ['type' => 'string', 'max' => 60],
                    'limit'     => ['type' => 'int', 'min' => 1, 'max' => 8],
                ],
            ],
            'get_package' => ['auth' => false, 'params' => ['package_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647]]],
            'get_cancellation_policy' => ['auth' => false, 'params' => ['package_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'max' => 2147483647]]],
            'my_bookings' => ['auth' => true, 'params' => []],
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
                'get_package' => $this->index->get($a['package_id']) ?? ['not_found' => true],
                'get_cancellation_policy' => $this->policy($a['package_id']),
                'my_bookings' => $this->myBookings((string)($ctx['email'] ?? '')),
                'get_travel_notices' => $this->travelNotices($a),
            };
            $this->audit($tool, $a, $ctx, 'ok');
            return ['ok' => true, 'data' => $data];
        } catch (InvalidArgumentException $e) {
            $this->audit($tool, [], $ctx, 'invalid:' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function searchPackages(array $a): array
    {
        $f = [];
        if (isset($a['max_price'])) $f['max_price'] = $a['max_price'];
        if (!empty($a['country'])) $f['country'] = $a['country'];
        $limit = (int)($a['limit'] ?? 5);
        if ($this->reranker) {
            // Optional semantic re-rank of a wider BM25 candidate pool; falls back to BM25 order on any failure.
            $pool = $this->index->search($a['query'], max($limit, 15), $f);
            $hits = array_slice($this->reranker->rerank((string)$a['query'], $pool)['hits'], 0, max(1, min(20, $limit)));
        } else {
            $hits = $this->index->search($a['query'], $limit ?: 5, $f);
        }
        return array_map(fn($h) => ['id' => $h['id'], 'title' => $h['meta']['title'], 'location' => $h['meta']['location'],
            'country' => $h['meta']['country'], 'price_bdt' => $h['meta']['price'], 'duration' => $h['meta']['duration'],
            'citation' => $h['citation']], $hits);
    }

    private function travelNotices(array $a): array
    {
        $none = ['notices' => [], 'status' => 'no_verified_notice',
            'note' => 'TMS has no staff-verified notice for this. You MUST say you cannot confirm current entry rules, disruptions or advisories, and tell the traveler to check the official government or embassy source. Do not guess.'];
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
        $p = $this->index->get($id);
        if (!$p) return ['not_found' => true];
        $txt = trim($p['cancellation']);
        return ['package' => $p['title'], 'policy' => $txt !== '' ? $txt : null,
                'note' => $txt === '' ? 'No package-specific cancellation policy is recorded.' : null, 'citation' => $p['citation']];
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
