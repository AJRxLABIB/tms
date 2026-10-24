<?php
/**
 * SupplierQuality — a transparent, explainable quality view of a supplier.
 *
 * Design rules (deliberate):
 *  - Every criterion returns its value, weight, sample size and a plain-language explanation. Nothing is hidden.
 *  - Small samples give "insufficient data" for that criterion, and too little overall data gives no score at all.
 *  - The score NEVER suspends, hides or penalises anyone. It only raises a "needs human review" flag with reasons.
 *    Status changes stay with an administrator (SupplierService::setSupplierStatus), who records a decision here.
 *  - Suppliers can see their own breakdown and file a dispute; admins resolve disputes with a written resolution.
 *  - Pure scoring (score()) is separated from SQL (metrics()) so it can be tested without a database.
 * Tables: database/migrations/018_supplier_quality.sql. Documented criteria and weights are in CRITERIA below.
 */
require_once __DIR__ . '/SupplierService.php';
require_once __DIR__ . '/InventorySource.php';

class SupplierQuality
{
    /** key => [weight, label, minimum sample, what the minimum sample counts] */
    public const CRITERIA = [
        'verification' => [15, 'Business verification', 1,  'verification status'],
        'volume'       => [15, 'Completed bookings',    1,  'bookings'],
        'reviews'      => [20, 'Verified customer reviews', 5, 'verified reviews'],
        'cancellation' => [15, 'Cancellations by supplier', 10, 'bookings'],
        'complaints'   => [10, 'Customer complaints',   10, 'bookings'],
        'response'     => [10, 'Responsiveness',        5,  'answered bookings'],
        'freshness'    => [15, 'Data freshness',        1,  'inventory records'],
    ];
    /** No score unless this much non-verification weight is measurable AND the supplier is verified with this many completed bookings. */
    public const MIN_MEASURED_WEIGHT = 40;
    public const MIN_COMPLETED_FOR_SCORE = 5;
    public const REVIEW_BELOW_SCORE = 50.0;
    public const REVIEW_CANCEL_RATE = 0.20;
    public const REVIEW_COMPLAINT_RATE = 0.15;
    public const WINDOW_DAYS = 365;

    public const DISPUTE_TRANSITIONS = ['open' => ['under_review', 'resolved'], 'under_review' => ['resolved'], 'resolved' => []];
    public const REVIEW_DECISIONS = ['no_action', 'monitor', 'request_info', 'warn', 'escalate_to_status_change'];

    // ───────────────────────── pure scoring ─────────────────────────

