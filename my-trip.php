<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
// ItineraryPlanner.php requires its own dependencies (AIClient, ExploreService,
// CrowdPredictor) internally with paths relative to its own directory -- no
// need to require them again here (same pattern api/ai.php uses).
require_once __DIR__ . '/includes/ai/ItineraryPlanner.php';
tms_require_user();

/**
 * my-trip.php — "MY TRIP" hub: the one place a traveler with a confirmed
 * booking can see everything about that trip in one screen, instead of
 * hunting across separate pages (tour-history.php, wallet.php,
 * expense-tracker.php, weather.php, ai-copilot.php, emergency.php...).
 *
 * This page deliberately does NOT duplicate any of those pages' own logic
 * (cancellation, modification, document upload, expense entry all still
 * live only in their original files) -- it links out to the real, working
 * feature for each, and surfaces the read-only facts about this specific
 * booking that make those links meaningful (dates, hotel, payment status).
 * The one piece of new functionality is the itinerary section, which wires
 * up ItineraryPlanner::getByBookingId() -- previously dead code, since
 * nothing anywhere saved an itinerary against a real booking_id before
 * this (see includes/ai/ItineraryPlanner.php's saveItinerary()).
 */

$email = $_SESSION['login'];
$bookingId = tms_int($_GET['id'] ?? 0, 1);

if ($bookingId < 1) {
    header('Location: tour-history.php');
    exit;
}

// IDOR guard: only this booking's own owner may view it -- same pattern as
// pay-now.php/payment.php/PaymentService::confirmSandboxPayment().
$sqlWithExtras = "SELECT b.BookingId, b.PackageId, b.FromDate, b.ToDate, b.status, b.NoOfPersons,
        b.TotalCost, b.VerificationCode, b.PaymentStatus, b.RefundStatus, b.RegDate,
        p.PackageName, p.PackageLocation, p.PackageImage, p.PackageType,
        p.HotelName, p.HotelRating, p.MealPlan, p.TransportMode
    FROM tblbooking b
    JOIN tbltourpackages p ON p.PackageId = b.PackageId
    WHERE b.BookingId = :bid AND b.UserEmail = :email
    LIMIT 1";
// Same defensive fallback as tour-history.php: PaymentStatus/HotelName/etc.
// are all self-healing columns (see includes/config.php) so they should
// already exist on any real request, but if that ever silently failed for
// some reason, fall back to the pre-payment/pre-hotel-fields column set
// rather than taking this whole page down with a PDO exception.
$sqlBasic = "SELECT b.BookingId, b.PackageId, b.FromDate, b.ToDate, b.status, b.NoOfPersons,
        b.TotalCost, b.VerificationCode, b.RegDate,
        p.PackageName, p.PackageLocation, p.PackageImage, p.PackageType
    FROM tblbooking b
    JOIN tbltourpackages p ON p.PackageId = b.PackageId
    WHERE b.BookingId = :bid AND b.UserEmail = :email
    LIMIT 1";
try {
    $q = $dbh->prepare($sqlWithExtras);
    $q->execute([':bid' => $bookingId, ':email' => $email]);
    $trip = $q->fetch(PDO::FETCH_OBJ);
} catch (PDOException $e) {
    error_log('[TMS] my-trip.php: extra columns not available yet -- falling back: ' . $e->getMessage());
    $q = $dbh->prepare($sqlBasic);
    $q->execute([':bid' => $bookingId, ':email' => $email]);
    $trip = $q->fetch(PDO::FETCH_OBJ);
}

