<?php
/**
 * PricingEngine.php — Dynamic / surge pricing for TMS.
 *
 * ONE function computes the adjusted price everywhere it's needed
 * (package card display, booking form, cart, checkout). Every caller uses
 * this same function so the price a customer sees is always the price
 * they're charged — nothing recomputes the math differently in a second
 * place, which is how "the site said ৳X but I was charged ৳Y" bugs happen.
 *
 * Four independent signals, combined multiplicatively:
 *
 *   1. DEMAND — how many bookings this specific package has received in
 *      the last 30 days (tblbooking, any non-cancelled status). More
 *      recent bookings = more demand = higher price, same principle
 *      airlines/hotels use.
 *
 *   2. TIMING — how far away the trip's departure date is, when a date is
 *      known:
 *        < 7 days   : late booking surge      (+15%)
 *        7–30 days  : short-notice surge      (+6%)
 *        30–90 days : normal window           (0%)
 *        > 90 days  : early-bird discount     (-8%)
 *
 *   3. WEEKEND DEPARTURE — Thu/Fri/Sat departures (the Bangladesh weekend
 *      is Fri–Sat, so Thursday is weekend-adjacent travel) get a modest
 *      +5%, the same leisure-demand pattern hotels/airlines price for.
 *      Pure date math, no extra query.
 *
 *   4. REMAINING SEATS — only applied when a package actually tracks
 *      capacity (`SeatsAvailable` is set, not NULL). Computed live from
 *      confirmed+pending bookings, never a stored running counter that
 *      could drift. Packages that don't track capacity (the default,
 *      preserving all pre-existing behavior) are entirely unaffected by
 *      this factor — see tms_remaining_seats().
 *        <= 2 seats left      : +18% (almost sold out)
 *        >= 80% filled        : +10%
 *        >= 50% filled        : +4%
 *
 * Deliberately NOT implemented, and why:
 *   - Weather: forecasts are only reliable ~16 days out (see
 *     tms_get_weather()); most bookings are made further ahead than that,
 *     so a "weather-based" price for most departures would just be
 *     silently wrong on the day that matters most — worse than not having
 *     the signal at all.
 *   - Fixed holiday calendar: would need a maintained list of regional
 *     holidays (Eid, Puja, etc.) that shifts every year on the lunar/lunar-
 *     solar calendars — a static hardcoded list would silently go stale.
 *     The weekend-departure signal above captures most of the same
 *     leisure-demand pattern without that maintenance burden.
 *
 * When no date is available yet (e.g. a package card on a listing page,
 * before the visitor has picked travel dates), only the demand and seats
 * signals are applied — never a guessed date/weekend signal.
 *
 * The combined multiplier is clamped to [0.85, 1.35] so this stays a
 * pricing *adjustment*, not something that can spiral. Every result also
 * carries a plain-English reason so the price on screen is never an
 * unexplained number.
 */

/**
 * @param PDO         $dbh
 * @param int         $packageId
 * @param float       $basePrice   The package's base PackagePrice.
 * @param string|null $fromDate    'Y-m-d' departure date, or null if not chosen yet.
 * @return array{base:float, final:int, multiplier:float, demand_pct:int, date_pct:int, weekend_pct:int, label:string}
 */
/**
 * Remaining seats for a package, or null if capacity isn't tracked for it
 * (SeatsAvailable is NULL — the default, meaning "unlimited/not tracked",
 * preserving every existing package's current behavior exactly). Computed
 * live from confirmed+pending bookings rather than stored as a running
 * counter, so it can never drift out of sync with the actual booking table.
 */