    /**
     * @param array $m metrics: status, completed, total_bookings, reviews_n, reviews_avg, declined, complaints,
     *                 response_n, response_avg_hours, freshness (0..1|null), open_disputes
     * @return array{score:?float,status:string,needs_review:bool,review_reasons:string[],criteria:array,measured_weight:int,note:string}
     */
    public static function score(array $m): array
    {
        $total = (int)($m['total_bookings'] ?? 0);
        $completed = (int)($m['completed'] ?? 0);
        $c = [];

        $verified = ($m['status'] ?? '') === SupplierService::STATUS_VERIFIED;
        $c['verification'] = self::crit('verification', true, $verified ? 1.0 : 0.0, 1,
            $verified ? 'Business identity verified by an administrator.' : 'Not verified (status: ' . (string)($m['status'] ?? 'unknown') . ').');

        $c['volume'] = self::crit('volume', true, min(1.0, $completed / 25), $completed,
            $completed . ' completed booking(s) in the last ' . self::WINDOW_DAYS . ' days; 25 or more earns full marks.');

        $rn = (int)($m['reviews_n'] ?? 0);
        if ($rn >= self::CRITERIA['reviews'][2]) {
            $avg = (float)$m['reviews_avg'];
            $c['reviews'] = self::crit('reviews', true, max(0.0, min(1.0, ($avg - 1) / 4)), $rn, 'Average ' . number_format($avg, 2) . '/5 from ' . $rn . ' reviews written by customers with a confirmed booking.');
        } else {
            $c['reviews'] = self::crit('reviews', false, null, $rn, 'Only ' . $rn . ' verified review(s); at least ' . self::CRITERIA['reviews'][2] . ' are needed.');
        }

        $declined = (int)($m['declined'] ?? 0);
        $cancelRate = $total > 0 ? $declined / $total : null;
        if ($total >= self::CRITERIA['cancellation'][2]) {
            $c['cancellation'] = self::crit('cancellation', true, 1.0 - min(1.0, $cancelRate / 0.25), $total, $declined . ' of ' . $total . ' bookings declined by the supplier (' . round($cancelRate * 100, 1) . '%); 25% or more scores zero.');
        } else {
            $c['cancellation'] = self::crit('cancellation', false, null, $total, 'Only ' . $total . ' booking(s); at least ' . self::CRITERIA['cancellation'][2] . ' are needed to judge a cancellation rate.');
        }

        $complaints = (int)($m['complaints'] ?? 0);
        $complaintRate = $total > 0 ? $complaints / $total : null;
        if ($total >= self::CRITERIA['complaints'][2]) {
            $c['complaints'] = self::crit('complaints', true, 1.0 - min(1.0, $complaintRate / 0.20), $total, $complaints . ' complaint(s) on ' . $total . ' bookings (' . round($complaintRate * 100, 1) . '%); 20% or more scores zero. A complaint is a customer report, not proof of fault.');
        } else {
            $c['complaints'] = self::crit('complaints', false, null, $total, 'Only ' . $total . ' booking(s); at least ' . self::CRITERIA['complaints'][2] . ' are needed.');
        }

        $respN = (int)($m['response_n'] ?? 0);
        if ($respN >= self::CRITERIA['response'][2] && isset($m['response_avg_hours'])) {
            $h = (float)$m['response_avg_hours'];
            $c['response'] = self::crit('response', true, max(0.0, min(1.0, (48 - $h) / 44)), $respN, 'Average time to answer a booking: ' . number_format($h, 1) . ' h (4 h or less earns full marks, 48 h or more scores zero).');
        } else {
            $c['response'] = self::crit('response', false, null, $respN, 'Only ' . $respN . ' answered booking(s); at least ' . self::CRITERIA['response'][2] . ' are needed.');
        }

        if (isset($m['freshness']) && $m['freshness'] !== null) {
            $c['freshness'] = self::crit('freshness', true, max(0.0, min(1.0, (float)$m['freshness'])), 1, round((float)$m['freshness'] * 100) . '% of live package inventory records were refreshed recently.');
        } else {
            $c['freshness'] = self::crit('freshness', false, null, 0, 'No inventory-source records to judge freshness.');
        }

        $weightAll = 0; $weightedSum = 0.0; $measuredNonVerif = 0;
        foreach ($c as $k => $row) {
            if (!$row['measured']) continue;
            $weightAll += $row['weight']; $weightedSum += $row['value'] * $row['weight'];
            if ($k !== 'verification') $measuredNonVerif += $row['weight'];
        }

        $reasons = [];
        if ($cancelRate !== null && $total >= self::CRITERIA['cancellation'][2] && $cancelRate >= self::REVIEW_CANCEL_RATE) $reasons[] = 'Elevated cancellation rate (' . round($cancelRate * 100, 1) . '%).';
        if ($complaintRate !== null && $total >= self::CRITERIA['complaints'][2] && $complaintRate >= self::REVIEW_COMPLAINT_RATE) $reasons[] = 'Elevated complaint rate (' . round($complaintRate * 100, 1) . '%).';
        if (($m['freshness'] ?? null) !== null && (float)$m['freshness'] < 0.5) $reasons[] = 'More than half of live inventory records are stale.';
        if ((int)($m['open_disputes'] ?? 0) > 0) $reasons[] = (int)$m['open_disputes'] . ' unresolved dispute(s).';

        $status = 'scored'; $score = null; $note = '';
        if (!$verified) {
            $status = 'insufficient_data'; $note = 'No score: the supplier is not verified.';
        } elseif ($completed < self::MIN_COMPLETED_FOR_SCORE || $measuredNonVerif < self::MIN_MEASURED_WEIGHT) {
            $status = 'insufficient_data';
            $note = 'No score yet: not enough history (needs ' . self::MIN_COMPLETED_FOR_SCORE . '+ completed bookings and criteria worth ' . self::MIN_MEASURED_WEIGHT . '+ weight measured; measured ' . $measuredNonVerif . ').';
        } else {
            $score = round($weightedSum / $weightAll * 100, 1);
            $note = 'Score computed from ' . count(array_filter($c, fn($r) => $r['measured'])) . ' of ' . count($c) . ' criteria (unmeasured criteria are left out, not counted as zero).';
            if ($score < self::REVIEW_BELOW_SCORE) $reasons[] = 'Score ' . $score . ' is below ' . self::REVIEW_BELOW_SCORE . '.';
        }
        if ($status === 'scored' && !$reasons) $note .= ' No review flags.';
        $note .= ' This score never changes a supplier\'s status automatically; only an administrator can.';

        return ['score' => $score, 'status' => $status, 'needs_review' => (bool)$reasons, 'review_reasons' => $reasons,
                'criteria' => $c, 'measured_weight' => $weightAll, 'note' => $note];
    }

