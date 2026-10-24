<?php
/**
 * FraudCases — transparent anomaly signals and a human review workflow.
 *
 * Principles:
 *  - Every signal is a fixed, explainable rule over real rows (no model, no hidden weights). The evidence (exact
 *    figures and ids) is stored with the case so a reviewer can check it.
 *  - A score is a PRIORITY for investigation, never proof. Nothing in this class blocks, bans, refunds, cancels or
 *    otherwise penalises a customer. "confirmed" is a reviewer's finding that a human acts on elsewhere.
 *  - Minimum sample sizes are built into each rule so thin data cannot produce a case.
 *  - Customers can appeal; appeals are recorded and answered by a human.
 *
 * Pure parts (score(), canTransition(), severity()) need no database and are unit-tested.
 * Scanning parts guard every table with an existence check and never throw into the caller.
 */
class FraudCases
{
    public const SIGNALS = ['repeated_payment_attempts', 'duplicate_booking', 'coupon_referral_abuse', 'loyalty_anomaly', 'cancellation_pattern'];
    public const STATUSES = ['open', 'reviewing', 'cleared', 'confirmed'];
    public const DECISIONS = ['reviewing', 'cleared', 'confirmed', 'note'];

    private const TRANSITIONS = [
        'open'      => ['reviewing', 'cleared', 'confirmed'],
        'reviewing' => ['reviewing', 'cleared', 'confirmed'],
        'cleared'   => ['reviewing'],      // reopen (for example after an appeal)
        'confirmed' => ['reviewing'],
    ];

    public static function label(string $signal): string
    {
        return [
            'repeated_payment_attempts' => 'Repeated payment attempts',
            'duplicate_booking'         => 'Duplicate booking pattern',
            'coupon_referral_abuse'     => 'Coupon / referral usage',
            'loyalty_anomaly'           => 'Unusual loyalty-point movement',
            'cancellation_pattern'      => 'Unexpected cancellation pattern',
        ][$signal] ?? $signal;
    }

