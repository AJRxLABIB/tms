<?php
/**
 * FraudDetector.php — Duplicate Account & Suspicious Booking Detection
 *
 * Same philosophy as the fake-review heuristics in SentimentAnalyser and the
 * price/crowd predictors: every signal here is deterministic SQL over real
 * platform data, not an AI guess. No LLM call anywhere in this class — these
 * patterns (shared phone numbers, Gmail dot/plus tricks, coupon-code
 * clustering, rapid-fire bookings, abnormal cancellation rates) are exact,
 * checkable facts, and an AI's opinion would add latency and cost without
 * adding certainty. Every method returns WHY something was flagged, since
 * these are soft signals for human review, not automatic account/booking
 * actions.
 */

class FraudDetector {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Accounts sharing a phone number, or emails that are the same inbox
     * under Gmail's dot/plus-addressing trick (john.doe@gmail.com,
     * j.o.h.n.doe@gmail.com, and johndoe+promo@gmail.com all deliver to the
     * same mailbox — a classic way to farm sign-up bonuses/first-booking
     * coupons with "different" accounts).
     */
    public function getDuplicateAccounts(): array {
        $groups = [];

        // Signal 1: shared mobile number across different accounts
        try {
            $stmt = $this->db->query(
                "SELECT MobileNumber, GROUP_CONCAT(EmailId SEPARATOR ', ') AS emails, COUNT(*) AS cnt
                 FROM tblusers
                 WHERE MobileNumber IS NOT NULL AND MobileNumber != ''
                 GROUP BY MobileNumber
                 HAVING cnt > 1"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                $groups[] = [
                    'reason'   => 'Same phone number (' . $row->MobileNumber . ') used on ' . $row->cnt . ' different accounts.',
                    'accounts' => explode(', ', $row->emails),
                ];
            }
        } catch (Exception $e) {
            error_log('[TMS FraudDetector] Duplicate mobile check failed: ' . $e->getMessage());
        }

        // Signal 2: Gmail dot/plus-addressing trick — normalize and group
        try {
            $emails = $this->db->query("SELECT EmailId FROM tblusers WHERE EmailId LIKE '%@gmail.com'")->fetchAll(PDO::FETCH_COLUMN);
            $byNormalized = [];
            foreach ($emails as $email) {
                $normalized = $this->normalizeGmail($email);
                $byNormalized[$normalized][] = $email;
            }
            foreach ($byNormalized as $normalized => $variants) {
                if (count($variants) > 1) {
                    $groups[] = [
                        'reason'   => 'Gmail dot/plus-address variants of the same inbox (' . $normalized . ') registered as ' . count($variants) . ' separate accounts.',
                        'accounts' => $variants,
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('[TMS FraudDetector] Gmail-trick check failed: ' . $e->getMessage());
        }

        return $groups;
    }

    /**
     * Booking-side abuse patterns. Each returned row is one flagged
     * booking/cluster with a concrete, checkable reason.
     */
    public function getSuspiciousBookings(int $limit = 30): array {
        $flagged = [];

        // Signal 1: coupon-code clusters — the same coupon used many times
        // by accounts that share a phone number (one person farming a
        // "first booking" or referral discount across fake accounts).
        try {
            $stmt = $this->db->query(
                "SELECT b.CouponCode, u.MobileNumber, COUNT(DISTINCT b.UserEmail) AS distinct_users, COUNT(*) AS uses
                 FROM tblbooking b
                 JOIN tblusers u ON u.EmailId = b.UserEmail
                 WHERE b.CouponCode IS NOT NULL AND b.CouponCode != '' AND u.MobileNumber IS NOT NULL AND u.MobileNumber != ''
                 GROUP BY b.CouponCode, u.MobileNumber
                 HAVING distinct_users > 1
                 ORDER BY uses DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                $flagged[] = [
                    'type'   => 'coupon_cluster',
                    'reason' => "Coupon '{$row->CouponCode}' used {$row->uses} times by {$row->distinct_users} different accounts sharing phone number {$row->MobileNumber}.",
                    'detail' => (array)$row,
                ];
            }
        } catch (Exception $e) {
            error_log('[TMS FraudDetector] Coupon cluster check failed: ' . $e->getMessage());
        }

        // Signal 2: rapid-fire bookings — 5+ bookings from the same account
        // within a single hour (RegDate has time precision; BookingDate is
        // date-only and not reliable for this check).
        try {
            $stmt = $this->db->query(
                "SELECT UserEmail, DATE_FORMAT(RegDate, '%Y-%m-%d %H:00') AS hour_bucket, COUNT(*) AS cnt
                 FROM tblbooking
                 GROUP BY UserEmail, hour_bucket
                 HAVING cnt >= 5
                 ORDER BY cnt DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                $flagged[] = [
                    'type'   => 'rapid_fire',
                    'reason' => "{$row->cnt} bookings placed by {$row->UserEmail} within a single hour ({$row->hour_bucket}) — unusual for genuine trip planning.",
                    'detail' => (array)$row,
                ];
            }
        } catch (Exception $e) {
            error_log('[TMS FraudDetector] Rapid-fire check failed: ' . $e->getMessage());
        }

        // Signal 3: abnormal cancellation rate — accounts with several
        // bookings where most were cancelled (book-then-cancel patterns can
        // indicate card testing, or coupon/promo abuse via cancel-and-retry).
        try {
            $stmt = $this->db->query(
                "SELECT UserEmail,
                        COUNT(*) AS total_bookings,
                        SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS cancelled
                 FROM tblbooking
                 GROUP BY UserEmail
                 HAVING total_bookings >= 5 AND (cancelled / total_bookings) >= 0.8
                 ORDER BY total_bookings DESC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                $pct = round(($row->cancelled / $row->total_bookings) * 100);
                $flagged[] = [
                    'type'   => 'high_cancellation',
                    'reason' => "{$row->UserEmail}: {$row->cancelled} of {$row->total_bookings} bookings cancelled ({$pct}%) — well above normal.",
                    'detail' => (array)$row,
                ];
            }
        } catch (Exception $e) {
            error_log('[TMS FraudDetector] Cancellation-rate check failed: ' . $e->getMessage());
        }

        return $flagged;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Normalizes a Gmail address the way Gmail itself treats it: dots in the
     * local part are ignored, and anything from a "+" onward is a
     * sub-address that still delivers to the base inbox. Only applies to
     * @gmail.com (and googlemail.com) — this trick is specific to how
     * Gmail's mail routing works, not a general email property.
     */
    private function normalizeGmail(string $email): string {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if (!in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return $email; // not Gmail — no normalization applies, treated as its own unique address
        }
        $local = strtok($local, '+'); // drop +suffix
        $local = str_replace('.', '', $local); // dots are ignored by Gmail
        return $local . '@gmail.com';
    }
}