    private static function crit(string $key, bool $measured, ?float $value, int $n, string $explain): array
    {
        return ['key' => $key, 'label' => self::CRITERIA[$key][1], 'weight' => self::CRITERIA[$key][0], 'measured' => $measured,
                'value' => $value === null ? null : round($value, 4), 'sample' => $n, 'explanation' => $explain];
    }

    // ───────────────────────── metrics from the database ─────────────────────────

    public static function metrics(PDO $dbh, int $sid, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $since = date('Y-m-d', strtotime($today . ' -' . self::WINDOW_DAYS . ' days'));
        $s = SupplierService::find($dbh, $sid);
        $base = "FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId AND p.SupplierId = :sid WHERE b.RegDate >= :since";

        $q = $dbh->prepare("SELECT COUNT(*) AS total,
                SUM(b.status = 1 AND b.ToDate REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}' AND b.ToDate < :today) AS completed $base");
        $q->execute([':sid' => $sid, ':since' => $since, ':today' => $today]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $rv = $dbh->prepare("SELECT COUNT(*) AS n, AVG(r.Rating) AS a FROM tblreviews r
               JOIN tbltourpackages p ON p.PackageId = r.PackageId AND p.SupplierId = :sid
              WHERE r.Status = 1 AND r.Rating BETWEEN 1 AND 5
                AND EXISTS (SELECT 1 FROM tblbooking b WHERE b.PackageId = r.PackageId AND b.UserEmail = r.UserEmail AND b.status = 1)");
        $rv->execute([':sid' => $sid]);
        $rvr = $rv->fetch(PDO::FETCH_ASSOC) ?: [];

        $dc = $dbh->prepare("SELECT COUNT(*) FROM tbl_supplier_booking_responses r JOIN tblbooking b ON b.BookingId = r.BookingId
              WHERE r.SupplierId = :sid AND r.Decision = 'declined' AND b.RegDate >= :since");
        $dc->execute([':sid' => $sid, ':since' => $since]);

        $cp = $dbh->prepare("SELECT COUNT(*) FROM tblissues i JOIN tblbooking b ON b.BookingId = i.BookingId
              JOIN tbltourpackages p ON p.PackageId = b.PackageId AND p.SupplierId = :sid WHERE i.PostingDate >= :since");
        $cp->execute([':sid' => $sid, ':since' => $since]);

        $rs = $dbh->prepare("SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(MINUTE, b.RegDate, r.DecidedAt)) / 60 AS h
              FROM tbl_supplier_booking_responses r JOIN tblbooking b ON b.BookingId = r.BookingId
              JOIN tbltourpackages p ON p.PackageId = b.PackageId AND p.SupplierId = :sid
              WHERE b.RegDate >= :since AND r.DecidedAt >= b.RegDate");
        $rs->execute([':sid' => $sid, ':since' => $since]);
        $rsr = $rs->fetch(PDO::FETCH_ASSOC) ?: [];

        $od = $dbh->prepare("SELECT COUNT(*) FROM tbl_supplier_disputes WHERE SupplierId = ? AND Status <> 'resolved'");
        $od->execute([$sid]);

        $fresh = null;
        try { $fresh = InventorySource::freshnessForSupplier($dbh, $sid); } catch (Throwable $e) { /* table not migrated yet */ }

        return [
            'status' => $s['Status'] ?? 'unknown',
            'total_bookings' => (int)($r['total'] ?? 0), 'completed' => (int)($r['completed'] ?? 0),
            'reviews_n' => (int)($rvr['n'] ?? 0), 'reviews_avg' => (float)($rvr['a'] ?? 0),
            'declined' => (int)$dc->fetchColumn(), 'complaints' => (int)$cp->fetchColumn(),
            'response_n' => (int)($rsr['n'] ?? 0), 'response_avg_hours' => isset($rsr['h']) ? (float)$rsr['h'] : null,
            'freshness' => $fresh, 'open_disputes' => (int)$od->fetchColumn(),
        ];
    }

    /** Compute and store a snapshot; returns the scoring result plus snapshot id. */
    public static function snapshot(PDO $dbh, int $sid): array
    {
        $m = self::metrics($dbh, $sid);
        $res = self::score($m);
        $dbh->prepare('INSERT INTO tbl_supplier_quality_snapshots (SupplierId, Score, Status, NeedsReview, MetricsJson) VALUES (?,?,?,?,?)')
            ->execute([$sid, $res['score'], $res['status'], $res['needs_review'] ? 1 : 0, json_encode(['metrics' => $m, 'result' => $res], JSON_UNESCAPED_UNICODE)]);
        $res['snapshot_id'] = (int)$dbh->lastInsertId();
        $res['metrics'] = $m;
        return $res;
    }

    // ───────────────────────── human review ─────────────────────────

    /** @return array{ok:bool,error:?string} A reviewer's written decision. Does not change supplier status by itself. */
    public static function recordReview(PDO $dbh, int $sid, string $reviewer, string $decision, string $note, ?int $snapshotId = null): array
    {
        $note = trim($note);
        if (!in_array($decision, self::REVIEW_DECISIONS, true)) return ['ok' => false, 'error' => 'Unknown decision.'];
        if (mb_strlen($note) < 5) return ['ok' => false, 'error' => 'A written reason of at least 5 characters is required.'];
        if (!SupplierService::find($dbh, $sid)) return ['ok' => false, 'error' => 'Supplier not found.'];
        $dbh->prepare('INSERT INTO tbl_supplier_reviews_log (SupplierId, Reviewer, Decision, Note, SnapshotId) VALUES (?,?,?,?,?)')
            ->execute([$sid, mb_substr($reviewer, 0, 80), $decision, mb_substr($note, 0, 500), $snapshotId]);
        SupplierService::audit($dbh, $sid, $reviewer, 'quality_review_' . $decision, $note);
        return ['ok' => true, 'error' => null];
    }

    public static function reviewHistory(PDO $dbh, int $sid, int $limit = 20): array
    {
        $st = $dbh->prepare('SELECT * FROM tbl_supplier_reviews_log WHERE SupplierId = ? ORDER BY ReviewLogId DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute([$sid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function latestSnapshot(PDO $dbh, int $sid): ?array
    {
        $st = $dbh->prepare('SELECT * FROM tbl_supplier_quality_snapshots WHERE SupplierId = ? ORDER BY SnapshotId DESC LIMIT 1');
        $st->execute([$sid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // ───────────────────────── disputes ─────────────────────────

    public static function canTransitionDispute(string $from, string $to): bool
    {
        return in_array($to, self::DISPUTE_TRANSITIONS[$from] ?? [], true);
    }

    /** @return array{ok:bool,error:?string,id:?int} Supplier files a dispute about its own score, a review, a complaint, etc. */
    public static function fileDispute(PDO $dbh, int $sid, string $subject, string $body): array
    {
        $subject = trim($subject); $body = trim($body);
        if (mb_strlen($subject) < 4 || mb_strlen($subject) > 160) return ['ok' => false, 'error' => 'Subject must be 4–160 characters.', 'id' => null];
        if (mb_strlen($body) < 20 || mb_strlen($body) > 4000) return ['ok' => false, 'error' => 'Please describe the issue in 20–4000 characters.', 'id' => null];
        $open = $dbh->prepare("SELECT COUNT(*) FROM tbl_supplier_disputes WHERE SupplierId = ? AND Status <> 'resolved'");
        $open->execute([$sid]);
        if ((int)$open->fetchColumn() >= 3) return ['ok' => false, 'error' => 'You already have 3 open disputes. Wait for a decision before filing another.', 'id' => null];
        $dbh->prepare('INSERT INTO tbl_supplier_disputes (SupplierId, Subject, Body) VALUES (?,?,?)')->execute([$sid, $subject, $body]);
        $id = (int)$dbh->lastInsertId();
        SupplierService::audit($dbh, $sid, 'supplier:' . $sid, 'dispute_filed', '#' . $id . ' ' . $subject);
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /** @return array{ok:bool,error:?string} */
    public static function setDisputeStatus(PDO $dbh, int $disputeId, string $to, string $actor, string $resolution = ''): array
    {
        $resolution = trim($resolution);
        if ($to === 'resolved' && mb_strlen($resolution) < 5) return ['ok' => false, 'error' => 'A written resolution is required to close a dispute.'];
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT SupplierId, Status FROM tbl_supplier_disputes WHERE DisputeId = ? FOR UPDATE');
            $st->execute([$disputeId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $dbh->rollBack(); return ['ok' => false, 'error' => 'Dispute not found.']; }
            if (!self::canTransitionDispute((string)$row['Status'], $to)) { $dbh->rollBack(); return ['ok' => false, 'error' => 'A ' . $row['Status'] . ' dispute cannot move to ' . $to . '.']; }
            $dbh->prepare('UPDATE tbl_supplier_disputes SET Status = ?, Resolution = IF(? = \'resolved\', ?, Resolution), ResolvedBy = IF(? = \'resolved\', ?, ResolvedBy) WHERE DisputeId = ?')
                ->execute([$to, $to, mb_substr($resolution, 0, 2000), $to, mb_substr($actor, 0, 80), $disputeId]);
            SupplierService::audit($dbh, (int)$row['SupplierId'], $actor, 'dispute_' . $to, '#' . $disputeId . ' ' . $resolution);
            $dbh->commit();
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy SupplierQuality] dispute update failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the dispute.'];
        }
    }

    public static function disputes(PDO $dbh, ?int $sid = null, bool $openOnly = false): array
    {
        $w = []; $a = [];
        if ($sid !== null) { $w[] = 'd.SupplierId = ?'; $a[] = $sid; }
        if ($openOnly) $w[] = "d.Status <> 'resolved'";
        $st = $dbh->prepare('SELECT d.*, s.BusinessName FROM tbl_supplier_disputes d JOIN tbl_suppliers s ON s.SupplierId = d.SupplierId'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(d.Status,'open','under_review','resolved'), d.DisputeId DESC LIMIT 200");
        $st->execute($a);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
