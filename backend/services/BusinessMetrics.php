<?php
/**
 * BusinessMetrics — read-only, whitelisted business metrics for the admin "Business copilot".
 *
 * Safety model:
 *   - Every number comes from a FIXED prepared SQL template in this file. Nothing a user (or a model) types is ever
 *     placed in SQL text; only validated dates and a bounded integer LIMIT are bound/cast.
 *   - A question is mapped to a template by a deterministic keyword matcher (English, Bengali, Banglish). If nothing
 *     matches, the answer is "I can't answer that", never a guess.
 *   - Every result carries the period, the metric definition and the figures behind the summary.
 *   - Aggregates only: no customer emails or booking rows are returned.
 *
 * The matcher functions (normalize/parsePeriod/matchIntent/parseLimit) are pure and tested without a database
 * (tests/unit/business_metrics_test.php).
 */
final class BusinessMetrics
{
    public const T_TOP_DEST   = 'top_destinations';
    public const T_BOOKINGS   = 'bookings_summary';
    public const T_SUPPLIERS  = 'supplier_cancellations';
    public const T_UNRECON    = 'unreconciled_payments';
    public const T_DEFINITIONS = 'metric_definitions';

    /** Template id => title. This is the complete whitelist. */
    public const TEMPLATES = [
        self::T_TOP_DEST    => 'Top destinations by completed bookings',
        self::T_BOOKINGS    => 'Bookings and conversion',
        self::T_SUPPLIERS   => 'Supplier cancellation rates',
        self::T_UNRECON     => 'Unreconciled payments',
        self::T_DEFINITIONS => 'Revenue metric definitions',
    ];

    /** A supplier is flagged "elevated" at or above this cancellation rate, with at least MIN_SAMPLE bookings. */
    public const ELEVATED_RATE = 0.25;
    public const MIN_SAMPLE    = 3;

    // ───────────────────────── pure text handling ─────────────────────────