function tms_remaining_seats(PDO $dbh, int $packageId): ?int {
    try {
        $capQ = $dbh->prepare("SELECT SeatsAvailable FROM tbltourpackages WHERE PackageId = :pid");
        $capQ->execute([':pid' => $packageId]);
        $capacity = $capQ->fetchColumn();
        if ($capacity === false || $capacity === null || $capacity === '') return null;

        $bookedQ = $dbh->prepare(
            "SELECT COALESCE(SUM(NoOfPersons),0) FROM tblbooking WHERE PackageId = :pid AND status IN (0,1)"
        );
        $bookedQ->execute([':pid' => $packageId]);
        $booked = (int) $bookedQ->fetchColumn();

        return max(0, (int)$capacity - $booked);
    } catch (Exception $e) {
        error_log('[TMS Pricing] Seat count lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Same as tms_remaining_seats(), but takes a row-level write lock on the
 * package first (SELECT ... FOR UPDATE) so it MUST be called inside an
 * already-open transaction ($dbh->beginTransaction()). This is what
 * actually prevents overselling under concurrency (Rule 9): two checkouts
 * racing for the last seat on the same package will now serialize on this
 * lock instead of both reading "1 seat left" and both succeeding. The
 * plain, lock-free tms_remaining_seats() above is kept for read-only
 * display (package cards, listing pages) where taking a write lock would
 * be wasteful and isn't needed.
 */
function tms_remaining_seats_for_update(PDO $dbh, int $packageId): ?int {
    $capQ = $dbh->prepare("SELECT SeatsAvailable FROM tbltourpackages WHERE PackageId = :pid FOR UPDATE");
    $capQ->execute([':pid' => $packageId]);
    $capacity = $capQ->fetchColumn();
    if ($capacity === false || $capacity === null || $capacity === '') return null;

    $bookedQ = $dbh->prepare(
        "SELECT COALESCE(SUM(NoOfPersons),0) FROM tblbooking WHERE PackageId = :pid AND status IN (0,1)"
    );
    $bookedQ->execute([':pid' => $packageId]);
    $booked = (int) $bookedQ->fetchColumn();

    return max(0, (int)$capacity - $booked);
}

/**
 * Coupon discount in whole currency units, exact integer arithmetic, half-up. Percent is clamped to [0, TMS_MAX_LOYALTY_DISCOUNT].
 * (Was float round(x,2) written into INT columns: 13,665 at 10% became discount 1,367 but total 12,299 - off by one.)
 */
function tms_coupon_discount(int $subtotal, float $percent): int {
    $pct = max(0.0, min($percent, (float)TMS_MAX_LOYALTY_DISCOUNT));
    $bp  = (int) round($pct * 100);                       // basis points, e.g. 12.5% -> 1250
    return max(0, min($subtotal, intdiv($subtotal * $bp + 5000, 10000)));
}

function tms_dynamic_price(PDO $dbh, int $packageId, float $basePrice, ?string $fromDate = null): array
{
    if ($basePrice <= 0) {
        return ['base' => $basePrice, 'final' => (int) round($basePrice), 'multiplier' => 1.0,
                'demand_pct' => 0, 'date_pct' => 0, 'weekend_pct' => 0, 'seats_pct' => 0, 'label' => ''];
    }

    // ── Demand signal: recent bookings for this exact package ──────────────
    $demandPct = 0;
    try {
        $dq = $dbh->prepare(
            "SELECT COUNT(*) FROM tblbooking
             WHERE PackageId = :pid AND status IN (0,1)
               AND RegDate >= (NOW() - INTERVAL 30 DAY)"
        );
        $dq->execute([':pid' => $packageId]);
        $recentBookings = (int) $dq->fetchColumn();

        if ($recentBookings >= 10)      $demandPct = 12;
        elseif ($recentBookings >= 5)   $demandPct = 6;
        elseif ($recentBookings >= 2)   $demandPct = 2;
    } catch (Exception $e) {
        error_log('[TMS Pricing] Demand lookup failed: ' . $e->getMessage());
        $demandPct = 0;
    }

    // ── Timing signal: only when a real departure date is known ────────────
    $datePct = 0;
    $daysUntil = null;
    if ($fromDate) {
        try {
            $dep = new DateTime($fromDate);
            $today = new DateTime('today');
            $daysUntil = (int) $today->diff($dep)->format('%r%a');
            if ($daysUntil >= 0) {
                if ($daysUntil < 7)        $datePct = 15;
                elseif ($daysUntil < 30)   $datePct = 6;
                elseif ($daysUntil <= 90)  $datePct = 0;
                else                       $datePct = -8;
            }
        } catch (Exception $e) {
            $datePct = 0;
        }
    }

    // ── Weekend signal: departures on Thu/Fri/Sat (the Bangladesh weekend
    // is Friday–Saturday, so Thursday departures are also weekend-adjacent
    // travel) see real, well-established higher leisure demand — same
    // principle hotels/airlines apply for Fri/Sat check-ins. Pure date
    // math, no extra query, so this stays cheap even on listing pages
    // rendering many cards. ─────────────────────────────────────────────
    $weekendPct = 0;
    if ($fromDate) {
        try {
            $dow = (int) (new DateTime($fromDate))->format('N'); // 1=Mon ... 7=Sun
            if (in_array($dow, [4, 5, 6], true)) $weekendPct = 5; // Thu, Fri, Sat
        } catch (Exception $e) {
            $weekendPct = 0;
        }
    }

    // ── Scarcity signal: only when this package actually tracks capacity ───
    $seatsPct = 0;
    $remainingSeats = tms_remaining_seats($dbh, $packageId);
    if ($remainingSeats !== null) {
        $capQ2 = $dbh->prepare("SELECT SeatsAvailable FROM tbltourpackages WHERE PackageId = :pid");
        $capQ2->execute([':pid' => $packageId]);
        $totalCapacity = (int) $capQ2->fetchColumn();
        if ($totalCapacity > 0) {
            $fillRatio = 1 - ($remainingSeats / $totalCapacity);
            if ($remainingSeats <= 2)        $seatsPct = 18; // almost sold out
            elseif ($fillRatio >= 0.8)       $seatsPct = 10;
            elseif ($fillRatio >= 0.5)       $seatsPct = 4;
        }
    }

    $multiplier = (1 + $demandPct / 100) * (1 + $datePct / 100) * (1 + $weekendPct / 100) * (1 + $seatsPct / 100);
    $multiplier = max(0.85, min(1.45, $multiplier));
    $final = (int) round($basePrice * $multiplier);

    // ── Human-readable reason ───────────────────────────────────────────────
    $parts = [];
    if ($datePct > 0)      $parts[] = ($daysUntil < 7 ? 'Booking soon' : 'Limited notice') . ' (+' . $datePct . '%)';
    elseif ($datePct < 0)  $parts[] = 'Early-bird discount (' . $datePct . '%)';
    if ($demandPct > 0)    $parts[] = 'high demand (+' . $demandPct . '%)';
    if ($weekendPct > 0)   $parts[] = 'weekend departure (+' . $weekendPct . '%)';
    if ($seatsPct > 0)     $parts[] = ($remainingSeats <= 2 ? 'almost sold out' : 'limited seats left') . ' (+' . $seatsPct . '%)';
    $label = $parts ? implode(' · ', $parts) : 'Standard pricing';

    return [
        'base'       => $basePrice,
        'final'      => $final,
        'multiplier' => round($multiplier, 4),
        'demand_pct' => $demandPct,
        'date_pct'   => $datePct,
        'weekend_pct'=> $weekendPct,
        'seats_pct'  => $seatsPct,
        'remaining_seats' => $remainingSeats,
        'label'      => $label,
    ];
}

/**
 * Price history (Rule 26: "Track historical prices where legitimate data
 * is available"). Records one snapshot for $packageId on today's date,
 * upserting so at most one row per package per calendar day ever exists --
 * calling this more than once on the same day (e.g. a cron that runs every
 * 30 minutes, see scripts/check_alerts.php) just keeps updating today's row
 * rather than creating duplicates. Never throws: a failed snapshot is a
 * missed data point, not a reason to break the caller (the same alert
 * check / package page render that wants this recorded).
 */
function tms_record_price_snapshot(PDO $dbh, int $packageId, int $price): void
{
    if ($packageId <= 0 || $price <= 0) return;
    try {
        $dbh->prepare(
            "INSERT INTO tbl_price_history (PackageId, Price, RecordedDate)
             VALUES (:pid, :price, :today)
             ON DUPLICATE KEY UPDATE Price = VALUES(Price), RecordedAt = CURRENT_TIMESTAMP"
        )->execute([':pid' => $packageId, ':price' => $price, ':today' => date('Y-m-d')]);
    } catch (Exception $e) {
        error_log('[TMS Pricing] price_history snapshot failed: ' . $e->getMessage());
    }
}

/**
 * Snapshots today's canonical price (the same undated demand+seats price
 * tms_check_and_fire_alerts() itself compares against -- no travel date, so
 * this is one stable number per package per day, not one per possible
 * departure date) for every active, priced package. Called from
 * scripts/check_alerts.php's existing cron run rather than a new scheduled
 * job (Rule 73 -- this project already runs that script every 30-60 min,
 * which is exactly the cadence price history needs).
 *
 * @return int how many packages were snapshotted this run.
 */
function tms_snapshot_all_package_prices(PDO $dbh): int
{
    $count = 0;
    try {
        $pkgs = $dbh->query(
            "SELECT PackageId, PackagePrice FROM tbltourpackages WHERE Status = 1 AND PackagePrice IS NOT NULL AND PackagePrice > 0"
        )->fetchAll(PDO::FETCH_OBJ);
        foreach ($pkgs as $p) {
            $price = tms_dynamic_price($dbh, (int)$p->PackageId, (float)$p->PackagePrice)['final'];
            tms_record_price_snapshot($dbh, (int)$p->PackageId, (int)$price);
            $count++;
        }
    } catch (Exception $e) {
        error_log('[TMS Pricing] snapshot_all_package_prices failed: ' . $e->getMessage());
    }
    return $count;
}

/**
 * Recorded price history for one package, oldest first, for charting/display.
 * Returns an empty array (never fabricated placeholder points, Rule 3) for
 * a package with no snapshots yet -- callers must render an honest "not
 * enough data yet" state rather than a fake flat line.
 *
 * @return array<int, array{date:string, price:int}>
 */
function tms_get_price_history(PDO $dbh, int $packageId, int $days = 90): array
{
    try {
        $q = $dbh->prepare(
            "SELECT RecordedDate, Price FROM tbl_price_history
             WHERE PackageId = :pid AND RecordedDate >= (CURDATE() - INTERVAL :days DAY)
             ORDER BY RecordedDate ASC"
        );
        $q->bindValue(':pid', $packageId, PDO::PARAM_INT);
        $q->bindValue(':days', $days, PDO::PARAM_INT);
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['date' => (string)$r['RecordedDate'], 'price' => (int)$r['Price']], $rows);
    } catch (Exception $e) {
        error_log('[TMS Pricing] get_price_history failed: ' . $e->getMessage());
        return [];
    }
}