if (!$trip) {
    // Either this booking doesn't exist, or it isn't this user's -- either
    // way, the honest response is the same "not found", never a distinct
    // "that's not yours" message that would confirm a booking ID exists.
    $notFound = true;
} else {
    $notFound = false;

    // Travel Alert System (master prompt item 17): the schema and the
    // admin-side management UI (admin/ai-dashboard.php) already existed,
    // but nothing customer-facing ever read from tbl_safety_alerts -- a
    // traveler had no way to see a platform-issued alert for their own
    // destination. This is the one customer-facing surface added for it
    // (Rule 73: one well-placed surface, not scattered across every page).
    // Destination text is free-form on both sides (an admin might type
    // "Bali" while a package says "Bali, Indonesia"), so this matches
    // either string containing the other rather than requiring an exact
    // match -- simple substring matching, not a real geocoding system,
    // which would be overkill for what this is.
    $safetyAlerts = [];
    if (!empty($trip->PackageLocation)) {
        try {
            $stmt = $dbh->prepare(
                "SELECT * FROM tbl_safety_alerts
                 WHERE active = 1
                 AND (:loc1 LIKE CONCAT('%', destination, '%') OR destination LIKE CONCAT('%', :loc2, '%'))
                 ORDER BY FIELD(severity, 'critical', 'warning', 'info'), created_at DESC"
            );
            $stmt->execute([':loc1' => $trip->PackageLocation, ':loc2' => $trip->PackageLocation]);
            $safetyAlerts = $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log('[TMS] my-trip.php: safety alert lookup failed: ' . $e->getMessage());
        }
    }
    $payStatus = $trip->PaymentStatus ?? 'unpaid';
    $payBadge = ['unpaid'=>'default','pending'=>'warning','paid'=>'success','failed'=>'danger','refunded'=>'default','partially_refunded'=>'warning'][$payStatus] ?? 'default';

    $statusConfig = [
        0 => ['label' => 'Pending Confirmation', 'class' => 'warning'],
        1 => ['label' => 'Confirmed', 'class' => 'success'],
        2 => ['label' => 'Cancelled', 'class' => 'danger'],
    ];
    $sc = $statusConfig[$trip->status] ?? ['label' => 'Unknown', 'class' => 'default'];

    // Pre-trip / during-trip / post-trip phase (Rule 68) -- pure date math
    // against this booking's own real dates, nothing inferred or invented.
    $today = new DateTime('today');
    $fromDate = new DateTime($trip->FromDate);
    $toDate = new DateTime($trip->ToDate);
    if ($trip->status == 2) {
        $phase = 'cancelled';
    } elseif ($today < $fromDate) {
        $daysUntil = (int)$today->diff($fromDate)->format('%a');
        $phase = 'before';
    } elseif ($today >= $fromDate && $today <= $toDate) {
        $phase = 'during';
    } else {
        $phase = 'after';
    }

    $planner = new ItineraryPlanner($dbh);
    $itinerary = $planner->getByBookingId($bookingId);
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | My Trip</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.mt-hero{background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);color:#fff;padding:40px 0;margin-bottom:30px;}
.mt-hero h2{margin:0 0 6px;font-weight:800;}
.mt-hero .mt-meta{opacity:.9;font-size:14px;}
.mt-phase{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;margin-right:8px;}
.mt-phase-before{background:#dbeafe;color:#1e40af;}
/* NEW (Round 20, spec item 33 -- Dark Mode): "during" and "cancelled" are
   genuine success/danger states, not just this page's own color choice, so
   they now pull from the shared design tokens (css/modern-theme.css's
   :root + includes/header.php's html[data-theme="dark"] override) instead
   of hardcoding the same light-mode-only hex pair every other page was
   independently hardcoding too. "before"/"after" are neutral/informational
   (upcoming vs. completed), not a success/warning/danger state, so they're
   left as this page's own colors -- the master prompt's token list doesn't
   include an "info" category to route them through. */
.mt-phase-during{background:var(--success-bg);color:var(--success-text);}
.mt-phase-after{background:#f1f5f9;color:#475569;}
.mt-phase-cancelled{background:var(--danger-bg);color:var(--danger-text);}
.mt-card{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);border:1px solid #f0f0f0;padding:20px;margin-bottom:20px;}
.mt-card h4{margin:0 0 14px;font-weight:700;font-size:16px;}
.mt-card h4 i{color:var(--secondary);margin-right:6px;}
.mt-links{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;}
.mt-link{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px 10px;border-radius:10px;background:#f8f9fa;border:1px solid #eee;text-decoration:none;color:#333;font-size:13px;font-weight:600;text-align:center;transition:.2s;}
.mt-link:hover{background:var(--secondary);color:#fff;text-decoration:none;}
.mt-link i{font-size:22px;}
.mt-fact-row{display:flex;gap:8px;margin-bottom:10px;font-size:14px;color:#555;}
.mt-fact-row i{color:var(--secondary);width:18px;flex-shrink:0;margin-top:2px;}
html[data-theme="dark"] .mt-fact-row{color:var(--text-muted) !important;}
.mt-day{border-left:3px solid var(--secondary);padding:10px 0 10px 16px;margin-bottom:14px;}
.mt-day h5{margin:0 0 6px;font-weight:700;}
.mt-sched-item{font-size:13px;color:#555;margin-bottom:5px;}
html[data-theme="dark"] .mt-sched-item{color:var(--text-muted) !important;}
.mt-ai-disclosure{font-size:12px;color:#888;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;}
.mt-empty{text-align:center;padding:40px 20px;color:#aaa;}
.mt-empty i{font-size:44px;display:block;margin-bottom:14px;color:#ddd;}
.mt-generate-btn{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer;}
.mt-generate-btn:disabled{opacity:.6;cursor:not-allowed;}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<?php if ($notFound): ?>
<div class="container" style="padding:80px 0;text-align:center;">
    <i class="fa fa-exclamation-circle" style="font-size:56px;color:#ddd;"></i>
    <h3 style="margin-top:16px;">Trip not found</h3>
    <p style="color:#888;">We couldn't find that booking on your account.</p>
    <a href="tour-history.php" class="btn btn-primary" style="margin-top:10px;">Back to My Bookings</a>
</div>
<?php else: ?>

<div class="mt-hero">
    <div class="container">
        <span class="mt-phase mt-phase-<?php echo $phase; ?>">
            <?php echo ['before' => 'Upcoming Trip', 'during' => 'Happening Now', 'after' => 'Completed', 'cancelled' => 'Cancelled'][$phase]; ?>
        </span>
        <span class="label label-<?php echo $sc['class']; ?>" style="font-size:12px;padding:5px 10px;"><?php echo $sc['label']; ?></span>
        <span class="label label-<?php echo $payBadge; ?>" style="font-size:12px;padding:5px 10px;">
            <i class="fa fa-credit-card"></i> <?php echo ucfirst(str_replace('_',' ', $payStatus)); ?>
        </span>
        <h2><?php echo htmlspecialchars($trip->PackageName, ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="mt-meta">
            <i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($trip->PackageLocation, ENT_QUOTES, 'UTF-8'); ?>
            &nbsp;·&nbsp; <i class="fa fa-calendar"></i>
            <?php echo (new DateTime($trip->FromDate))->format('d M Y'); ?> — <?php echo (new DateTime($trip->ToDate))->format('d M Y'); ?>
            &nbsp;·&nbsp; <i class="fa fa-users"></i> <?php echo (int)$trip->NoOfPersons; ?> traveler<?php echo ((int)$trip->NoOfPersons === 1 ? '' : 's'); ?>
            <?php if ($phase === 'before'): ?>
            &nbsp;·&nbsp; <strong><?php echo $daysUntil; ?> day<?php echo $daysUntil === 1 ? '' : 's'; ?> to go</strong>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($safetyAlerts)): ?>
<div class="container" style="margin-top:20px;">
    <?php foreach ($safetyAlerts as $alert):
        $sevStyle = [
            'critical' => ['bg' => 'var(--danger-bg)', 'text' => 'var(--danger-text)', 'icon' => 'exclamation-triangle'],
            'warning'  => ['bg' => 'var(--warning-bg)', 'text' => 'var(--warning-text)', 'icon' => 'exclamation-circle'],
            'info'     => ['bg' => '#dbeafe', 'text' => '#1e40af', 'icon' => 'info-circle'],
        ][$alert->severity] ?? ['bg' => '#f1f5f9', 'text' => '#475569', 'icon' => 'bell'];
    ?>
    <div style="background:<?php echo $sevStyle['bg']; ?>;color:<?php echo $sevStyle['text']; ?>;border-radius:10px;padding:14px 18px;margin-bottom:12px;display:flex;gap:12px;align-items:flex-start;">
        <i class="fa fa-<?php echo $sevStyle['icon']; ?>" style="font-size:18px;margin-top:2px;"></i>
        <div>
            <strong><?php echo htmlspecialchars(ucfirst($alert->alert_type), ENT_QUOTES, 'UTF-8'); ?> alert — <?php echo htmlspecialchars($alert->destination, ENT_QUOTES, 'UTF-8'); ?></strong>
            <div style="font-size:13.5px;margin-top:3px;"><?php echo htmlspecialchars($alert->message, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="container" style="margin-bottom:60px;">
    <div class="row">
        <div class="col-md-8">

            <div class="mt-card">
                <h4><i class="fa fa-info-circle"></i> Trip Details</h4>
                <div class="mt-fact-row"><i class="fa fa-hashtag"></i> Booking #BK<?php echo (int)$trip->BookingId; ?> — booked <?php echo htmlspecialchars($trip->RegDate, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-fact-row"><i class="fa fa-money"></i> Total cost: ৳<?php echo number_format((float)$trip->TotalCost, 2); ?></div>
                <?php if (!empty($trip->HotelName)): ?>
                <div class="mt-fact-row"><i class="fa fa-bed"></i> <?php echo htmlspecialchars($trip->HotelName, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($trip->HotelRating)): ?> (<?php echo htmlspecialchars((string)$trip->HotelRating, ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-star" style="font-size:11px;"></i>)<?php endif; ?><?php if (!empty($trip->MealPlan)): ?> — <?php echo htmlspecialchars($trip->MealPlan, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                <?php else: ?>
                <div class="mt-fact-row"><i class="fa fa-bed"></i> Accommodation details not specified for this package.</div>
                <?php endif; ?>
                <?php if (!empty($trip->TransportMode)): ?>
                <div class="mt-fact-row"><i class="fa fa-bus"></i> Transport: <?php echo htmlspecialchars($trip->TransportMode, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (!empty($trip->VerificationCode) && $trip->status != 2): ?>
                <div class="mt-fact-row"><i class="fa fa-qrcode"></i> E-ticket verification code: <code><?php echo htmlspecialchars($trip->VerificationCode, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <?php endif; ?>
            </div>

            <?php // SECURITY (round 20): api/ai.php's itinerary action now requires a CSRF
            // token. The page's only other tms_csrf_field() call lives inside the Pay
            // Now form below, which doesn't render once a trip is paid -- meaning a
            // paid trip with no itinerary yet would have no token to send and every
            // "Generate an AI Itinerary" click would 403. This one is unconditional. ?>
            <?php tms_csrf_field(); ?>
            <div class="mt-card" id="mtItineraryCard">
                <h4><i class="fa fa-calendar-check-o"></i> Day-by-Day Itinerary</h4>
                <?php if ($itinerary && !empty($itinerary['days'])): ?>
                    <div class="mt-ai-disclosure">
                        <i class="fa fa-info-circle"></i> This is an AI-generated trip plan — a suggestion to help you plan, not a confirmed itinerary booked or verified by TMS staff. Hotel/restaurant names and prices here are AI suggestions, not part of your actual booking.
                    </div>
                    <?php foreach ($itinerary['days'] as $day): ?>
                    <div class="mt-day">
                        <h5>Day <?php echo (int)($day['day'] ?? 0); ?><?php if (!empty($day['title'])): ?> — <?php echo htmlspecialchars($day['title'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></h5>
                        <?php foreach (($day['schedule'] ?? []) as $item): ?>
                        <div class="mt-sched-item">
                            <strong><?php echo htmlspecialchars($item['time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong> —
                            <?php echo htmlspecialchars($item['activity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($item['location'])): ?> @ <?php echo htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="mt-empty">
                        <i class="fa fa-map-o"></i>
                        <p>No itinerary generated for this trip yet.</p>
                        <?php if ($trip->status != 2): ?>
                        <button type="button" class="mt-generate-btn" id="mtGenerateBtn" onclick="mtGenerateItinerary()">
                            <i class="fa fa-magic"></i> Generate an AI Itinerary
                        </button>
                        <p style="font-size:12px;color:#aaa;margin-top:10px;">Uses your real trip dates and destination. Takes about 15–30 seconds.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <div class="col-md-4">
            <div class="mt-card">
                <h4><i class="fa fa-th-large"></i> Everything for this trip</h4>
                <div class="mt-links">
                    <a href="weather.php?dest=<?php echo (int)$trip->PackageId; ?>" class="mt-link"><i class="fa fa-cloud"></i> Weather</a>
                    <a href="tour-map.php" class="mt-link"><i class="fa fa-map"></i> Map</a>
                    <?php if ($trip->status == 1): ?>
                    <a href="ticket.php?bkid=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-download"></i> E-Ticket</a>
                    <?php endif; ?>
                    <a href="expense-tracker.php?booking=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-money"></i> Expenses</a>
                    <a href="wallet.php" class="mt-link"><i class="fa fa-briefcase"></i> Documents</a>
                    <a href="emergency.php" class="mt-link"><i class="fa fa-phone"></i> Emergency</a>
                    <a href="ai-copilot.php?booking_id=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-comments"></i> AI Copilot</a>
                    <a href="tour-history.php" class="mt-link"><i class="fa fa-cog"></i> Manage Booking</a>
                </div>
            </div>
            <?php if (($trip->PaymentStatus ?? 'unpaid') === 'unpaid' && $trip->status != 2): ?>
            <div class="mt-card">
                <h4><i class="fa fa-credit-card"></i> Payment</h4>
                <p style="font-size:13px;color:#888;">This trip hasn't been paid for yet.</p>
                <form method="POST" action="pay-now.php">
                    <?php tms_csrf_field(); ?>
                    <input type="hidden" name="booking_id" value="<?php echo (int)$trip->BookingId; ?>">
                    <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-flask"></i> Pay Now (Sandbox)</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function mtGenerateItinerary() {
    var btn = document.getElementById('mtGenerateBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating…';

    var body = new URLSearchParams({
        booking_id: <?php echo (int)$trip->BookingId; ?>,
        destination: <?php echo json_encode($trip->PackageLocation, JSON_UNESCAPED_UNICODE); ?>,
        start_date: <?php echo json_encode((new DateTime($trip->FromDate))->format('Y-m-d')); ?>,
        duration: <?php echo max(1, (new DateTime($trip->FromDate))->diff(new DateTime($trip->ToDate))->days ?: 1); ?>,
        travelers: <?php echo (int)$trip->NoOfPersons; ?>,
        budget: <?php echo (int)round((float)$trip->TotalCost); ?>,
        accommodation: <?php echo json_encode($trip->HotelName ?: 'hotel'); ?>,
        transport: <?php echo json_encode($trip->TransportMode ?: 'mixed'); ?>,
        // SECURITY (round 20): api/ai.php's itinerary action now requires a CSRF
        // token; this page already renders one (tms_csrf_field()) inside the Pay
        // Now form further down -- reuse that same value here.
        csrf_token: (document.querySelector('[name="csrf_token"]') || {}).value || ''
    });

    fetch('api/ai.php?action=itinerary', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-magic"></i> Generate an AI Itinerary';
                alert((res && res.error) ? res.error : 'Could not generate an itinerary right now. Please try again.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-magic"></i> Generate an AI Itinerary';
            alert('Network error — please try again.');
        });
}
</script>

<?php endif; ?>

<?php include('includes/footer.php'); ?>
</body>
</html>