    public static function normalize(string $q): string
    {
        $q = mb_strtolower(trim($q), 'UTF-8');
        $q = strtr($q, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        return (string)preg_replace('/\s+/u', ' ', $q);
    }

    /** True if $q contains any needle. ASCII needles match at a word start; Bengali needles match as substrings. */
    private static function has(string $q, array $needles): bool
    {
        foreach ($needles as $n) {
            if (preg_match('/[^\x00-\x7F]/', $n)) {
                if (mb_strpos($q, $n, 0, 'UTF-8') !== false) return true;
            } elseif (preg_match('/(?<![a-z0-9])' . preg_quote($n, '/') . '/u', $q)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a time phrase to an inclusive date range. Default (no phrase) is the last 30 days, flagged explicit=false
     * so the answer can say so. Weeks start on Monday; quarters are calendar quarters.
     * @return array{start:string,end:string,label:string,explicit:bool}
     */
    public static function parsePeriod(string $question, ?DateTimeImmutable $now = null): array
    {
        $q   = self::normalize($question);
        $now = $now ?? new DateTimeImmutable('now');
        $today = $now->setTime(0, 0, 0);
        $mk = fn(DateTimeImmutable $s, DateTimeImmutable $e, string $label, bool $ex = true) =>
            ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d'), 'label' => $label, 'explicit' => $ex];

        if (preg_match('/(?:last|past|previous)\s+(\d{1,3})\s+days?/u', $q, $m) || preg_match('/গত\s*(\d{1,3})\s*দিন/u', $q, $m)) {
            $n = max(1, min(366, (int)$m[1]));
            return $mk($today->modify('-' . ($n - 1) . ' days'), $today, "last {$n} days");
        }
        if (self::has($q, ['yesterday', 'গতকাল', 'gotokal', 'gatokal', 'kal ']) && !self::has($q, ['tomorrow'])) {
            $y = $today->modify('-1 day');
            return $mk($y, $y, 'yesterday (' . $y->format('Y-m-d') . ')');
        }
        if (self::has($q, ['today', 'আজ', 'ajke', 'aj '])) {
            return $mk($today, $today, 'today (' . $today->format('Y-m-d') . ')');
        }

        $quarterStart = function (DateTimeImmutable $d): DateTimeImmutable {
            $m = (int)$d->format('n');
            return $d->setDate((int)$d->format('Y'), intdiv($m - 1, 3) * 3 + 1, 1);
        };
        $endOfQuarter = fn(DateTimeImmutable $s) => $s->modify('+3 months')->modify('-1 day');

        if (self::has($q, ['last quarter', 'previous quarter', 'গত কোয়ার্টার', 'গত ত্রৈমাসিক', 'gato quarter', 'gato quater', 'ager quarter'])) {
            $s = $quarterStart($today)->modify('-3 months');
            return $mk($s, $endOfQuarter($s), 'last quarter (' . $s->format('M Y') . ' to ' . $endOfQuarter($s)->format('M Y') . ')');
        }
        if (self::has($q, ['this quarter', 'current quarter', 'এই কোয়ার্টার', 'এই ত্রৈমাসিক', 'ei quarter'])) {
            $s = $quarterStart($today);
            return $mk($s, $today, 'this quarter so far (' . $s->format('M Y') . ' onward)');
        }
        if (self::has($q, ['last month', 'previous month', 'গত মাস', 'gato mash', 'gotomash', 'goto mash', 'gato mas', 'ager mash', 'last mash'])) {
            $s = $today->modify('first day of last month');
            return $mk($s, $s->modify('last day of this month'), 'last month (' . $s->format('F Y') . ')');
        }
        if (self::has($q, ['this month', 'current month', 'এই মাস', 'ei mash', 'ei mas', 'ei mase'])) {
            $s = $today->modify('first day of this month');
            return $mk($s, $today, 'this month so far (' . $s->format('F Y') . ')');
        }
        $monday = $today->modify('monday this week');
        if (self::has($q, ['last week', 'previous week', 'গত সপ্তাহ', 'gato soptaho', 'gato shoptaho', 'gato soptah', 'ager soptaho'])) {
            $s = $monday->modify('-7 days');
            return $mk($s, $s->modify('+6 days'), 'last week (' . $s->format('Y-m-d') . ' to ' . $s->modify('+6 days')->format('Y-m-d') . ')');
        }
        if (self::has($q, ['this week', 'এই সপ্তাহ', 'ei soptaho', 'ei shoptaho', 'ei soptah'])) {
            return $mk($monday, $today, 'this week so far');
        }
        if (self::has($q, ['last year', 'previous year', 'গত বছর', 'গত বৎসর', 'gato bochor', 'gato bosor', 'ager bochor'])) {
            $y = (int)$today->format('Y') - 1;
            return $mk(new DateTimeImmutable("$y-01-01"), new DateTimeImmutable("$y-12-31"), "last year ($y)");
        }
        if (self::has($q, ['this year', 'এই বছর', 'ei bochor', 'ei bosor'])) {
            $y = (int)$today->format('Y');
            return $mk(new DateTimeImmutable("$y-01-01"), $today, "this year so far ($y)");
        }
        return $mk($today->modify('-29 days'), $today, 'last 30 days (default; no period was named)', false);
    }

    /** "top 10" style limit, default 5, clamped 1..20. */
    public static function parseLimit(string $question): int
    {
        $q = self::normalize($question);
        if (preg_match('/(?:top|best|first|শীর্ষ)\s*(\d{1,2})/u', $q, $m)) return max(1, min(20, (int)$m[1]));
        if (preg_match('/(\d{1,2})\s*(?:destinations?|suppliers?|টি)/u', $q, $m)) return max(1, min(20, (int)$m[1]));
        return 5;
    }

    /**
     * Map a question to one whitelisted template.
     * @return array{template:?string,score:int,period:array,limit:int}
     */
    public static function matchIntent(string $question, ?DateTimeImmutable $now = null): array
    {
        $q = self::normalize($question);
        $period = self::parsePeriod($question, $now);
        $limit  = self::parseLimit($question);
        $none = ['template' => null, 'score' => 0, 'period' => $period, 'limit' => $limit];
        if ($q === '') return $none;

        $metricTerm = self::has($q, ['gross', 'collected', 'revenue', 'commission', 'refund', 'booking value', 'net revenue',
            'কমিশন', 'রিফান্ড', 'রেভিনিউ', 'আয়', 'commision', 'income']);
        $askDefinition = self::has($q, ['difference', 'define', 'definition', 'meaning', 'what is', 'what are', 'explain', 'vs', 'versus',
            'মানে', 'পার্থক্য', 'সংজ্ঞা', 'ki ', 'ki?', 'ki e', 'fark', 'parthokko']);
        if ($metricTerm && $askDefinition) {
            return ['template' => self::T_DEFINITIONS, 'score' => 10, 'period' => $period, 'limit' => $limit];
        }

        $score = [
            self::T_TOP_DEST  => 0, self::T_BOOKINGS => 0, self::T_SUPPLIERS => 0, self::T_UNRECON => 0,
        ];
        if (self::has($q, ['destination', 'popular', 'most booked', 'busiest', 'top place', 'where', 'location',
            'জনপ্রিয়', 'গন্তব্য', 'ডেস্টিনেশন', 'kothay', 'jaiga', 'jayga', 'jaygay', 'sthan', 'gontobbo', 'gontobyo'])) $score[self::T_TOP_DEST] += 3;
        if (self::has($q, ['completed', 'highest', 'top ', 'most', 'volume', 'সম্পন্ন', 'সবচেয়ে', 'sob theke', 'sobcheye', 'beshi'])) $score[self::T_TOP_DEST] += 1;

        if (self::has($q, ['booking', 'conversion', 'convert', 'বুকিং', 'কনভার্সন', 'konvarsion', 'koyta', 'koita', 'koto'])) $score[self::T_BOOKINGS] += 2;
        if (self::has($q, ['conversion', 'convert', 'rate', 'trend', 'change', 'grew', 'growth', 'কনভার্সন', 'পরিবর্তন', 'poriborton'])) $score[self::T_BOOKINGS] += 2;

        if (self::has($q, ['supplier', 'partner', 'vendor', 'সাপ্লায়ার', 'সরবরাহকারী', 'পার্টনার', 'saplayar', 'suppliar'])) $score[self::T_SUPPLIERS] += 3;
        if (self::has($q, ['cancel', 'cancellation', 'বাতিল', 'ক্যান্সেল', 'batil', 'elevated', 'high rate'])) $score[self::T_SUPPLIERS] += 2;

        if (self::has($q, ['unreconciled', 'reconcil', 'unmatched', 'mismatch', 'stuck payment', 'pending payment', 'payment issue',
            'মিলছে না', 'রিকনসাইল', 'mil nai', 'milche na', 'milena'])) $score[self::T_UNRECON] += 5;
        if (self::has($q, ['payment', 'pament', 'পেমেন্ট', 'টাকা', 'taka'])) $score[self::T_UNRECON] += 1;

        // "supplier ... cancel" must beat generic "booking" wording; bookings need "supplier" absent.
        if ($score[self::T_SUPPLIERS] >= 3) $score[self::T_BOOKINGS] = max(0, $score[self::T_BOOKINGS] - 2);

        arsort($score);
        $best = array_key_first($score);
        $val  = $score[$best];
        if ($val < 3) return $none;
        // Tie between two templates at the top is ambiguous: refuse rather than guess.
        $vals = array_values($score);
        if (isset($vals[1]) && $vals[1] === $val) return $none;
        return ['template' => $best, 'score' => $val, 'period' => $period, 'limit' => $limit];
    }

    // ───────────────────────── definitions ─────────────────────────

    /** @return array<string,string> */
    public static function definitions(): array
    {
        return [
            'Gross booking value' => 'Sum of tblbooking.TotalCost for bookings registered in the period that are not cancelled (status 0 pending or 1 confirmed). It is what customers have committed to, paid or not.',
            'Collected revenue'   => 'Sum of tblpayments.AmountMinor/100 for payments in the period whose status is paid, partially_refunded or refunded (money actually received through the payment system, before refunds). Bookings paid before the payment ledger existed have no row there and are not counted.',
            'Refunds'             => 'Sum of tblpayment_refunds.AmountMinor/100 with status processed in the period (money actually returned). Pending refund requests are counted separately and are not subtracted.',
            'Net collected'       => 'Collected revenue minus refunds, for the same period.',
            'Platform commission' => 'Sum of tbl_supplier_settlements.CommissionAmount for settlements created in the period that are not drafts. It is the platform\'s share of supplier bookings, and is only known once a settlement has been prepared.',
        ];
    }

    // ───────────────────────── execution ─────────────────────────

    private static function tableExists(PDO $dbh, string $table): bool
    {
        try {
            $st = $dbh->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }

    private static function validDate(string $d): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    }

    /**
     * Run one template. Never throws: failures come back as ok=false with a short reason.
     * @return array{ok:bool,template:string,title:string,period:array,definition:string,figures:array,notes:array,error?:string}
     */
    public static function run(PDO $dbh, string $template, array $period, int $limit = 5): array
    {
        $out = ['ok' => false, 'template' => $template, 'title' => self::TEMPLATES[$template] ?? 'Unknown', 'period' => $period,
                'definition' => '', 'figures' => [], 'notes' => []];
        if (!isset(self::TEMPLATES[$template])) { $out['error'] = 'Unknown metric.'; return $out; }
        if (!self::validDate((string)($period['start'] ?? '')) || !self::validDate((string)($period['end'] ?? ''))) { $out['error'] = 'Invalid period.'; return $out; }
        $limit = max(1, min(20, $limit));
        try {
            switch ($template) {
                case self::T_TOP_DEST:    return self::topDestinations($dbh, $out, $limit);
                case self::T_BOOKINGS:    return self::bookingsSummary($dbh, $out);
                case self::T_SUPPLIERS:   return self::supplierCancellations($dbh, $out, $limit);
                case self::T_UNRECON:     return self::unreconciled($dbh, $out);
                case self::T_DEFINITIONS: return self::definitionsResult($dbh, $out);
            }
        } catch (Throwable $e) {
            error_log('[TMS BusinessMetrics] ' . $template . ': ' . $e->getMessage());
            $out['error'] = 'This metric is not available right now (a table or column it needs could not be read).';
        }
        return $out;
    }

    private static function topDestinations(PDO $dbh, array $out, int $limit): array
    {
        $out['definition'] = 'Completed booking = a confirmed booking (status 1) whose trip end date (ToDate) falls inside the period and is already in the past. Destination = the package\'s PackageLocation. Booking value is the sum of TotalCost.';
        $st = $dbh->prepare(
            "SELECT p.PackageLocation AS destination, COUNT(*) AS completed_bookings,
                    COALESCE(SUM(b.NoOfPersons),0) AS travellers, COALESCE(SUM(b.TotalCost),0) AS booking_value_bdt
             FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
             WHERE b.status = 1 AND b.ToDate >= :s AND b.ToDate <= :e AND b.ToDate < :today
             GROUP BY p.PackageLocation
             ORDER BY completed_bookings DESC, travellers DESC, destination ASC
             LIMIT " . (int)$limit
        );
        $st->execute([':s' => $out['period']['start'], ':e' => $out['period']['end'], ':today' => date('Y-m-d')]);
        $out['figures'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$out['figures']) $out['notes'][] = 'No completed bookings ended in this period.';
        $out['ok'] = true;
        return $out;
    }

    /** @return array{created:int,confirmed:int,cancelled:int,pending:int} */
    private static function bookingCounts(PDO $dbh, string $start, string $end): array
    {
        $st = $dbh->prepare(
            "SELECT COUNT(*) AS created, COALESCE(SUM(status = 1),0) AS confirmed, COALESCE(SUM(status = 2),0) AS cancelled, COALESCE(SUM(status = 0),0) AS pending
             FROM tblbooking
             WHERE COALESCE(BookingDate, RegDate) >= :s AND COALESCE(BookingDate, RegDate) < DATE_ADD(:e, INTERVAL 1 DAY)"
        );
        $st->execute([':s' => $start, ':e' => $end]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['created' => (int)($r['created'] ?? 0), 'confirmed' => (int)($r['confirmed'] ?? 0), 'cancelled' => (int)($r['cancelled'] ?? 0), 'pending' => (int)($r['pending'] ?? 0)];
    }

    private static function bookingsSummary(PDO $dbh, array $out): array
    {
        $s = new DateTimeImmutable($out['period']['start']); $e = new DateTimeImmutable($out['period']['end']);
        $days = (int)$s->diff($e)->days + 1;
        $pe = $s->modify('-1 day'); $ps = $pe->modify('-' . ($days - 1) . ' days');
        $cur = self::bookingCounts($dbh, $s->format('Y-m-d'), $e->format('Y-m-d'));
        $prev = self::bookingCounts($dbh, $ps->format('Y-m-d'), $pe->format('Y-m-d'));
        $rate = fn(array $c) => $c['created'] > 0 ? round($c['confirmed'] / $c['created'] * 100, 1) : null;
        $out['definition'] = 'Bookings created = rows registered in the period (BookingDate, else RegDate). Conversion proxy = confirmed bookings / bookings created, as of now. The site does not record visits, so this is booking-to-confirmation conversion, not visitor-to-booking conversion. The previous period is the same number of days immediately before.';
        $out['figures'] = [
            ['period' => 'selected (' . $s->format('Y-m-d') . ' to ' . $e->format('Y-m-d') . ')', 'created' => $cur['created'], 'confirmed' => $cur['confirmed'], 'cancelled' => $cur['cancelled'], 'pending' => $cur['pending'], 'conversion_proxy_pct' => $rate($cur)],
            ['period' => 'previous (' . $ps->format('Y-m-d') . ' to ' . $pe->format('Y-m-d') . ')', 'created' => $prev['created'], 'confirmed' => $prev['confirmed'], 'cancelled' => $prev['cancelled'], 'pending' => $prev['pending'], 'conversion_proxy_pct' => $rate($prev)],
        ];
        if ($cur['created'] === 0) $out['notes'][] = 'No bookings were created in the selected period.';
        $out['ok'] = true;
        return $out;
    }

    private static function supplierCancellations(PDO $dbh, array $out, int $limit): array
    {
        $out['definition'] = 'Bookings registered in the period for packages owned by a supplier. Cancellation rate = cancelled (status 2) / total. Cancellations include ones the customer requested, so the by-customer count is shown separately. A supplier is flagged elevated at ' . (int)(self::ELEVATED_RATE * 100) . '% or more with at least ' . self::MIN_SAMPLE . ' bookings; small samples are noisy and a flag is a prompt to review, not a finding of fault.';
        if (!self::tableExists($dbh, 'tbl_suppliers')) { $out['error'] = 'The supplier tables are not available.'; return $out; }
        $st = $dbh->prepare(
            "SELECT s.SupplierId AS supplier_id, s.BusinessName AS supplier, COUNT(*) AS bookings,
                    COALESCE(SUM(b.status = 2),0) AS cancelled,
                    COALESCE(SUM(b.status = 2 AND b.CancelledBy = 'u'),0) AS cancelled_by_customer
             FROM tblbooking b
             JOIN tbltourpackages p ON p.PackageId = b.PackageId
             JOIN tbl_suppliers s ON s.SupplierId = p.SupplierId
             WHERE COALESCE(b.BookingDate, b.RegDate) >= :s AND COALESCE(b.BookingDate, b.RegDate) < DATE_ADD(:e, INTERVAL 1 DAY)
             GROUP BY s.SupplierId, s.BusinessName
             HAVING COUNT(*) >= " . (int)self::MIN_SAMPLE . "
             ORDER BY (SUM(b.status = 2) / COUNT(*)) DESC, bookings DESC
             LIMIT " . (int)$limit
        );
        $st->execute([':s' => $out['period']['start'], ':e' => $out['period']['end']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['cancellation_rate_pct'] = round(((int)$r['cancelled'] / max(1, (int)$r['bookings'])) * 100, 1);
            $r['elevated'] = ((int)$r['cancelled'] / max(1, (int)$r['bookings'])) >= self::ELEVATED_RATE ? 'yes' : 'no';
        }
        unset($r);
        $out['figures'] = $rows;
        if (!$rows) $out['notes'][] = 'No supplier had at least ' . self::MIN_SAMPLE . ' bookings in this period.';
        $out['ok'] = true;
        return $out;
    }

    private static function unreconciled(PDO $dbh, array $out): array
    {
        $out['definition'] = 'Payment records that disagree with, or have not settled against, their booking, as of now (not limited to the period): (1) payments still pending after 60 minutes; (2) a paid payment whose booking is not marked paid; (3) a booking marked paid with no paid payment row (normal for bookings made before the payment ledger existed); (4) webhook events that failed, were rejected, or sat unprocessed for over 10 minutes.';
        if (!self::tableExists($dbh, 'tblpayments')) { $out['error'] = 'The payment tables are not available.'; return $out; }
        $one = function (string $sql) use ($dbh): array {
            $r = $dbh->query($sql)->fetch(PDO::FETCH_NUM);
            return [(int)($r[0] ?? 0), (int)($r[1] ?? 0)];
        };
        [$n1, $a1] = $one("SELECT COUNT(*), COALESCE(SUM(AmountMinor),0) FROM tblpayments WHERE Status = 'pending' AND CreatedAt < DATE_SUB(NOW(), INTERVAL 60 MINUTE)");
        [$n2, $a2] = $one("SELECT COUNT(*), COALESCE(SUM(p.AmountMinor),0) FROM tblpayments p JOIN tblbooking b ON b.BookingId = p.BookingId WHERE p.Status = 'paid' AND (b.PaymentStatus IS NULL OR b.PaymentStatus <> 'paid')");
        [$n3, $a3] = $one("SELECT COUNT(*), COALESCE(SUM(b.TotalCost),0) * 100 FROM tblbooking b WHERE b.PaymentStatus = 'paid' AND NOT EXISTS (SELECT 1 FROM tblpayments p WHERE p.BookingId = b.BookingId AND p.Status IN ('paid','partially_refunded','refunded'))");
        $n4 = 0;
        if (self::tableExists($dbh, 'tblpayment_events')) {
            $n4 = (int)$dbh->query("SELECT COUNT(*) FROM tblpayment_events WHERE ProcessingStatus IN ('failed','rejected') OR (ProcessingStatus = 'received' AND ReceivedAt < DATE_SUB(NOW(), INTERVAL 10 MINUTE))")->fetchColumn();
        }
        $out['figures'] = [
            ['check' => 'Payments pending over 60 min', 'count' => $n1, 'amount_bdt' => round($a1 / 100, 2)],
            ['check' => 'Paid payment, booking not marked paid', 'count' => $n2, 'amount_bdt' => round($a2 / 100, 2)],
            ['check' => 'Booking marked paid, no payment row', 'count' => $n3, 'amount_bdt' => round($a3 / 100, 2)],
            ['check' => 'Webhook events failed / rejected / stale', 'count' => $n4, 'amount_bdt' => null],
        ];
        $out['notes'][] = 'Check 3 includes older bookings that were marked paid before the payment ledger existed; it is a review list, not a count of errors.';
        $out['ok'] = true;
        return $out;
    }

    private static function definitionsResult(PDO $dbh, array $out): array
    {
        $out['definition'] = 'How this system defines each revenue figure. The figures below are for the selected period.';
        $s = $out['period']['start']; $e = $out['period']['end'];
        $fig = [];
        $st = $dbh->prepare("SELECT COALESCE(SUM(TotalCost),0) FROM tblbooking WHERE status IN (0,1) AND COALESCE(BookingDate, RegDate) >= :s AND COALESCE(BookingDate, RegDate) < DATE_ADD(:e, INTERVAL 1 DAY)");
        $st->execute([':s' => $s, ':e' => $e]);
        $fig['Gross booking value'] = (float)$st->fetchColumn();
        if (self::tableExists($dbh, 'tblpayments')) {
            $st = $dbh->prepare("SELECT COALESCE(SUM(AmountMinor),0) FROM tblpayments WHERE Status IN ('paid','partially_refunded','refunded') AND CreatedAt >= :s AND CreatedAt < DATE_ADD(:e, INTERVAL 1 DAY)");
            $st->execute([':s' => $s, ':e' => $e]);
            $fig['Collected revenue'] = round(((int)$st->fetchColumn()) / 100, 2);
        }
        if (self::tableExists($dbh, 'tblpayment_refunds')) {
            $st = $dbh->prepare("SELECT COALESCE(SUM(AmountMinor),0) FROM tblpayment_refunds WHERE Status = 'processed' AND ProcessedAt >= :s AND ProcessedAt < DATE_ADD(:e, INTERVAL 1 DAY)");
            $st->execute([':s' => $s, ':e' => $e]);
            $fig['Refunds'] = round(((int)$st->fetchColumn()) / 100, 2);
        }
        if (isset($fig['Collected revenue'], $fig['Refunds'])) $fig['Net collected'] = round($fig['Collected revenue'] - $fig['Refunds'], 2);
        if (self::tableExists($dbh, 'tbl_supplier_settlements')) {
            $st = $dbh->prepare("SELECT COALESCE(SUM(CommissionAmount),0) FROM tbl_supplier_settlements WHERE Status <> 'draft' AND CreatedAt >= :s AND CreatedAt < DATE_ADD(:e, INTERVAL 1 DAY)");
            $st->execute([':s' => $s, ':e' => $e]);
            $fig['Platform commission'] = round((float)$st->fetchColumn(), 2);
        }
        $defs = self::definitions();
        foreach ($defs as $name => $text) {
            $out['figures'][] = ['metric' => $name, 'value_bdt' => $fig[$name] ?? null, 'definition' => $text];
        }
        $out['notes'][] = 'These four figures answer different questions and will not match each other: gross is commitments, collected is cash in, refunds is cash out, commission is the platform\'s share. Values are BDT.';
        $out['ok'] = true;
        return $out;
    }

    // ───────────────────────── question → answer ─────────────────────────

    /**
     * @return array{ok:bool,matched:bool,question:string,result:?array,summary:string}
     */
    public static function answer(PDO $dbh, string $question, ?DateTimeImmutable $now = null): array
    {
        $question = mb_substr(trim($question), 0, 300, 'UTF-8');
        $m = self::matchIntent($question, $now);
        if ($m['template'] === null) {
            return ['ok' => false, 'matched' => false, 'question' => $question, 'result' => null,
                'summary' => 'I can only answer from a fixed set of approved metrics: ' . implode('; ', array_values(self::TEMPLATES)) . '. Try rephrasing, for example "Which destinations had the most completed bookings last month?"'];
        }
        $res = self::run($dbh, $m['template'], $m['period'], $m['limit']);
        return ['ok' => $res['ok'], 'matched' => true, 'question' => $question, 'result' => $res, 'summary' => self::summarize($res)];
    }

    /** Deterministic plain-language summary of already-computed figures. */
    public static function summarize(array $res): string
    {
        if (empty($res['ok'])) return ($res['title'] ?? 'Metric') . ': ' . ($res['error'] ?? 'not available.');
        $p = $res['period']['label'] ?? '';
        $f = $res['figures'] ?? [];
        switch ($res['template']) {
            case self::T_TOP_DEST:
                if (!$f) return "No completed bookings ended in {$p}.";
                $parts = [];
                foreach ($f as $i => $r) $parts[] = ($i + 1) . '. ' . ($r['destination'] ?: '(no location)') . ' - ' . $r['completed_bookings'] . ' completed booking(s), ' . $r['travellers'] . ' traveller(s)';
                return "Top destinations by completed bookings, {$p}: " . implode('; ', $parts) . '.';
            case self::T_BOOKINGS:
                [$c, $v] = [$f[0], $f[1]];
                $d = $v['created'] > 0 ? round(($c['created'] - $v['created']) / $v['created'] * 100, 1) : null;
                return "{$p}: {$c['created']} bookings created, {$c['confirmed']} confirmed, {$c['cancelled']} cancelled, {$c['pending']} pending"
                    . ($c['conversion_proxy_pct'] !== null ? " (conversion proxy {$c['conversion_proxy_pct']}%)" : '')
                    . ($d !== null ? "; bookings created changed {$d}% versus the previous period ({$v['created']})." : '; the previous period had no bookings to compare with.');
            case self::T_SUPPLIERS:
                if (!$f) return "No supplier had enough bookings to compare in {$p}.";
                $flag = array_filter($f, fn($r) => $r['elevated'] === 'yes');
                $parts = [];
                foreach ($f as $r) $parts[] = $r['supplier'] . ' ' . $r['cancellation_rate_pct'] . '% (' . $r['cancelled'] . '/' . $r['bookings'] . ')';
                return "Supplier cancellation rates, {$p}: " . implode('; ', $parts) . '. ' . count($flag) . ' at or above the ' . (int)(self::ELEVATED_RATE * 100) . '% review threshold.';
            case self::T_UNRECON:
                $parts = [];
                foreach ($f as $r) $parts[] = $r['check'] . ': ' . $r['count'];
                return 'Unreconciled payments right now - ' . implode('; ', $parts) . '.';
            case self::T_DEFINITIONS:
                $parts = [];
                foreach ($f as $r) $parts[] = $r['metric'] . ($r['value_bdt'] !== null ? ' = ' . number_format((float)$r['value_bdt'], 2) . ' BDT' : ' (not available)');
                return "For {$p}: " . implode('; ', $parts) . '.';
        }
        return '';
    }
}
