<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// ItineraryPlanner.php requires its own dependencies (AIClient, ExploreService,
// CrowdPredictor) internally with paths relative to its own directory -- no
// need to require them again here (same pattern api/ai.php uses).
require_once dirname(__DIR__) . '/backend/ai/ItineraryPlanner.php';
require_once dirname(__DIR__) . '/backend/services/TripReadinessScore.php';
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
 * this (see backend/ai/ItineraryPlanner.php's saveItinerary()).
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
// are all self-healing columns (see backend/config.php) so they should
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
    error_log('[TourBuddy] my-trip.php: extra columns not available yet -- falling back: ' . $e->getMessage());
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
            error_log('[TourBuddy] my-trip.php: safety alert lookup failed: ' . $e->getMessage());
        }
    }
    $payStatus = $trip->PaymentStatus ?? 'unpaid';
    $payBadge = ['unpaid'=>'default','pending'=>'warning','paid'=>'success','failed'=>'danger','refunded'=>'default','partially_refunded'=>'warning'][$payStatus] ?? 'default';
    $payLabels = [
        'unpaid'              => tms_t('label_payment_unpaid', 'Unpaid'),
        'pending'             => tms_t('label_pending', 'Pending'),
        'paid'                => tms_t('label_payment_paid', 'Paid'),
        'failed'              => tms_t('label_payment_failed', 'Failed'),
        'refunded'            => tms_t('label_payment_refunded', 'Refunded'),
        'partially_refunded'  => tms_t('label_payment_partially_refunded', 'Partially refunded'),
    ];
    $payLabel = $payLabels[$payStatus] ?? ucfirst(str_replace('_',' ', $payStatus));

    $statusConfig = [
        0 => ['label' => tms_t('status_pending_confirmation', 'Pending Confirmation'), 'class' => 'warning'],
        1 => ['label' => tms_t('step_confirmed', 'Confirmed'), 'class' => 'success'],
        2 => ['label' => tms_t('label_cancelled', 'Cancelled'), 'class' => 'danger'],
    ];
    $sc = $statusConfig[$trip->status] ?? ['label' => tms_t('label_unknown', 'Unknown'), 'class' => 'default'];

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

    // Plan versions (owner-only: $trip above was loaded WHERE UserEmail = the signed-in user). Accepting changes the saved
    // day-by-day plan only; the booking itself is never touched. See backend/services/ItineraryVersions.php.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['itv_action'])) {
        tms_csrf_validate();
        require_once dirname(__DIR__) . '/backend/services/ItineraryVersions.php';
        $itvAct = (string)$_POST['itv_action']; $itvVid = (int)($_POST['version_id'] ?? 0);
        if ($itvAct === 'optimize')    $itvRes = ItineraryVersions::proposeRouteOptimization($dbh, (int)$trip->BookingId, $email, ItineraryChecker::cachedGeocoder($dbh));
        elseif ($itvAct === 'accept')  $itvRes = ItineraryVersions::accept($dbh, $itvVid, $email, !empty($_POST['itv_ack']));
        elseif ($itvAct === 'reject')  $itvRes = ItineraryVersions::reject($dbh, $itvVid, $email);
        else                           $itvRes = ['ok' => false, 'message' => 'Unknown action.'];
        $_SESSION['itv_flash'] = ['ok' => !empty($itvRes['ok']), 'message' => (string)($itvRes['message'] ?? '')];
        header('Location: my-trip.php?id=' . (int)$trip->BookingId . '#mtVersionsCard');
        exit;
    }

    // TourBuddy V2 -- Trip Readiness Score. Only meaningful before departure
    // (during/after/cancelled trips don't need a "get ready" checklist),
    // computed from real facts already available on this page -- no new
    // query for payment/confirmation status, since $trip already has them.
    $readiness = ($phase === 'before') ? TripReadinessScore::forBooking($dbh, $trip, $email) : null;
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | My Trip</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="<?php echo tms_asset_ver('js/ai-feedback.js'); ?>" defer></script>
<style>
.mt-hero{background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);color:#fff;padding:40px 0;margin-bottom:30px;}
.mt-hero h2{margin:0 0 6px;font-weight:800;}
.mt-hero .mt-meta{opacity:.9;font-size:14px;}
.mt-phase{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;margin-right:8px;}
.mt-phase-before{background:#dbeafe;color:#1e40af;}
/* NEW (Round 20, spec item 33 -- Dark Mode): "during" and "cancelled" are
   genuine success/danger states, not just this page's own color choice, so
   they now pull from the shared design tokens (css/modern-theme.css's
   :root + backend/header.php's html[data-theme="dark"] override) instead
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
.mt-ai-disclosure{font-size:12px;color:var(--text-muted);background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;}
.mt-empty{text-align:center;padding:40px 20px;color:var(--text-muted);}
.mt-empty i{font-size:44px;display:block;margin-bottom:14px;color:#ddd;}
.mt-generate-btn{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:10px 22px;font-weight:700;font-size:14px;cursor:pointer;}
.mt-generate-btn:disabled{opacity:.6;cursor:not-allowed;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<?php if ($notFound): ?>
<div class="container" style="padding:80px 0;text-align:center;">
    <i class="fa fa-exclamation-circle" style="font-size:56px;color:#ddd;"></i>
    <h3 style="margin-top:16px;"><?php echo htmlspecialchars(tms_t('heading_trip_not_found','Trip not found'), ENT_QUOTES, 'UTF-8'); ?></h3>
    <p style="color:var(--text-muted);"><?php echo htmlspecialchars(tms_t('msg_trip_not_found_subtitle',"We couldn't find that booking on your account."), ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="tour-history.php" class="btn btn-primary" style="margin-top:10px;"><?php echo htmlspecialchars(tms_t('btn_back_to_my_bookings','Back to My Bookings'), ENT_QUOTES, 'UTF-8'); ?></a>
</div>
<?php else: ?>

<div class="mt-hero">
    <div class="container">
        <span class="mt-phase mt-phase-<?php echo $phase; ?>">
            <?php echo ['before' => tms_t('phase_upcoming_trip','Upcoming Trip'), 'during' => tms_t('phase_happening_now','Happening Now'), 'after' => tms_t('phase_completed','Completed'), 'cancelled' => tms_t('label_cancelled','Cancelled')][$phase]; ?>
        </span>
        <span class="label label-<?php echo $sc['class']; ?>" style="font-size:12px;padding:5px 10px;"><?php echo $sc['label']; ?></span>
        <span class="label label-<?php echo $payBadge; ?>" style="font-size:12px;padding:5px 10px;">
            <i class="fa fa-credit-card"></i> <?php echo $payLabel; ?>
        </span>
        <h2><?php echo htmlspecialchars($trip->PackageName, ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="mt-meta">
            <i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($trip->PackageLocation, ENT_QUOTES, 'UTF-8'); ?>
            &nbsp;·&nbsp; <i class="fa fa-calendar"></i>
            <?php echo (new DateTime($trip->FromDate))->format('d M Y'); ?> — <?php echo (new DateTime($trip->ToDate))->format('d M Y'); ?>
            &nbsp;·&nbsp; <i class="fa fa-users"></i> <?php echo (int)$trip->NoOfPersons; ?> <?php echo tms_t(((int)$trip->NoOfPersons === 1 ? 'label_traveler_word' : 'label_travelers_word'), ((int)$trip->NoOfPersons === 1 ? 'traveler' : 'travelers')); ?>
            <?php if ($phase === 'before'): ?>
            &nbsp;·&nbsp; <strong><?php echo $daysUntil; ?> <?php echo tms_t(($daysUntil === 1 ? 'label_day_word' : 'label_days_suffix'), ($daysUntil === 1 ? 'day' : 'days')); ?> <?php echo tms_t('dest_to_go_suffix','to go'); ?></strong>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($readiness !== null): ?>
<div class="container" style="margin-top:20px;">
    <div class="mt-card" style="padding:18px 22px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
            <h4 style="margin:0;"><i class="fa fa-tasks"></i> <?php echo htmlspecialchars(tms_t('heading_trip_readiness','Trip Readiness'), ENT_QUOTES, 'UTF-8'); ?></h4>
            <strong style="font-size:20px;color:<?php echo $readiness['score'] >= 80 ? '#16a34a' : ($readiness['score'] >= 40 ? '#d97706' : '#dc2626'); ?>;"><?php echo (int)$readiness['score']; ?>% <?php echo tms_t('label_ready_suffix','ready'); ?></strong>
        </div>
        <div style="background:#e5e7eb;border-radius:10px;height:10px;overflow:hidden;margin-bottom:14px;">
            <div style="background:<?php echo $readiness['score'] >= 80 ? '#16a34a' : ($readiness['score'] >= 40 ? '#d97706' : '#dc2626'); ?>;height:100%;width:<?php echo (int)$readiness['score']; ?>%;"></div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:10px 22px;font-size:13px;">
            <?php foreach ($readiness['components'] as $c): ?>
            <span style="color:<?php echo $c['met'] ? '#16a34a' : '#94a3b8'; ?>;">
                <i class="fa fa-<?php echo $c['met'] ? 'check-circle' : 'circle-o'; ?>"></i>
                <?php echo htmlspecialchars(tms_t('readiness_' . $c['key'], $c['label']), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($readiness['missing'])): ?>
        <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($readiness['missing'] as $m): ?>
                <?php if (!empty($m['action_url'])): ?>
                <a href="<?php echo htmlspecialchars($m['action_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:12px;padding:5px 12px;border-radius:20px;">
                    <i class="fa fa-arrow-right"></i> <?php echo htmlspecialchars(tms_t('readiness_action_' . ($m['key'] ?? ''), $m['action_label']), ENT_QUOTES, 'UTF-8'); ?>
                </a>
                <?php else: ?>
                <span style="font-size:12px;color:var(--text-muted);padding:5px 12px;"><?php echo htmlspecialchars(tms_t('readiness_action_' . ($m['key'] ?? ''), $m['action_label']), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
            <strong><?php echo htmlspecialchars(ucfirst($alert->alert_type), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(tms_t('label_alert_word','alert'), ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($alert->destination, ENT_QUOTES, 'UTF-8'); ?></strong>
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
                <h4><i class="fa fa-info-circle"></i> <?php echo htmlspecialchars(tms_t('heading_trip_details','Trip Details'), ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="mt-fact-row"><i class="fa fa-hashtag"></i> <?php echo htmlspecialchars(tms_t('dest_booking_hash_prefix','Booking #BK'), ENT_QUOTES, 'UTF-8'); ?><?php echo (int)$trip->BookingId; ?> — <?php echo htmlspecialchars(tms_t('label_booked_word','booked'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($trip->RegDate, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="mt-fact-row"><i class="fa fa-money"></i> <?php echo htmlspecialchars(tms_t('label_total_cost_prefix','Total cost:'), ENT_QUOTES, 'UTF-8'); ?> ৳<?php echo number_format((float)$trip->TotalCost, 2); ?></div>
                <?php if (!empty($trip->HotelName)): ?>
                <div class="mt-fact-row"><i class="fa fa-bed"></i> <?php echo htmlspecialchars($trip->HotelName, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($trip->HotelRating)): ?> (<?php echo htmlspecialchars((string)$trip->HotelRating, ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-star" style="font-size:11px;"></i>)<?php endif; ?><?php if (!empty($trip->MealPlan)): ?> — <?php echo htmlspecialchars($trip->MealPlan, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
                <?php else: ?>
                <div class="mt-fact-row"><i class="fa fa-bed"></i> <?php echo htmlspecialchars(tms_t('msg_accommodation_not_specified','Accommodation details not specified for this package.'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (!empty($trip->TransportMode)): ?>
                <div class="mt-fact-row"><i class="fa fa-bus"></i> <?php echo htmlspecialchars(tms_t('label_transport_prefix','Transport:'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($trip->TransportMode, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php if (!empty($trip->VerificationCode) && $trip->status != 2): ?>
                <div class="mt-fact-row"><i class="fa fa-qrcode"></i> <?php echo htmlspecialchars(tms_t('label_eticket_code_prefix','E-ticket verification code:'), ENT_QUOTES, 'UTF-8'); ?> <code><?php echo htmlspecialchars($trip->VerificationCode, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <?php endif; ?>
            </div>

            <?php // SECURITY (round 20): api/ai.php's itinerary action now requires a CSRF
            // token. The page's only other tms_csrf_field() call lives inside the Pay
            // Now form below, which doesn't render once a trip is paid -- meaning a
            // paid trip with no itinerary yet would have no token to send and every
            // "Generate an AI Itinerary" click would 403. This one is unconditional. ?>
            <?php tms_csrf_field(); ?>
            <?php
            // Trip Autopilot: pending proposal for this booking (verified notice and/or forecast -> suggested itinerary change).
            // Nothing here changes anything; the customer approves or declines. See TRIP_AUTOPILOT.md.
            $apProposal = null; $apDiff = [];
            if ($trip->status != 2 && ($phase === 'before' || $phase === 'during')) {
                require_once dirname(__DIR__) . '/backend/services/TripAutopilot.php';
                TripAutopilot::expireStale($dbh);
                $apProposal = TripAutopilot::pendingFor($dbh, (int)$trip->BookingId, $email);
                if ($apProposal && $apProposal['proposed']) { $apDiff = TripAutopilot::diffDays($itinerary, $apProposal['proposed']); }
            }
            $apE = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
            if ($trip->status != 2 && ($phase === 'before' || $phase === 'during')): ?>
            <div class="mt-card" id="mtAutopilotCard" role="region" aria-labelledby="mtApHeading">
                <h4 id="mtApHeading"><i class="fa fa-magic"></i> Trip Autopilot</h4>
                <?php if ($apProposal): ?>
                    <p style="font-size:13px;margin-bottom:10px;"><strong>A verified disruption may affect this trip.</strong> Nothing has been changed. Review and choose.</p>
                    <?php foreach ($apProposal['signals'] as $sg): ?>
                        <div class="mt-fact-row" style="display:block;">
                        <?php if ($sg['type'] === 'notice'): ?>
                            <?php if (!empty($sg['label'])): ?><span class="label label-<?php echo ($sg['label'] === 'verified') ? 'success' : 'default'; ?>"><?php echo $apE(ucfirst($sg['label'])); ?></span> <?php endif; ?>
                            <?php if (!empty($sg['severity'])): ?><span class="label label-<?php echo $sg['severity'] === 'high' ? 'danger' : ($sg['severity'] === 'medium' ? 'warning' : 'info'); ?>"><?php echo $apE(ucfirst($sg['severity'])); ?> severity</span> <?php endif; ?>
                            <strong><?php echo $apE($sg['title']); ?></strong> (<?php echo $apE($sg['country']); ?>)<br>
                            <span style="font-size:13px;"><?php echo nl2br($apE($sg['body'])); ?></span><br>
                            <span style="font-size:12px;color:var(--text-muted);">Source: <a href="<?php echo $apE($sg['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $apE($sg['source_name']); ?></a> &middot; checked <?php echo $apE($sg['verified_on']); ?><?php if (!empty($sg['expires_on'])): ?> &middot; valid until <?php echo $apE($sg['expires_on']); ?><?php endif; ?>. Check the source before you decide.</span>
                        <?php else: ?>
                            <span class="label label-default">Forecast</span> <strong>Not a confirmed event:</strong> <?php echo (int)$sg['rain_chance']; ?>% chance of rain on day <?php echo (int)$sg['day']; ?> (<?php echo $apE($sg['date']); ?>)<?php if (!empty($sg['observed_at'])): ?>, forecast fetched <?php echo $apE($sg['observed_at']); ?><?php endif; ?>.
                        <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($apProposal['proposed']): ?>
                        <p style="font-size:13px;margin:10px 0 4px;"><strong>Suggested change</strong>
                        <?php require_once dirname(__DIR__) . '/backend/ai/ItineraryChecker.php'; echo ItineraryChecker::summaryHtml(ItineraryChecker::check($apProposal['proposed'], [], ItineraryChecker::cachedGeocoder($dbh)), 'Check of the suggested plan'); ?> &mdash; <?php echo (int)count($apDiff); ?> day(s). This is an AI suggestion, not a confirmed booking or a staff-verified plan. Prices and venues in it are not part of your booking.</p>
                        <ul style="font-size:13px;">
                        <?php foreach ($apDiff as $d): ?>
                            <li>Day <?php echo (int)$d['day']; ?> (<?php echo $apE($d['status']); ?>): <?php echo $apE($d['old_title'] ?? '—'); ?> &rarr; <strong><?php echo $apE($d['new_title'] ?? '—'); ?></strong></li>
                        <?php endforeach; ?>
                        <?php if (!$apDiff): ?><li>No day-level difference from your current plan.</li><?php endif; ?>
                        </ul>
                        <p style="font-size:12px;color:var(--text-muted);">Approving saves a new version of your day-by-day itinerary only. Your booking, dates, price and payment are never changed here.</p>
                        <button type="button" class="mt-generate-btn" id="mtApApprove" onclick="mtApDecide('approve', <?php echo (int)$apProposal['ProposalId']; ?>)">Approve this change</button>
                    <?php else: ?>
                        <p style="font-size:13px;"><?php echo $apE($apProposal['Summary']); ?></p>
                    <?php endif; ?>
                    <button type="button" class="mt-generate-btn" style="background:#595959;" id="mtApDecline" onclick="mtApDecide('decline', <?php echo (int)$apProposal['ProposalId']; ?>)"><?php echo $apProposal['proposed'] ? 'No thanks, keep my plan' : 'Dismiss'; ?></button>
                <?php else: ?>
                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">Checks this trip against admin-verified travel notices and the forecast. If something affects it, you get a suggested change to review. Nothing changes unless you approve.</p>
                    <button type="button" class="mt-generate-btn" id="mtApCheck" onclick="mtApCheck()">Check my trip for disruptions</button>
                <?php endif; ?>
                <div id="mtApMsg" role="status" aria-live="polite" style="margin-top:10px;font-size:13px;"></div>
            </div>
            <?php endif; ?>
            <?php
            // ── Plan versions: propose / compare / accept or reject (owner-only, CSRF) ──
            if ($itinerary && !empty($itinerary['days']) && $trip->status != 2 && ($phase === 'before' || $phase === 'during')):
                require_once dirname(__DIR__) . '/backend/services/ItineraryVersions.php';
                $itvOv = ItineraryVersions::overview($dbh, (int)$trip->BookingId, $email);
                $itvFlash = $_SESSION['itv_flash'] ?? null; unset($_SESSION['itv_flash']);
            ?>
            <div class="mt-card" id="mtVersionsCard" role="region" aria-labelledby="mtVersionsHeading">
                <h4 id="mtVersionsHeading"><i class="fa fa-code-fork"></i> Plan versions</h4>
                <?php if ($itvFlash): ?><div class="alert alert-<?php echo $itvFlash['ok'] ? 'success' : 'warning'; ?>" role="status"><?php echo $apE($itvFlash['message']); ?></div><?php endif; ?>
                <?php if (!$itvOv['ready']): ?>
                    <p style="font-size:13px;color:var(--text-muted);">Plan versions are not available yet on this site (a database update is pending).</p>
                <?php else: ?>
                    <p style="font-size:13px;color:var(--text-muted);">Every change to your plan is kept as a version you can compare. A suggestion never replaces your plan until you accept it, and accepting never changes or cancels a booking.</p>
                    <form method="post" action="my-trip.php?id=<?php echo (int)$trip->BookingId; ?>" style="margin-bottom:12px;">
                        <?php tms_csrf_field(); ?>
                        <input type="hidden" name="itv_action" value="optimize">
                        <button type="submit" class="mt-generate-btn">Suggest a route with less backtracking</button>
                    </form>
                    <?php foreach ($itvOv['versions'] as $v):
                        $chk = $v['check']; $df = $v['diff']; $cost = json_decode((string)$v['CostJson'], true) ?: [];
                    ?>
                    <div class="mt-fact-row" style="display:block;border-top:1px solid #e5e7eb;padding-top:8px;">
                        <strong>v<?php echo (int)$v['VersionNo']; ?></strong>
                        <span class="label label-<?php echo $v['Status'] === 'current' ? 'success' : ($v['Status'] === 'proposed' ? 'warning' : 'default'); ?>"><?php echo $apE($v['Status']); ?></span>
                        <span style="font-size:12px;color:var(--text-muted);"><?php echo $apE($v['Source']); ?> &middot; <?php echo $apE($v['CreatedAt']); ?></span>
                        <?php if ($v['Note']): ?><div style="font-size:13px;"><?php echo $apE($v['Note']); ?></div><?php endif; ?>
                        <div style="font-size:12px;color:var(--text-muted);">
                            Costs in this version: estimated <?php echo number_format((float)($cost['estimated'] ?? 0)); ?>, quoted <?php echo number_format((float)($cost['quoted'] ?? 0)); ?>, confirmed <?php echo number_format((float)($cost['confirmed'] ?? 0)); ?>.
                            Only &quot;confirmed&quot; amounts come from a real booking; estimated amounts are AI figures.
                        </div>
                        <?php if ($v['Status'] === 'proposed' && $df): ?>
                            <div style="font-size:13px;margin-top:4px;"><strong>Compared with your current plan:</strong> <?php echo $apE($df['summary']); ?>
                                <?php if (!empty($df['cost_delta']['total'])): ?> (estimated cost change <?php echo ($df['cost_delta']['total'] > 0 ? '+' : '') . number_format((float)$df['cost_delta']['total']); ?>)<?php endif; ?></div>
                            <ul style="font-size:13px;margin:4px 0 4px 18px;">
                            <?php foreach ($df['days'] as $dd): foreach (['added' => 'Added', 'removed' => 'Removed', 'moved' => 'Moved', 'changed' => 'Changed'] as $k => $lbl): foreach ($dd[$k] as $txt): ?>
                                <li>Day <?php echo (int)$dd['day']; ?> &middot; <?php echo $lbl; ?>: <?php echo $apE($txt); ?></li>
                            <?php endforeach; endforeach; endforeach; ?>
                            </ul>
                            <?php if ($chk): ?>
                                <div style="font-size:13px;"><?php echo !empty($chk['feasible']) ? 'No timing or hours contradictions found by our automatic checks.' : 'Our automatic checks found problems with this version:'; ?>
                                <?php if (!empty($chk['issues'])): ?><ul style="margin:4px 0 4px 18px;color:#92400e;"><?php foreach (array_slice($chk['issues'], 0, 5) as $is): ?><li><?php echo isset($is['day']) ? 'Day ' . (int)$is['day'] . ': ' : ''; echo $apE($is['message']); ?></li><?php endforeach; ?></ul><?php endif; ?></div>
                            <?php endif; ?>
                            <form method="post" action="my-trip.php?id=<?php echo (int)$trip->BookingId; ?>" style="margin-top:6px;">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="version_id" value="<?php echo (int)$v['VersionId']; ?>">
                                <?php if (!empty($v['affected'])): ?>
                                    <div class="alert alert-warning" style="font-size:13px;">This version moves or removes items tied to your <strong>confirmed bookings</strong>:
                                        <ul style="margin:4px 0 4px 18px;"><?php foreach ($v['affected'] as $af): ?><li><?php echo $apE($af['activity']); ?> (booking #<?php echo $apE($af['booking_ref']); ?>, <?php echo $apE($af['change']); ?>)</li><?php endforeach; ?></ul>
                                        Accepting only changes your plan. Your bookings stay as they are; change them through the normal booking process.
                                        <label style="display:block;margin-top:6px;"><input type="checkbox" name="itv_ack" value="1"> I understand, accept anyway</label></div>
                                <?php endif; ?>
                                <button type="submit" name="itv_action" value="accept" class="mt-generate-btn">Accept this version</button>
                                <button type="submit" name="itv_action" value="reject" class="mt-generate-btn" style="background:#595959;">Reject</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($itvOv['audit']): ?>
                    <details style="margin-top:10px;font-size:12px;"><summary>History</summary>
                        <ul style="margin:6px 0 0 18px;"><?php foreach ($itvOv['audit'] as $au): ?><li><?php echo $apE($au['CreatedAt']); ?> &middot; <?php echo $apE($au['Action']); ?><?php if ($au['Detail']): ?> &mdash; <?php echo $apE($au['Detail']); ?><?php endif; ?></li><?php endforeach; ?></ul>
                    </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="mt-card" id="mtItineraryCard">
                <h4><i class="fa fa-calendar-check-o"></i> <?php echo htmlspecialchars(tms_t('heading_day_by_day_itinerary','Day-by-Day Itinerary'), ENT_QUOTES, 'UTF-8'); ?></h4>
                <?php if ($itinerary && !empty($itinerary['days'])): ?>
                    <div class="mt-ai-disclosure">
                        <i class="fa fa-info-circle"></i> <?php echo htmlspecialchars(tms_t('msg_ai_itinerary_disclosure',"This is an AI-generated trip plan — a suggestion to help you plan, not a confirmed itinerary booked or verified by TourBuddy staff. Hotel/restaurant names and prices here are AI suggestions, not part of your actual booking."), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php
                    require_once dirname(__DIR__) . '/backend/ai/ItineraryChecker.php';
                    // Own-plan consistency only (no requested duration/budget passed: those could be misread against stored plans).
                    echo ItineraryChecker::summaryHtml(ItineraryChecker::check($itinerary, [], ItineraryChecker::cachedGeocoder($dbh)));
                    ?>
                    <?php foreach ($itinerary['days'] as $day): ?>
                    <div class="mt-day">
                        <h5><?php echo htmlspecialchars(tms_t('label_day_prefix','Day'), ENT_QUOTES, 'UTF-8'); ?> <?php echo (int)($day['day'] ?? 0); ?><?php if (!empty($day['title'])): ?> — <?php echo htmlspecialchars($day['title'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></h5>
                        <?php foreach (($day['schedule'] ?? []) as $item): ?>
                        <div class="mt-sched-item">
                            <strong><?php echo htmlspecialchars($item['time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong> —
                            <?php echo htmlspecialchars($item['activity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($item['location'])): ?> @ <?php echo htmlspecialchars($item['location'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php
                    // Round 115, master prompt item 14 ("AI Feedback Loop"): one
                    // thumbs up/down for the generated itinerary as a whole (not
                    // per day -- Rule 73, a per-day control would be more noise
                    // than signal for a single AI generation call). See
                    // js/ai-feedback.js.
                    ?>
                    <div class="tms-ai-feedback" style="margin-top:6px;font-size:12px;color:var(--text-muted);">
                        <?php echo htmlspecialchars(tms_t('msg_was_itinerary_helpful','Was this itinerary helpful?'), ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, 1, this)" style="border:none;background:none;color:#27ae60;cursor:pointer;padding:2px 6px;" title="<?php echo htmlspecialchars(tms_t('title_feedback_yes_helpful','Yes, helpful'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-thumbs-up"></i></button>
                        <button type="button" onclick="tmsSendAiFeedback('itinerary', <?php echo (int)$trip->BookingId; ?>, -1, this)" style="border:none;background:none;color:#c0392b;cursor:pointer;padding:2px 6px;" title="<?php echo htmlspecialchars(tms_t('title_feedback_no_helpful','No, not helpful'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-thumbs-down"></i></button>
                    </div>
                <?php if ($phase === 'before' || $phase === 'during'): ?>
                <?php
                // Round 115 (master prompt item 12, "AI Dynamic Trip
                // Replanning"): pure opt-in check, never automatic. Nothing
                // here changes the saved itinerary or the booking unless the
                // traveler explicitly clicks "Apply" on a proposal they've
                // already read in full -- see api/ai.php's
                // handleWeatherReplan{Check,Preview,Apply}() and
                // ItineraryPlanner::checkWeatherRisk()/saveApprovedItinerary().
                ?>
                <div class="mt-card" id="mtWeatherReplanCard" style="margin-top:16px;">
                    <h4><i class="fa fa-cloud"></i> Weather Impact Check</h4>
                    <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
                        <?php echo htmlspecialchars(tms_t('dest_weather_replan_intro',"Checks the real forecast against your saved itinerary and flags any day that might need a change. Nothing changes automatically — you review and approve any suggested update yourself before it's saved."), ENT_QUOTES, 'UTF-8'); ?></p>
                    <button type="button" class="mt-generate-btn" id="mtWeatherCheckBtn" onclick="mtCheckWeatherReplan()">
                        <i class="fa fa-cloud"></i> <?php echo htmlspecialchars(tms_t('btn_check_weather_impact','Check Weather Impact on My Itinerary'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                    <div id="mtWeatherReplanResult" style="margin-top:14px;"></div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                    <div class="mt-empty">
                        <i class="fa fa-map-o"></i>
                        <p><?php echo htmlspecialchars(tms_t('msg_no_itinerary_yet','No itinerary generated for this trip yet.'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if ($trip->status != 2): ?>
                        <button type="button" class="mt-generate-btn" id="mtGenerateBtn" onclick="mtGenerateItinerary()">
                            <i class="fa fa-magic"></i> <?php echo htmlspecialchars(tms_t('btn_generate_ai_itinerary','Generate an AI Itinerary'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                        <p style="font-size:12px;color:var(--text-muted);margin-top:10px;"><?php echo htmlspecialchars(tms_t('dest_itinerary_generation_hint','Uses your real trip dates and destination. Takes about 15–30 seconds.'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
        <div class="col-md-4">
            <div class="mt-card">
                <h4><i class="fa fa-th-large"></i> <?php echo htmlspecialchars(tms_t('heading_everything_for_trip','Everything for this trip'), ENT_QUOTES, 'UTF-8'); ?></h4>
                <?php
                // Master prompt item 68 ("PRE-TRIP / DURING-TRIP / POST-TRIP"):
                // $phase was already computed above (line ~113) and used for the
                // badge/countdown, but every link below was shown identically
                // regardless of phase -- and several already-built, already-
                // working features (budget-planner.php, packing-assistant.php,
                // travel-memory.php, reviews.php, loyalty.php's achievements
                // section, ai-recommend.php) were never linked from this hub at
                // all, so a traveler had no way to discover them from the one
                // page meant to bring "everything for this trip" together. This
                // groups the same always-true "Everything" list into the three
                // sections item 68 names, per-phase, reusing only pages that
                // already exist -- no new backend, no new page. "Visa" (also
                // named under BEFORE TRIP) is deliberately omitted: see
                // SCOPE_EXCLUSIONS.md -- this project has no licensed source
                // for visa/entry-requirement data and made a considered decision
                // not to build a placeholder for it (Round 40).
                ?>
                <div class="mt-links">
                    <?php if ($phase === 'before'): ?>
                    <a href="weather.php?dest=<?php echo (int)$trip->PackageId; ?>" class="mt-link"><i class="fa fa-cloud"></i> <?php echo htmlspecialchars(tms_t('link_weather','Weather'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="budget-planner.php" class="mt-link"><i class="fa fa-calculator"></i> <?php echo htmlspecialchars(tms_t('link_budget_planner','Budget Planner'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="packing-assistant.php" class="mt-link"><i class="fa fa-suitcase"></i> <?php echo htmlspecialchars(tms_t('link_packing_assistant','Packing Assistant'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="wallet.php" class="mt-link"><i class="fa fa-briefcase"></i> <?php echo htmlspecialchars(tms_t('link_documents','Documents'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="tour-map.php" class="mt-link"><i class="fa fa-map"></i> <?php echo htmlspecialchars(tms_t('link_map','Map'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php elseif ($phase === 'during'): ?>
                    <a href="tour-map.php" class="mt-link"><i class="fa fa-map"></i> <?php echo htmlspecialchars(tms_t('link_map','Map'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="weather.php?dest=<?php echo (int)$trip->PackageId; ?>" class="mt-link"><i class="fa fa-cloud"></i> <?php echo htmlspecialchars(tms_t('link_weather','Weather'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="flight-status.php" class="mt-link"><i class="fa fa-plane"></i> <?php echo htmlspecialchars(tms_t('link_flight_tracker','Flight Tracker'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="emergency.php" class="mt-link"><i class="fa fa-phone"></i> <?php echo htmlspecialchars(tms_t('link_emergency','Emergency'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="expense-tracker.php?booking=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-money"></i> <?php echo htmlspecialchars(tms_t('link_expenses','Expenses'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="ai-copilot.php?booking_id=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-comments"></i> <?php echo htmlspecialchars(tms_t('link_ai_copilot','AI Copilot'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php if ($trip->status == 1): ?>
                    <a href="ticket.php?bkid=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-download"></i> <?php echo htmlspecialchars(tms_t('link_eticket_reservations','E-Ticket / Reservations'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                    <?php elseif ($phase === 'after'): ?>
                    <a href="reviews.php?pkg=<?php echo (int)$trip->PackageId; ?>" class="mt-link"><i class="fa fa-star"></i> <?php echo htmlspecialchars(tms_t('link_write_review','Write a Review'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="travel-memory.php" class="mt-link"><i class="fa fa-camera"></i> <?php echo htmlspecialchars(tms_t('link_travel_memories','Travel Memories'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="expense-tracker.php?booking=<?php echo (int)$trip->BookingId; ?>" class="mt-link"><i class="fa fa-money"></i> <?php echo htmlspecialchars(tms_t('link_expenses_planned_actual','Expenses (planned vs actual)'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="loyalty.php" class="mt-link"><i class="fa fa-trophy"></i> <?php echo htmlspecialchars(tms_t('link_achievements','Achievements'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="ai-recommend.php" class="mt-link"><i class="fa fa-magic"></i> <?php echo htmlspecialchars(tms_t('link_recommendations_next_trip','Recommendations for your next trip'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="package-list.php" class="mt-link"><i class="fa fa-compass"></i> <?php echo htmlspecialchars(tms_t('link_plan_next_trip','Plan Your Next Trip'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: /* cancelled -- no phase-specific set in item 68, keep the previously-generic links */ ?>
                    <a href="weather.php?dest=<?php echo (int)$trip->PackageId; ?>" class="mt-link"><i class="fa fa-cloud"></i> <?php echo htmlspecialchars(tms_t('link_weather','Weather'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="tour-map.php" class="mt-link"><i class="fa fa-map"></i> <?php echo htmlspecialchars(tms_t('link_map','Map'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="wallet.php" class="mt-link"><i class="fa fa-briefcase"></i> <?php echo htmlspecialchars(tms_t('link_documents','Documents'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                    <a href="tour-history.php" class="mt-link"><i class="fa fa-cog"></i> <?php echo htmlspecialchars(tms_t('link_manage_booking','Manage Booking'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <!-- Round 115 continuation (master prompt item 10 -- Booking Engine
                         "support" sub-requirement): same enquiry.php link as
                         tour-history.php's booking card, so support is reachable from
                         here regardless of trip phase. -->
                    <a href="enquiry.php?subject=<?php echo urlencode('Booking #BK'.(int)$trip->BookingId); ?>" class="mt-link"><i class="fa fa-life-ring"></i> <?php echo htmlspecialchars(tms_t('link_support','Support'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>

            <?php
            // Round 83 (master prompt item 52 -- "PWA / Offline"): the spec
            // names a specific list of things offline mode should prioritize
            // for a trip -- itinerary, booking confirmation, tickets,
            // emergency contacts, hotel information, packing list, selected
            // documents, previously cached trip information. Everything in
            // $offlineKitItems below is a real, already-working page for
            // *this specific real booking* -- nothing fabricated, and E-Ticket
            // is only offered when this booking is actually Confirmed
            // (status==1), matching the same condition the E-Ticket link
            // elsewhere on this page already uses. Not offered for a
            // cancelled booking -- there's nothing meaningful to save offline
            // for a trip that isn't happening.
            $offlineKitItems = [];
            if ($trip->status != 2) {
                $offlineKitItems[] = ['url' => 'my-trip.php?id=' . (int)$trip->BookingId, 'label' => tms_t('item_trip_itinerary', 'Trip Itinerary')];
                if ((int)$trip->status === 1) {
                    $offlineKitItems[] = ['url' => 'ticket.php?bkid=' . (int)$trip->BookingId, 'label' => tms_t('item_eticket_booking_confirmation', 'E-Ticket / Booking Confirmation')];
                }
                $offlineKitItems[] = ['url' => 'emergency.php', 'label' => tms_t('item_emergency_contacts_safety', 'Emergency Contacts & Safety')];
                $offlineKitItems[] = ['url' => 'wallet.php', 'label' => tms_t('item_my_documents', 'My Documents')];
                $offlineKitItems[] = ['url' => 'packing-assistant.php', 'label' => tms_t('link_packing_assistant', 'Packing Assistant')];
            }
            ?>
            <?php if ($offlineKitItems): ?>
            <div class="mt-card" id="mtOfflineCard">
                <h4><i class="fa fa-wifi"></i> <?php echo htmlspecialchars(tms_t('heading_save_trip_offline','Save This Trip for Offline'), ENT_QUOTES, 'UTF-8'); ?></h4>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
                    <?php echo htmlspecialchars(tms_t('dest_offline_intro','Stores these real pages on this device so you can still open them with no signal:'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <ul style="font-size:13px;color:#555;margin:0 0 14px;padding-left:20px;">
                    <?php foreach ($offlineKitItems as $item): ?>
                    <li><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="mt-generate-btn" id="mtOfflineBtn" onclick="mtSaveOffline()">
                    <i class="fa fa-download"></i> <?php echo htmlspecialchars(tms_t('btn_save_for_offline','Save for Offline'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <div id="mtOfflineStatus" style="font-size:12.5px;color:var(--text-muted);margin-top:10px;"></div>
                <p style="font-size:11.5px;color:var(--text-muted);margin-top:10px;">
                    <?php echo htmlspecialchars(tms_t('dest_offline_disclaimer',"Live features (payment, weather, AI Copilot, live chat) still need a connection and won't work offline -- only the pages above are saved as a snapshot of what they show right now."), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (($trip->PaymentStatus ?? 'unpaid') === 'unpaid' && $trip->status != 2): ?>
            <div class="mt-card">
                <h4><i class="fa fa-credit-card"></i> <?php echo htmlspecialchars(tms_t('heading_payment','Payment'), ENT_QUOTES, 'UTF-8'); ?></h4>
                <p style="font-size:13px;color:var(--text-muted);"><?php echo htmlspecialchars(tms_t('msg_trip_not_paid',"This trip hasn't been paid for yet."), ENT_QUOTES, 'UTF-8'); ?></p>
                <form method="POST" action="pay-now.php">
                    <?php tms_csrf_field(); ?>
                    <input type="hidden" name="booking_id" value="<?php echo (int)$trip->BookingId; ?>">
                    <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-flask"></i> <?php echo htmlspecialchars(tms_t('btn_pay_now','Pay Now'), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars(tms_t('label_sandbox_suffix','(Sandbox)'), ENT_QUOTES, 'UTF-8'); ?></button>
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
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo addslashes(tms_t('msg_generating_ellipsis','Generating…')); ?>';

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
                btn.innerHTML = '<i class="fa fa-magic"></i> <?php echo addslashes(tms_t('btn_generate_ai_itinerary','Generate an AI Itinerary')); ?>';
                alert((res && res.error) ? res.error : '<?php echo addslashes(tms_t('msg_could_not_generate_itinerary','Could not generate an itinerary right now. Please try again.')); ?>');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-magic"></i> <?php echo addslashes(tms_t('btn_generate_ai_itinerary','Generate an AI Itinerary')); ?>';
            alert('<?php echo addslashes(tms_t('msg_network_error_retry','Network error — please try again.')); ?>');
        });
}

// Round 115 (master prompt item 12, "AI Dynamic Trip Replanning"). Three
// steps, matching the three separate API actions: check (read-only) ->
// preview (generates but does not save) -> apply (saves exactly what was
// previewed, only on an explicit click).
<?php if ($phase === 'before' || $phase === 'during'): ?>
var mtTripData = {
    booking_id: <?php echo (int)$trip->BookingId; ?>,
    destination: <?php echo json_encode($trip->PackageLocation, JSON_UNESCAPED_UNICODE); ?>,
    start_date: <?php echo json_encode((new DateTime($trip->FromDate))->format('Y-m-d')); ?>,
    duration: <?php echo max(1, (new DateTime($trip->FromDate))->diff(new DateTime($trip->ToDate))->days ?: 1); ?>,
    travelers: <?php echo (int)$trip->NoOfPersons; ?>,
    budget: <?php echo (int)round((float)$trip->TotalCost); ?>,
    accommodation: <?php echo json_encode($trip->HotelName ?: 'hotel'); ?>,
    transport: <?php echo json_encode($trip->TransportMode ?: 'mixed'); ?>
};

function mtApPost(action, extra, done) {
    var body = new URLSearchParams(Object.assign({ csrf_token: (document.querySelector('[name="csrf_token"]') || {}).value || '' }, extra));
    var msg = document.getElementById('mtApMsg');
    fetch('api/ai.php?action=' + action, { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) { done(res, msg); })
        .catch(function () { msg.textContent = 'Network error. Please try again.'; });
}
function mtApCheck() {
    var b = document.getElementById('mtApCheck'); b.disabled = true; b.textContent = 'Checking...';
    mtApPost('autopilot_check', { booking_id: <?php echo (int)$trip->BookingId; ?> }, function (res, msg) {
        b.disabled = false; b.textContent = 'Check my trip for disruptions';
        if (!res.success) { msg.textContent = res.error || 'Could not check right now.'; return; }
        if (res.data.status === 'no_disruption') { msg.textContent = 'No verified disruption affects this trip right now. This only covers notices our team has verified, so also check official sources before you travel.'; return; }
        location.reload();
    });
}
function mtApDecide(kind, id) {
    document.getElementById('mtApApprove') && (document.getElementById('mtApApprove').disabled = true);
    document.getElementById('mtApDecline') && (document.getElementById('mtApDecline').disabled = true);
    mtApPost(kind === 'approve' ? 'autopilot_approve' : 'autopilot_decline', { proposal_id: id }, function (res, msg) {
        if (res.success) { location.reload(); return; }
        msg.textContent = res.error || 'Could not complete that.';
        setTimeout(function () { location.reload(); }, 2500);
    });
}

function mtEsc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function mtCsrf() { return (document.querySelector('[name="csrf_token"]') || {}).value || ''; }

function mtCheckWeatherReplan() {
    var btn = document.getElementById('mtWeatherCheckBtn');
    var resultBox = document.getElementById('mtWeatherReplanResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo addslashes(tms_t('msg_checking_ellipsis','Checking…')); ?>';
    resultBox.innerHTML = '';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_check', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-cloud"></i> <?php echo addslashes(tms_t('btn_check_weather_impact','Check Weather Impact on My Itinerary')); ?>';
            if (!res || !res.success) {
                resultBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">' + (mtEsc(res && res.error) || '<?php echo addslashes(tms_t('msg_could_not_check_weather','Could not check weather right now.')); ?>') + '</p>';
                return;
            }
            var data = res.data;
            if (data.no_forecast_available) {
                resultBox.innerHTML = '<p style="font-size:13px;color:var(--text-muted);"><?php echo addslashes(tms_t('msg_no_forecast_available','No live forecast available yet for these dates (forecasts only cover about a week out) — check back closer to your trip.')); ?></p>';
            } else if (!data.flagged_days || data.flagged_days.length === 0) {
                resultBox.innerHTML = '<p style="font-size:13px;color:#27ae60;"><i class="fa fa-check-circle"></i> <?php echo addslashes(tms_t('msg_no_weather_concerns','No weather concerns found for your itinerary right now.')); ?></p>';
            } else {
                var html = '<p style="font-size:13px;color:#c0392b;font-weight:600;"><i class="fa fa-exclamation-triangle"></i> ' + data.flagged_days.length + ' <?php echo addslashes(tms_t('dest_days_may_need_look_suffix','day(s) may need a look:')); ?></p>';
                data.flagged_days.forEach(function (d) {
                    html += '<div class="mt-day" style="border-left-color:#e74c3c;">'
                        + '<h5><?php echo addslashes(tms_t('label_day_prefix','Day')); ?> ' + mtEsc(d.day) + ' — ' + mtEsc(d.date) + '</h5>'
                        + '<div class="mt-sched-item"><?php echo addslashes(tms_t('label_forecast_prefix','Forecast:')); ?> ' + mtEsc(d.rain_chance) + '<?php echo addslashes(tms_t('dest_rain_chance_suffix','% chance of rain,')); ?> ' + mtEsc(d.temp_range) + '</div>'
                        + (d.itinerary_title ? '<div class="mt-sched-item"><?php echo addslashes(tms_t('label_planned_prefix','Planned:')); ?> ' + mtEsc(d.itinerary_title) + '</div>' : '')
                        + '<button type="button" class="mt-generate-btn" style="margin-top:8px;font-size:12px;padding:6px 14px;" onclick="mtPreviewReplan(' + (parseInt(d.day, 10) || 0) + ', \'' + mtEsc(d.date) + '\', ' + (parseFloat(d.rain_chance) || 0) + ', this)"><?php echo addslashes(tms_t('btn_suggest_alternative_prefix','Suggest an alternative for Day')); ?> ' + d.day + '</button>'
                        + '<div class="mt-replan-preview" style="margin-top:10px;"></div>'
                        + '</div>';
                });
                resultBox.innerHTML = html;
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-cloud"></i> <?php echo addslashes(tms_t('btn_check_weather_impact','Check Weather Impact on My Itinerary')); ?>';
            resultBox.innerHTML = '<p style="color:#c0392b;font-size:13px;"><?php echo addslashes(tms_t('msg_network_error_retry','Network error — please try again.')); ?></p>';
        });
}

function mtPreviewReplan(day, date, rainChance, btnEl) {
    var previewBox = btnEl.parentElement.querySelector('.mt-replan-preview');
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo addslashes(tms_t('msg_generating_proposal','Generating proposal…')); ?>';
    previewBox.innerHTML = '';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration,
        travelers: mtTripData.travelers, budget: mtTripData.budget,
        accommodation: mtTripData.accommodation, transport: mtTripData.transport,
        flagged_day: day, flagged_date: date, rain_chance: rainChance, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_preview', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            btnEl.style.display = 'none';
            if (!res || !res.success) {
                previewBox.innerHTML = '<p style="color:#c0392b;font-size:13px;">' + (mtEsc(res && res.error) || '<?php echo addslashes(tms_t('msg_could_not_generate_proposal','Could not generate a proposal right now.')); ?>') + '</p>';
                return;
            }
            var itin = res.data.itinerary;
            var html = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;">'
                + '<p style="font-size:12px;color:#92400e;font-weight:700;margin-bottom:8px;"><i class="fa fa-info-circle"></i> <?php echo addslashes(tms_t('msg_proposed_change_banner','PROPOSED CHANGE — not saved yet. Review the full itinerary below, then choose.')); ?></p>';
            (itin.days || []).forEach(function (d) {
                html += '<div class="mt-day"><h5><?php echo addslashes(tms_t('label_day_prefix','Day')); ?> ' + mtEsc(d.day || '') + (d.title ? ' — ' + mtEsc(d.title) : '') + '</h5>';
                (d.schedule || []).forEach(function (item) {
                    html += '<div class="mt-sched-item"><strong>' + (item.time || '') + '</strong> — ' + (item.activity || '') + (item.location ? ' @ ' + item.location : '') + '</div>';
                });
                html += '</div>';
            });
            html += '<button type="button" class="mt-generate-btn" onclick="mtApplyReplan(this)"><?php echo addslashes(tms_t('btn_apply_update','Apply This Update')); ?></button> '
                + '<button type="button" class="mt-generate-btn" style="background:#aaa;" onclick="this.closest(\'.mt-replan-preview\').innerHTML=\'\'"><?php echo addslashes(tms_t('btn_discard','Discard')); ?></button>'
                + '</div>';
            previewBox.innerHTML = html;
            previewBox.dataset.itinerary = JSON.stringify(itin);
        })
        .catch(function () {
            previewBox.innerHTML = '<p style="color:#c0392b;font-size:13px;"><?php echo addslashes(tms_t('msg_network_error_retry','Network error — please try again.')); ?></p>';
        });
}

function mtApplyReplan(btnEl) {
    var previewBox = btnEl.closest('.mt-replan-preview');
    var itinJson = previewBox.dataset.itinerary;
    btnEl.disabled = true;
    btnEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo addslashes(tms_t('msg_saving_ellipsis','Saving…')); ?>';

    var body = new URLSearchParams({
        booking_id: mtTripData.booking_id, destination: mtTripData.destination,
        start_date: mtTripData.start_date, duration: mtTripData.duration,
        travelers: mtTripData.travelers, budget: mtTripData.budget,
        accommodation: mtTripData.accommodation, transport: mtTripData.transport,
        itinerary: itinJson, csrf_token: mtCsrf()
    });

    fetch('api/ai.php?action=weather_replan_apply', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.success) {
                location.reload();
            } else {
                btnEl.disabled = false;
                btnEl.innerHTML = '<?php echo addslashes(tms_t('btn_apply_update','Apply This Update')); ?>';
                alert((res && res.error) || '<?php echo addslashes(tms_t('msg_could_not_save_update','Could not save this update. Please try again.')); ?>');
            }
        })
        .catch(function () {
            btnEl.disabled = false;
            btnEl.innerHTML = '<?php echo addslashes(tms_t('btn_apply_update','Apply This Update')); ?>';
            alert('<?php echo addslashes(tms_t('msg_network_error_retry','Network error — please try again.')); ?>');
        });
}
<?php endif; ?>

// Round 83 (master prompt item 52): talks to sw.js's message handler over a
// MessageChannel so this page gets a real per-call reply instead of firing
// postMessage() blind. Every URL in mtOfflineItems is real -- built
// server-side above from this exact booking's own data, not invented here.
<?php if ($offlineKitItems): ?>
var mtOfflineItems = <?php echo json_encode($offlineKitItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
var mtOfflineStorageKey = 'tms_offline_trip_<?php echo (int)$trip->BookingId; ?>';

function mtSwMessage(msg) {
    return new Promise(function (resolve, reject) {
        if (!('serviceWorker' in navigator)) { reject(new Error('Service worker not supported in this browser.')); return; }
        navigator.serviceWorker.ready.then(function (reg) {
            if (!reg.active) { reject(new Error('No active service worker yet.')); return; }
            var channel = new MessageChannel();
            channel.port1.onmessage = function (e) { resolve(e.data); };
            reg.active.postMessage(msg, [channel.port2]);
        }).catch(reject);
    });
}

function mtSaveOffline() {
    var btn = document.getElementById('mtOfflineBtn');
    var status = document.getElementById('mtOfflineStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> <?php echo addslashes(tms_t('msg_saving_ellipsis','Saving…')); ?>';
    status.textContent = '';

    mtSwMessage({ type: 'TMS_CACHE_TRIP', urls: mtOfflineItems.map(function (i) { return i.url; }) })
        .then(function (res) {
            var results = (res && res.results) || [];
            var okCount = results.filter(function (r) { return r.ok; }).length;
            btn.disabled = false;
            if (okCount === mtOfflineItems.length) {
                btn.innerHTML = '<i class="fa fa-check-circle"></i> <?php echo addslashes(tms_t('msg_saved_for_offline','Saved for Offline')); ?>';
                localStorage.setItem(mtOfflineStorageKey, String(Date.now()));
                status.textContent = '<?php echo addslashes(tms_t('dest_pages_saved_all_prefix','All')); ?> ' + okCount + ' <?php echo addslashes(tms_t('dest_pages_saved_suffix','pages saved. Last saved:')); ?> ' + new Date().toLocaleString();
            } else {
                btn.innerHTML = '<i class="fa fa-download"></i> <?php echo addslashes(tms_t('btn_retry_save_offline','Retry Save for Offline')); ?>';
                status.textContent = okCount + ' <?php echo addslashes(tms_t('label_of_word','of')); ?> ' + mtOfflineItems.length + ' <?php echo addslashes(tms_t('dest_pages_saved_partial_tail','pages saved -- some failed (check your connection and try again).')); ?>';
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-download"></i> <?php echo addslashes(tms_t('btn_save_for_offline','Save for Offline')); ?>';
            status.textContent = '<?php echo addslashes(tms_t('dest_could_not_save_offline_prefix','Could not save for offline right now:')); ?> ' + (err && err.message ? err.message : '<?php echo addslashes(tms_t('dest_unknown_error','unknown error')); ?>');
        });
}

// On load, just reflect whatever this browser last recorded for this trip
// (a hint, not a guarantee the cache entries are still there) -- clicking
// the button always re-saves fresh regardless of this label.
(function () {
    var last = localStorage.getItem(mtOfflineStorageKey);
    if (last) {
        var status = document.getElementById('mtOfflineStatus');
        if (status) { status.textContent = '<?php echo addslashes(tms_t('dest_last_saved_offline_prefix','Last saved for offline:')); ?> ' + new Date(parseInt(last, 10)).toLocaleString(); }
    }
})();
<?php endif; ?>
</script>

<?php endif; ?>

<?php include('backend/footer.php'); ?>
</body>
</html>