    public static function severity(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Score one signal from its measured figures. Returns score 0 when the sample is too small or below threshold
     * (the scanner then creates no case). Every non-zero result carries a plain-language reason.
     *
     * @param array<string,int|float> $m measured figures (see each branch for keys)
     * @return array{score:int, severity:string, reason:string}
     */
    public static function score(string $signal, array $m): array
    {
        $score = 0; $reason = '';
        switch ($signal) {
            case 'repeated_payment_attempts':          // attempts, failed, hours
                $n = (int)($m['attempts'] ?? 0); $f = (int)($m['failed'] ?? 0); $h = max(1, (int)($m['hours'] ?? 24));
                if ($n >= 4) {
                    $score = min(100, 20 * $n + 5 * $f);
                    $reason = "{$n} payment attempts in {$h}h ({$f} failed or cancelled).";
                }
                break;
            case 'duplicate_booking':                  // copies (active bookings with same customer, package and start date)
                $c = (int)($m['copies'] ?? 0);
                if ($c >= 2) {
                    $score = min(100, 35 + 20 * ($c - 2));
                    $reason = "{$c} active bookings for the same package and start date by one customer.";
                }
                break;
            case 'coupon_referral_abuse':              // coupon_bookings, days, referred, distinct_phones
                $cb = (int)($m['coupon_bookings'] ?? 0); $d = max(1, (int)($m['days'] ?? 7));
                $rf = (int)($m['referred'] ?? 0); $dp = (int)($m['distinct_phones'] ?? 0);
                $parts = [];
                if ($cb >= 3) { $score += min(70, 20 * $cb); $parts[] = "{$cb} coupon-discounted bookings in {$d} days"; }
                if ($rf >= 5 && $dp * 10 < $rf * 6) {  // fewer distinct phone numbers than 60% of referred accounts
                    $score += min(70, 10 * $rf); $parts[] = "{$rf} referred accounts sharing only {$dp} distinct phone number(s)";
                }
                $score = min(100, $score); $reason = implode('; ', $parts) . '.';
                break;
            case 'loyalty_anomaly':                    // points, hours
                $p = (int)($m['points'] ?? 0); $h = max(1, (int)($m['hours'] ?? 24));
                if ($p >= 3000) {
                    $score = min(100, 30 + intdiv($p, 100));
                    $reason = "{$p} loyalty points credited in {$h}h.";
                }
                break;
            case 'cancellation_pattern':               // bookings, cancelled, days
                $b = (int)($m['bookings'] ?? 0); $x = (int)($m['cancelled'] ?? 0); $d = max(1, (int)($m['days'] ?? 90));
                if ($b >= 4 && $x * 10 >= $b * 6) {
                    $score = min(100, 30 + (int)round(50 * $x / $b) + 3 * min($b, 10));
                    $reason = "{$x} of {$b} bookings cancelled in {$d} days.";
                }
                break;
        }
        $score = max(0, min(100, $score));
        return ['score' => $score, 'severity' => self::severity($score), 'reason' => $score > 0 ? $reason : ''];
    }

    // ── Database side ──────────────────────────────────────────────────────────────────────────────────────────

    private static function tableExists(PDO $dbh, string $table): bool
    {
        try {
            $st = $dbh->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    public static function installed(PDO $dbh): bool
    {
        return self::tableExists($dbh, 'tbl_fraud_cases') && self::tableExists($dbh, 'tbl_fraud_case_decisions') && self::tableExists($dbh, 'tbl_fraud_appeals');
    }

    /**
     * Run every signal query and return candidate findings (no writes).
     * @return array<int, array{signal:string, subject_type:string, subject_key:string, score:int, severity:string, reason:string, evidence:array}>
     */
    public static function scan(PDO $dbh): array
    {
        $out = [];
        $add = function (string $signal, string $key, array $metrics, array $evidence, string $type = 'user') use (&$out) {
            $s = self::score($signal, $metrics);
            if ($s['score'] > 0) {
                $out[] = ['signal' => $signal, 'subject_type' => $type, 'subject_key' => mb_substr($key, 0, 150),
                          'score' => $s['score'], 'severity' => $s['severity'], 'reason' => $s['reason'], 'evidence' => $evidence + ['metrics' => $metrics]];
            }
        };
        $guard = function (callable $fn, string $name) {
            try { $fn(); } catch (Throwable $e) { error_log('[TourBuddy FraudCases] signal ' . $name . ' failed: ' . $e->getMessage()); }
        };

        if (self::tableExists($dbh, 'tblpayments') && self::tableExists($dbh, 'tblbooking')) {
            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT b.UserEmail AS email, COUNT(*) AS attempts, SUM(p.Status IN ('failed','cancelled','expired')) AS failed,
                            GROUP_CONCAT(p.PaymentId ORDER BY p.PaymentId SEPARATOR ',') AS ids
                     FROM tblpayments p JOIN tblbooking b ON b.BookingId = p.BookingId
                     WHERE p.CreatedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND b.UserEmail IS NOT NULL
                     GROUP BY b.UserEmail HAVING attempts >= 4 LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('repeated_payment_attempts', (string)$r['email'], ['attempts' => (int)$r['attempts'], 'failed' => (int)$r['failed'], 'hours' => 24], ['payment_ids' => array_slice(explode(',', (string)$r['ids']), 0, 25)]);
            }, 'repeated_payment_attempts');
        }

        if (self::tableExists($dbh, 'tblbooking')) {
            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT UserEmail AS email, PackageId, FromDate, COUNT(*) AS copies, GROUP_CONCAT(BookingId ORDER BY BookingId SEPARATOR ',') AS ids
                     FROM tblbooking WHERE status IN (0,1) AND UserEmail IS NOT NULL
                     GROUP BY UserEmail, PackageId, FromDate HAVING copies >= 2 LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('duplicate_booking', (string)$r['email'], ['copies' => (int)$r['copies']], ['package_id' => (int)$r['PackageId'], 'from_date' => (string)$r['FromDate'], 'booking_ids' => array_slice(explode(',', (string)$r['ids']), 0, 25)]);
            }, 'duplicate_booking');

            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT UserEmail AS email, COUNT(*) AS n, GROUP_CONCAT(BookingId ORDER BY BookingId SEPARATOR ',') AS ids
                     FROM tblbooking WHERE CouponCode IS NOT NULL AND CouponCode <> '' AND RegDate >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND UserEmail IS NOT NULL
                     GROUP BY UserEmail HAVING n >= 3 LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('coupon_referral_abuse', (string)$r['email'], ['coupon_bookings' => (int)$r['n'], 'days' => 7], ['booking_ids' => array_slice(explode(',', (string)$r['ids']), 0, 25)]);
            }, 'coupon_usage');

            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT UserEmail AS email, COUNT(*) AS n, SUM(status = 2) AS cancelled
                     FROM tblbooking WHERE RegDate >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND UserEmail IS NOT NULL
                     GROUP BY UserEmail HAVING n >= 4 AND SUM(status = 2) * 10 >= COUNT(*) * 6 LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('cancellation_pattern', (string)$r['email'], ['bookings' => (int)$r['n'], 'cancelled' => (int)$r['cancelled'], 'days' => 90], []);
            }, 'cancellation_pattern');
        }

        if (self::tableExists($dbh, 'tblusers')) {
            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT ReferredBy AS ref, COUNT(*) AS n, COUNT(DISTINCT MobileNumber) AS phones
                     FROM tblusers WHERE ReferredBy IS NOT NULL AND ReferredBy <> ''
                     GROUP BY ReferredBy HAVING n >= 5 AND COUNT(DISTINCT MobileNumber) * 10 < COUNT(*) * 6 LIMIT 100"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('coupon_referral_abuse', 'referrer:' . (string)$r['ref'], ['referred' => (int)$r['n'], 'distinct_phones' => (int)$r['phones']], [], 'referrer');
            }, 'referral_cluster');
        }

        if (self::tableExists($dbh, 'tblloyalty_log')) {
            $guard(function () use ($dbh, $add) {
                $rows = $dbh->query(
                    "SELECT UserEmail AS email, SUM(Points) AS pts, COUNT(*) AS n FROM tblloyalty_log
                     WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND Points > 0 AND UserEmail IS NOT NULL
                     GROUP BY UserEmail HAVING pts >= 3000 LIMIT 200"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $add('loyalty_anomaly', (string)$r['email'], ['points' => (int)$r['pts'], 'hours' => 24], ['entries' => (int)$r['n']]);
            }, 'loyalty_anomaly');
        }
        return $out;
    }

    /**
     * Persist findings as cases. Idempotent per signal+subject+day (unique key + INSERT IGNORE), and quiet:
     * skips subjects that already have an open/reviewing case for the same signal, or were cleared in the last 30 days.
     * @return array{created:int, existing:int, suppressed:int}
     */
    public static function record(PDO $dbh, array $findings): array
    {
        $sum = ['created' => 0, 'existing' => 0, 'suppressed' => 0];
        if (!self::installed($dbh)) return $sum;
        $active = $dbh->prepare("SELECT 1 FROM tbl_fraud_cases WHERE signal = ? AND subject_key = ? AND day_key <> CURDATE() AND (status IN ('open','reviewing') OR (status = 'cleared' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))) LIMIT 1");
        $ins = $dbh->prepare("INSERT IGNORE INTO tbl_fraud_cases (signal, subject_type, subject_key, day_key, score, severity, reason, evidence) VALUES (?,?,?,CURDATE(),?,?,?,?)");
        foreach ($findings as $f) {
            $active->execute([$f['signal'], $f['subject_key']]);
            if ($active->fetchColumn()) { $sum['suppressed']++; continue; }
            $ins->execute([$f['signal'], $f['subject_type'], $f['subject_key'], $f['score'], $f['severity'], mb_substr($f['reason'], 0, 500), json_encode($f['evidence'], JSON_UNESCAPED_UNICODE)]);
            if ($ins->rowCount() > 0) $sum['created']++; else $sum['existing']++;
        }
        return $sum;
    }

    /** Record a reviewer decision (validated transition, history row, all in one transaction). @return string|null error */
    public static function decide(PDO $dbh, int $caseId, string $reviewer, string $decision, string $note): ?string
    {
        $note = trim($note);
        if (!in_array($decision, self::DECISIONS, true)) return 'Unknown decision.';
        if (in_array($decision, ['cleared', 'confirmed'], true) && mb_strlen($note) < 5) return 'A note (at least 5 characters) is required to clear or confirm a case.';
        if (mb_strlen($note) > 1000) return 'Note is too long (max 1000 characters).';
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT status FROM tbl_fraud_cases WHERE id = ? FOR UPDATE');
            $st->execute([$caseId]);
            $cur = $st->fetchColumn();
            if ($cur === false) { $dbh->rollBack(); return 'Case not found.'; }
            if ($decision !== 'note') {
                if (!self::canTransition((string)$cur, $decision)) { $dbh->rollBack(); return "A {$cur} case cannot move to {$decision}."; }
                $dbh->prepare('UPDATE tbl_fraud_cases SET status = ? WHERE id = ?')->execute([$decision, $caseId]);
            }
            $dbh->prepare('INSERT INTO tbl_fraud_case_decisions (case_id, reviewer, decision, note) VALUES (?,?,?,?)')
                ->execute([$caseId, mb_substr($reviewer, 0, 100), $decision, $note !== '' ? $note : null]);
            $dbh->commit();
            return null;
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy FraudCases] decide failed: ' . $e->getMessage());
            return 'Could not save the decision.';
        }
    }

    /** Plain-language status for a customer. Never exposes scores, signals or evidence. */
    public static function customerStatusLabel(string $caseStatus, ?string $appealStatus): string
    {
        if ($appealStatus === 'open') return 'Your appeal is waiting for a reviewer.';
        if ($appealStatus === 'upheld') return 'Your appeal was accepted and the review has been reopened.';
        if ($appealStatus === 'dismissed') return 'Your appeal was reviewed and the earlier decision stands.';
        return $caseStatus === 'confirmed' ? 'A reviewer recorded a decision about unusual activity on your account.' : 'Under review.';
    }

    /**
     * What a customer may see about reviews of THEIR OWN account: id, date, a plain status and their own appeal.
     * Only reviews that ended in a recorded decision (confirmed), or that already have an appeal, are listed, so the
     * page does not announce internal checks that are still open or that cleared the customer.
     * @return array<int,array{id:int,date:string,label:string,can_appeal:bool,appeal_status:?string,response:?string}>
     */
    public static function customerView(PDO $dbh, string $email): array
    {
        try {
            $st = $dbh->prepare(
                "SELECT c.id, c.created_at, c.status,
                        (SELECT a.status   FROM tbl_fraud_appeals a WHERE a.case_id = c.id ORDER BY a.id DESC LIMIT 1) AS ap_status,
                        (SELECT a.response FROM tbl_fraud_appeals a WHERE a.case_id = c.id ORDER BY a.id DESC LIMIT 1) AS ap_response
                 FROM tbl_fraud_cases c
                 WHERE c.subject_type = 'user' AND c.subject_key = ?
                   AND (c.status = 'confirmed' OR EXISTS (SELECT 1 FROM tbl_fraud_appeals a2 WHERE a2.case_id = c.id))
                 ORDER BY c.id DESC LIMIT 20");
            $st->execute([$email]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ap = $r['ap_status'] !== null ? (string)$r['ap_status'] : null;
                $out[] = [
                    'id' => (int)$r['id'],
                    'date' => substr((string)$r['created_at'], 0, 10),
                    'label' => self::customerStatusLabel((string)$r['status'], $ap),
                    'can_appeal' => $ap !== 'open',
                    'appeal_status' => $ap,
                    'response' => $r['ap_response'] !== null ? (string)$r['ap_response'] : null,
                ];
            }
            return $out;
        } catch (Throwable $e) { return []; }   // tables not migrated yet
    }

    /** A customer appeals a case about their account. Caller must have authenticated $email. @return string|null error */
    public static function fileAppeal(PDO $dbh, int $caseId, string $email, string $message): ?string
    {
        $message = trim($message);
        if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) return 'Please explain in 10 to 2000 characters.';
        try {
            $st = $dbh->prepare('SELECT subject_key FROM tbl_fraud_cases WHERE id = ?');
            $st->execute([$caseId]);
            $key = $st->fetchColumn();
            if ($key === false || strcasecmp((string)$key, $email) !== 0) return 'Case not found.';   // no case-existence oracle for other accounts
            $dup = $dbh->prepare("SELECT 1 FROM tbl_fraud_appeals WHERE case_id = ? AND status = 'open' LIMIT 1");
            $dup->execute([$caseId]);
            if ($dup->fetchColumn()) return 'An appeal for this case is already open.';
            $dbh->prepare('INSERT INTO tbl_fraud_appeals (case_id, user_email, message) VALUES (?,?,?)')->execute([$caseId, mb_substr($email, 0, 100), $message]);
            return null;
        } catch (Throwable $e) { error_log('[TourBuddy FraudCases] appeal failed: ' . $e->getMessage()); return 'Could not file the appeal.'; }
    }

    /** A human answers an appeal; an upheld appeal reopens the case for review (it never changes the customer's account). @return string|null error */
    public static function respondToAppeal(PDO $dbh, int $appealId, string $reviewer, string $outcome, string $response): ?string
    {
        $response = trim($response);
        if (!in_array($outcome, ['upheld', 'dismissed'], true)) return 'Unknown outcome.';
        if (mb_strlen($response) < 5 || mb_strlen($response) > 1000) return 'A response of 5 to 1000 characters is required.';
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare("SELECT case_id FROM tbl_fraud_appeals WHERE id = ? AND status = 'open' FOR UPDATE");
            $st->execute([$appealId]);
            $caseId = $st->fetchColumn();
            if ($caseId === false) { $dbh->rollBack(); return 'Appeal not found or already answered.'; }
            $dbh->prepare('UPDATE tbl_fraud_appeals SET status = ?, response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                ->execute([$outcome, $response, mb_substr($reviewer, 0, 100), $appealId]);
            if ($outcome === 'upheld') {
                $dbh->prepare("UPDATE tbl_fraud_cases SET status = 'reviewing' WHERE id = ? AND status IN ('cleared','confirmed')")->execute([(int)$caseId]);
            }
            $dbh->prepare('INSERT INTO tbl_fraud_case_decisions (case_id, reviewer, decision, note) VALUES (?,?,?,?)')
                ->execute([(int)$caseId, mb_substr($reviewer, 0, 100), 'note', 'Appeal ' . $outcome . ': ' . mb_substr($response, 0, 900)]);
            $dbh->commit();
            return null;
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy FraudCases] appeal response failed: ' . $e->getMessage());
            return 'Could not save the response.';
        }
    }
}
