<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
require_once('backend/payments/PaymentProviderInterface.php');
require_once('backend/payments/SandboxProvider.php');
require_once('backend/payments/PaymentService.php');
require_once('backend/repositories/BookingRepository.php');
tms_require_user();
$paymentService = new PaymentService($dbh);
$bookingRepo    = new BookingRepository($dbh);

$error = '';
$msg   = '';

// Cancel booking (POST with CSRF — no longer a plain GET link)
if (isset($_POST['cancel_booking'])) {
    tms_csrf_validate();

    $bid   = tms_int($_POST['bkid'] ?? 0, 1);
    $email = $_SESSION['login'];

    // Fetch the booking's from-date and verify it belongs to this user
    $booking = $bookingRepo->findActiveForUser($bid, $email);

    if ($booking) {
        $fromDate  = new DateTime($booking->FromDate);
        $today     = new DateTime();
        // FIX (Round 14): this used ->diff()->days, which truncates — a trip
        // departing in 38 hours gives days = 1, so `$diffDays > 1` was false and
        // the user was told they were "within 24 hours of departure" two full
        // days out. Compare actual hours against the stated 24-hour rule.
        $hoursUntil = ($fromDate->getTimestamp() - $today->getTimestamp()) / 3600;

        if ($hoursUntil > 24) {
            // A booking only reaches status=1 after our team has confirmed
            // it — the closest thing this schema has to "money changed
            // hands" (there's no payment-gateway integration; confirmation
            // implies payment was settled some other way, per how the rest
            // of this app already works). So a refund only needs tracking
            // once a *confirmed* booking is cancelled — a still-pending one
            // hadn't been paid against yet.
            $wasConfirmed = ((int)$booking->status === 1);
            // LIVE BUG FIX (caught in live testing, Round 20): cancelling a
            // booking used to leave any still-pending date-change request
            // (RequestedFromDate/RequestedToDate/ModificationStatus='pending')
            // untouched, so the booking history kept showing a stale "Date
            // change requested ... (pending review)" badge on an already-
            // cancelled trip, and admin/manage-bookings.php's approve_mod
            // action had no guard against actually applying that request's
            // dates to a cancelled booking. Clear the modification request
            // here too, since a cancelled trip has nothing left to reschedule.
            $upd = $dbh->prepare(
                "UPDATE tblbooking SET status=2, CancelledBy='u'," .
                " RequestedFromDate=NULL, RequestedToDate=NULL," .
                " ModificationStatus=NULL, ModificationNote=NULL" .
                ($wasConfirmed ? ", RefundStatus='requested'" : "") .
                " WHERE BookingId=:bid AND UserEmail=:email"
            );
            $upd->bindParam(':bid',   $bid,   PDO::PARAM_INT);
            $upd->bindParam(':email', $email, PDO::PARAM_STR);
            $upd->execute();
            if ($wasConfirmed) {
                // Links this booking-level refund request to the payment
                // ledger too, if it was actually paid through payment.php's
                // sandbox flow (no-ops otherwise -- see PaymentService::logRefundRequest()).
                $paymentService->logRefundRequest($bid, 'Customer cancelled booking #BK' . $bid);
            }
            $msg = 'Booking #BK' . $bid . ' has been cancelled successfully.'
                 . ($wasConfirmed ? ' A refund request has been logged — our team will process it and update the status here.' : '');
        } else {
            $error = 'You cannot cancel a booking within 24 hours of the departure date.';
        }
    } else {
        $error = 'Booking not found or already cancelled.';
    }
}

// Request a date change on an existing booking (not cancelled, not within
// 24h of departure — same guard as cancellation). Goes to 'pending' for
// admin review rather than applying immediately, since a date change can
// affect package availability/pricing that only staff can verify.
if (isset($_POST['request_modification'])) {
    tms_csrf_validate();

    $bid       = tms_int($_POST['bkid'] ?? 0, 1);
    $email     = $_SESSION['login'];
    $newFrom   = tms_clean($_POST['new_fromdate'] ?? '');
    $newTo     = tms_clean($_POST['new_todate']   ?? '');

    $from = DateTime::createFromFormat('d-m-Y', $newFrom) ?: DateTime::createFromFormat('Y-m-d', $newFrom);
    $to   = DateTime::createFromFormat('d-m-Y', $newTo)   ?: DateTime::createFromFormat('Y-m-d', $newTo);
    $now  = new DateTime();

    $booking = $bookingRepo->findActiveForUser($bid, $email);

    if (!$booking) {
        $error = 'Booking not found or already cancelled.';
    } elseif ($booking->ModificationStatus === 'pending') {
        $error = 'This booking already has a pending date-change request.';
    } elseif (!$from || !$to || $to <= $from) {
        $error = 'Please enter a valid date range for the change request.';
    } elseif ($from <= $now) {
        // Round 14: the new departure date was never checked against today, so a
        // request to move a trip to 01-01-2020 went through to the admin queue.
        $error = 'The new departure date must be in the future.';
    } elseif ((new DateTime($booking->FromDate)) <= $now) {
        $error = 'This trip has already started or passed, so it can no longer be modified.';
    } else {
        $dbh->prepare(
            "UPDATE tblbooking SET RequestedFromDate=:rf, RequestedToDate=:rt, ModificationStatus='pending', ModificationNote=NULL
             WHERE BookingId=:bid AND UserEmail=:email"
        )->execute([
            ':rf' => $from->format('Y-m-d'), ':rt' => $to->format('Y-m-d'),
            ':bid' => $bid, ':email' => $email,
        ]);
        $msg = 'Date-change request submitted for booking #BK' . $bid . ' — our team will review it shortly.';
    }
}

// Fetch all bookings for this user.
// Round 90 (Rule 40/71 -- Repository layer): this used to be ~30 lines of
// inline SQL with a migration-resilience fallback (see
// BookingRepository::findByUserEmail() for that logic, carried over
// verbatim). Behavior is unchanged -- same two queries, same fallback
// trigger, same PDO::FETCH_OBJ shape -- only where the query lives moved.
$uemail   = $_SESSION['login'];
$bookings = $bookingRepo->findByUserEmail($uemail);

$statusConfig = [
    0 => ['label' => tms_t('label_pending', 'Pending'),     'class' => 'warning'],
    1 => ['label' => tms_t('step_confirmed', 'Confirmed'),  'class' => 'success'],
    2 => ['label' => tms_t('label_cancelled', 'Cancelled'), 'class' => 'danger'],
];
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | My Tour History</title>
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
<script src="js/qrcode.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof qrcode === 'undefined') return;
  document.querySelectorAll('.tms-qr[data-qr-value]').forEach(function (box) {
    var value = box.getAttribute('data-qr-value') || '';
    try {
      var qr = qrcode(0, 'M');
      qr.addData(value);
      qr.make();
      box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true });
      var svg = box.querySelector('svg');
      if (svg) { svg.style.width = '100px'; svg.style.height = '100px'; }
    } catch (e) {
      box.innerHTML = '<span style="font-size:10px;color:var(--text-muted);">QR unavailable</span>';
    }
  });
});
</script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.history-card{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px;border:1px solid #f0f0f0;transition:.25s;}
.history-card:hover{box-shadow:0 6px 28px rgba(0,0,0,.13);}
.history-header{background:#f8f9fa;padding:14px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.history-header .booking-id{font-weight:700;color:var(--secondary);font-size:15px;}
.history-body{padding:18px 20px;}
.history-body .detail-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;font-size:14px;color:#555;}
html[data-theme="dark"] .history-body .detail-row { color: var(--text-muted) !important; }
.history-body .detail-row i{color:var(--secondary);width:16px;flex-shrink:0;margin-top:2px;}
.cancel-btn{background:var(--white);color:#C23B3B;border:1.5px solid #C23B3B;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;}
.cancel-btn:hover{background:#C23B3B;color:#fff;}
.ticket-btn{background:var(--white);color:var(--secondary);border:1.5px solid var(--secondary);border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.ticket-btn:hover{background:var(--secondary);color:#fff;}
.empty-state{text-align:center;padding:70px 20px;color:var(--text-muted);}
.empty-state i{font-size:60px;display:block;margin-bottom:20px;color:#ddd;}

/* Round 2 additions: timeline, modification request, refund badge, QR */
.bk-timeline{display:flex;align-items:center;gap:4px;margin:12px 0 16px;flex-wrap:wrap;}
.bk-timeline .step{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:#5F6D65;}
.bk-timeline .step.done{color:#1A7F55;}
.bk-timeline .step.current{color:var(--secondary);}
.bk-timeline .step.bad{color:#C23B3B;}
.bk-timeline .dot{width:9px;height:9px;border-radius:50%;background:currentColor;flex-shrink:0;}
.bk-timeline .bar{width:26px;height:2px;background:#e5e9f0;}
.refund-badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:14px;margin-top:6px;}
/* Round 115, Phase I (UI polish / design-system consistency -- DESIGN_SYSTEM.md's
   own "Known inconsistency" example is this exact block): modern-theme.css already
   ships a status-badge triple (--success/--warning/--danger + their -bg/-text
   pairs) for this light-chip-with-dark-text pattern, so these one-off hex values
   are replaced with the existing tokens instead of a new/duplicate color scale.
   --warning-bg (#FEF3C7) and --danger-bg (#FEE2E2) already matched byte-for-byte;
   --success-bg (#DCFCE7) is a barely-perceptible shift from the old #d1fae5 in the
   same pastel-green family. No shadow/radius/layout change in this pass -- see
   DESIGN_SYSTEM.md for why that part is deliberately out of scope here. */
.refund-requested{background:var(--warning-bg);color:var(--warning-text);}
.refund-processed{background:var(--success-bg);color:var(--success-text);}
.refund-rejected{background:var(--danger-bg);color:var(--danger-text);}
.mod-badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:14px;margin-top:6px;background:#eff6ff;color:#1e40af;}
.modify-btn{background:var(--white);color:var(--secondary);border:1.5px solid var(--secondary);border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;}
.modify-btn:hover{background:var(--secondary);color:#fff;}
.qr-thumb{width:64px;height:64px;border-radius:8px;border:1px solid #eee;flex-shrink:0;}
.mod-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:9000;align-items:center;justify-content:center;padding:20px;}
.mod-modal-overlay.open{display:flex;}
.mod-modal{background:var(--white);border-radius:14px;padding:26px;max-width:380px;width:100%;}
.mod-modal h4{margin:0 0 14px;font-weight:700;}
.mod-modal .form-group{margin-bottom:14px;}
.mod-modal label{font-weight:600;font-size:13px;display:block;margin-bottom:5px;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tms-page-hero" style="min-height:<?php echo !empty($_SESSION['checkout_success']) ? '260px' : '180px'; ?>;">
    <div style="text-align:center;">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo tms_t('heading_booking_history','My Tour History'); ?></h1>
    <?php if (!empty($_SESSION['checkout_success'])): ?>
    <div class="tms-steps" style="margin-top:22px;">
      <div class="tms-step done"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label"><?php echo tms_t('step_cart','Cart'); ?></div></div>
      <div class="tms-step done"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label"><?php echo tms_t('step_checkout','Checkout'); ?></div></div>
      <div class="tms-step done active"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label"><?php echo tms_t('step_confirmed','Confirmed'); ?></div></div>
    </div>
    <?php endif; ?>
    </div>
</div>

<div class="privacy">
    <div class="container">
        <?php if (!empty($_SESSION['checkout_success'])): ?>
            <div class="succWrap" style="display:flex;align-items:center;gap:14px;">
                <i class="fa fa-check-circle" style="font-size:26px;color:#27ae60;"></i>
                <div>
                    <strong><?php echo tms_t('heading_booking_request_sent','Booking request sent!'); ?></strong><br>
                    <span style="font-weight:400;"><?php echo (int)$_SESSION['checkout_success']; ?> <?php echo tms_t('dest_bookings_created_suffix', "booking(s) created from your cart — they're pending confirmation from our team."); ?></span>
                </div>
            </div>
            <?php unset($_SESSION['checkout_success']); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="errorWrap"><strong><?php echo tms_t('label_error_colon','ERROR:'); ?></strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
        <?php elseif ($msg): ?>
            <div class="succWrap"><strong><?php echo tms_t('label_success_colon','SUCCESS:'); ?></strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
        <?php endif; ?>

        <h3 class="wow fadeInDown animated" data-wow-delay=".1s" style="margin-bottom:24px;">
            <i class="fa fa-history" style="color:var(--secondary)"></i>
            <?php echo tms_t('heading_your_bookings','Your Bookings'); ?>
            <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:8px;">(<?php echo count($bookings); ?> <?php echo tms_t('label_total_word','total'); ?>)</span>
        </h3>

        <?php if (count($bookings) > 0): ?>
            <?php foreach ($bookings as $b):
                $sc = $statusConfig[$b->status] ?? ['label'=>tms_t('label_unknown','Unknown'),'class'=>'default'];
                $canCancel = ($b->status != 2);
                $fromDate  = new DateTime($b->FromDate);
                $today     = new DateTime();
                // Must match the server-side rule in the handler above (hours,
                // not truncated days) or the button state contradicts what
                // actually happens when it's clicked.
                $hoursUntil = ($fromDate->getTimestamp() - $today->getTimestamp()) / 3600;
                $canCancel = $canCancel && ($hoursUntil > 24);
                $canModify = ($b->status != 2) && ($fromDate > $today) && ($b->ModificationStatus !== 'pending');
            ?>
            <div class="history-card wow fadeInUp" data-wow-delay=".05s">
                <div class="history-header">
                    <span class="booking-id">
                        <i class="fa fa-ticket"></i> #BK<?php echo (int)$b->BookingId; ?>
                    </span>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <span class="label label-<?php echo $sc['class']; ?>" style="font-size:12px;padding:5px 10px;">
                            <?php echo $sc['label'];
                                  if ($b->status == 2) {
                                      echo ' ' . ($b->CancelledBy === 'a' ? tms_t('label_by_admin_paren','(by Admin)') : tms_t('label_by_you_paren','(by You)'));
                                  } ?>
                        </span>
                        <?php
                        // LIVE FEATURE (payment architecture): PaymentStatus is new on
                        // tblbooking -- older rows and any booking made before this
                        // column existed default to 'unpaid', which is exactly the
                        // truthful state (no payment step existed before). A Pending,
                        // unpaid booking can still be paid now via the sandbox flow.
                        $payStatus = $b->PaymentStatus ?? 'unpaid';
                        $payBadge = ['unpaid'=>'default','pending'=>'warning','paid'=>'success','failed'=>'danger','refunded'=>'default','partially_refunded'=>'warning'][$payStatus] ?? 'default';
                        // Round 116 (item 36 -- i18n): was ucfirst(str_replace('_',' ', $payStatus)),
                        // which only ever produced the English word regardless of active language.
                        // Explicit lookup so each PaymentStatus enum value gets a real translation;
                        // ucfirst(...) is kept as the fallback for any future enum value that hasn't
                        // been given a dedicated key yet, so nothing breaks silently.
                        $payLabels = [
                            'unpaid'              => tms_t('label_payment_unpaid', 'Unpaid'),
                            'pending'             => tms_t('label_pending', 'Pending'),
                            'paid'                => tms_t('label_payment_paid', 'Paid'),
                            'failed'              => tms_t('label_payment_failed', 'Failed'),
                            'refunded'            => tms_t('label_payment_refunded', 'Refunded'),
                            'partially_refunded'  => tms_t('label_payment_partially_refunded', 'Partially refunded'),
                        ];
                        $payLabel = $payLabels[$payStatus] ?? ucfirst(str_replace('_',' ', $payStatus));
                        ?>
                        <span class="label label-<?php echo $payBadge; ?>" style="font-size:12px;padding:5px 10px;">
                            <i class="fa fa-credit-card"></i> <?php echo $payLabel; ?>
                        </span>
                        <?php if ($payStatus === 'unpaid' && $b->status != 2): ?>
                        <form method="POST" action="pay-now.php" style="display:inline;margin:0;">
                            <?php tms_csrf_field(); ?>
                            <input type="hidden" name="booking_id" value="<?php echo (int)$b->BookingId; ?>">
                            <button type="submit" class="label label-primary" style="border:none;font-size:12px;padding:5px 10px;cursor:pointer;">
                                <i class="fa fa-flask"></i> <?php echo tms_t('btn_pay_now','Pay Now'); ?> <?php echo tms_t('label_sandbox_suffix','(Sandbox)'); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <small style="color:var(--text-muted);"><?php echo tms_t('label_booked_colon','Booked:'); ?> <?php echo htmlspecialchars($b->RegDate, ENT_QUOTES,'UTF-8'); ?></small>
                    </div>
                </div>
                <div class="history-body">
                    <!-- Booking timeline -->
                    <div class="bk-timeline">
                        <div class="step done"><span class="dot"></span> <?php echo tms_t('label_requested','Requested'); ?></div>
                        <div class="bar"></div>
                        <?php if ($b->status == 2): ?>
                        <div class="step bad"><span class="dot"></span> <?php echo tms_t('label_cancelled','Cancelled'); ?></div>
                        <?php if ($b->RefundStatus): ?>
                        <div class="bar"></div>
                        <div class="step <?php echo $b->RefundStatus === 'processed' ? 'done' : 'current'; ?>"><span class="dot"></span> <?php echo tms_t('label_refund_prefix','Refund'); ?> <?php echo htmlspecialchars($b->RefundStatus, ENT_QUOTES,'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="step <?php echo $b->status == 1 ? 'done' : 'current'; ?>"><span class="dot"></span> <?php echo tms_t('step_confirmed','Confirmed'); ?></div>
                        <div class="bar"></div>
                        <div class="step <?php echo ($b->status == 1 && $fromDate <= $today) ? 'done' : ''; ?>"><span class="dot"></span> <?php echo tms_t('label_completed','Completed'); ?></div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div style="flex:1;min-width:220px;">
                            <div class="detail-row">
                                <i class="fa fa-briefcase"></i>
                                <div>
                                    <strong><?php echo tms_t('label_package_word','Package'); ?>:</strong>
                                    <a href="package-details.php?pkgid=<?php echo (int)$b->PackageId; ?>">
                                        <?php echo htmlspecialchars($b->PackageName, ENT_QUOTES,'UTF-8'); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="detail-row">
                                <i class="fa fa-calendar"></i>
                                <div>
                                    <strong><?php echo tms_t('label_from','From'); ?>:</strong> <?php echo htmlspecialchars($b->FromDate, ENT_QUOTES,'UTF-8'); ?>
                                    &nbsp;&nbsp;<strong><?php echo tms_t('label_to','To'); ?>:</strong> <?php echo htmlspecialchars($b->ToDate, ENT_QUOTES,'UTF-8'); ?>
                                    <?php if (!empty($b->NoOfPersons)): ?>
                                    &nbsp;&nbsp;<strong><?php echo tms_t('label_travelers','Travelers'); ?>:</strong> <?php echo (int)$b->NoOfPersons; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($b->TotalCost)): ?>
                            <div class="detail-row">
                                <i class="fa fa-money"></i>
                                <div><strong><?php echo tms_t('label_total','Total'); ?>:</strong> <?php echo tms_currency((float)$b->TotalCost); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($b->Comment)): ?>
                            <div class="detail-row">
                                <i class="fa fa-comment-o"></i>
                                <div><strong><?php echo tms_t('label_note_word','Note'); ?>:</strong> <?php echo htmlspecialchars($b->Comment, ENT_QUOTES,'UTF-8'); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($b->status == 2 && !empty($b->UpdationDate)): ?>
                            <div class="detail-row">
                                <i class="fa fa-clock-o"></i>
                                <div><strong><?php echo tms_t('label_cancelled_at','Cancelled at'); ?>:</strong> <?php echo htmlspecialchars($b->UpdationDate, ENT_QUOTES,'UTF-8'); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($b->RefundStatus): ?>
                            <span class="refund-badge refund-<?php echo htmlspecialchars($b->RefundStatus, ENT_QUOTES,'UTF-8'); ?>">
                                <i class="fa fa-undo"></i> <?php echo tms_t('label_refund_prefix','Refund'); ?> <?php echo htmlspecialchars(ucfirst($b->RefundStatus), ENT_QUOTES,'UTF-8'); ?>
                            </span>
                            <?php if (!empty($b->RefundNote)): ?><div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><?php echo htmlspecialchars($b->RefundNote, ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                            <?php endif; ?>

                            <?php if ($b->ModificationStatus === 'pending'): ?>
                            <span class="mod-badge">
                                <i class="fa fa-exchange"></i> <?php echo tms_t('label_date_change_requested_colon','Date change requested:'); ?> <?php echo htmlspecialchars($b->RequestedFromDate, ENT_QUOTES,'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($b->RequestedToDate, ENT_QUOTES,'UTF-8'); ?> <?php echo tms_t('label_pending_review_paren','(pending review)'); ?>
                            </span>
                            <?php elseif ($b->ModificationStatus === 'rejected'): ?>
                            <div style="font-size:12px;color:#e74c3c;margin-top:4px;"><i class="fa fa-times-circle"></i> <?php echo tms_t('msg_last_mod_declined','Last date-change request was declined'); ?><?php echo !empty($b->ModificationNote) ? ': '.htmlspecialchars($b->ModificationNote, ENT_QUOTES,'UTF-8') : '.'; ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($b->VerificationCode) && $b->status != 2): ?>
                        <div style="text-align:center;">
                            <div class="qr-thumb tms-qr" style="width:120px;height:120px;display:inline-flex;align-items:center;justify-content:center;background:var(--white);border:1px solid #e2e8f0;border-radius:8px;padding:6px;box-sizing:border-box;"
                                 data-qr-value="TMS-BK<?php echo (int)$b->BookingId; ?>-<?php echo htmlspecialchars($b->VerificationCode, ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><?php echo tms_t('label_scan_to_verify','Scan to verify'); ?></div>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                            <a href="my-trip.php?id=<?php echo (int)$b->BookingId; ?>" class="ticket-btn" style="background:#0f2027;color:#fff;border-color:#0f2027;">
                                <i class="fa fa-compass"></i> <?php echo tms_t('link_my_trip','My Trip'); ?>
                            </a>
                            <?php if ($b->status == 1): ?>
                            <a href="ticket.php?bkid=<?php echo (int)$b->BookingId; ?>" class="ticket-btn">
                                <i class="fa fa-download"></i> <?php echo tms_t('link_ticket_word','Ticket'); ?>
                            </a>
                            <?php endif; ?>
                            <a href="expense-tracker.php?booking=<?php echo (int)$b->BookingId; ?>" class="ticket-btn" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe;">
                                <i class="fa fa-money"></i> <?php echo tms_t('link_expenses','Expenses'); ?>
                            </a>
                            <a href="wallet.php" class="ticket-btn" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">
                                <i class="fa fa-briefcase"></i> <?php echo tms_t('link_wallet','Wallet'); ?>
                            </a>
                            <!-- Round 115 continuation (master prompt item 10 -- Booking Engine
                                 "support" sub-requirement): no per-booking contact/support link
                                 existed anywhere before this; links to the existing public
                                 enquiry.php with the subject pre-filled so the agent sees which
                                 booking it's about without the customer having to type it. -->
                            <a href="enquiry.php?subject=<?php echo urlencode('Booking #BK'.(int)$b->BookingId); ?>" class="ticket-btn" style="background:#fefce8;color:#854d0e;border-color:#fde68a;">
                                <i class="fa fa-life-ring"></i> <?php echo tms_t('link_support','Support'); ?>
                            </a>
                            <?php if ($canModify): ?>
                            <button type="button" class="modify-btn" onclick="openModModal(<?php echo (int)$b->BookingId; ?>)">
                                <i class="fa fa-exchange"></i> <?php echo tms_t('btn_change_dates','Change Dates'); ?>
                            </button>
                            <?php endif; ?>
                            <?php if ($canCancel): ?>
                            <form method="post" onsubmit="return confirm('<?php echo addslashes(tms_t('msg_confirm_cancel_booking_prefix','Cancel booking #BK')); ?><?php echo (int)$b->BookingId; ?><?php echo addslashes(tms_t('msg_confirm_cancel_booking_suffix','?')); ?>');">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="cancel_booking" value="1">
                                <input type="hidden" name="bkid" value="<?php echo (int)$b->BookingId; ?>">
                                <button type="submit" class="cancel-btn">
                                    <i class="fa fa-times"></i> <?php echo tms_t('btn_cancel','Cancel'); ?>
                                </button>
                            </form>
                            <?php elseif ($b->status != 2): ?>
                            <button class="cancel-btn" disabled
                                    title="<?php echo htmlspecialchars(tms_t('msg_cannot_cancel_24h','Cannot cancel within 24 hours of departure'), ENT_QUOTES,'UTF-8'); ?>"
                                    style="opacity:.5;cursor:not-allowed;">
                                <i class="fa fa-times"></i> <?php echo tms_t('btn_cancel','Cancel'); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa fa-plane"></i>
                <h3><?php echo tms_t('heading_no_bookings_yet','No bookings yet'); ?></h3>
                <p><?php echo tms_t('msg_no_bookings_subtitle', "You haven't booked any tour packages."); ?></p>
                <a href="package-list.php" class="btn btn-primary" style="margin-top:10px;">
                    <i class="fa fa-search"></i> <?php echo tms_t('btn_browse_packages','Browse Packages'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Date-change request modal (shared across all cards; openModModal() fills in the booking id) -->
<div class="mod-modal-overlay" id="modModalOverlay">
    <div class="mod-modal">
        <h4><i class="fa fa-exchange" style="color:var(--secondary)"></i> <?php echo tms_t('heading_request_date_change','Request Date Change'); ?></h4>
        <form method="post">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="request_modification" value="1">
            <input type="hidden" name="bkid" id="modBkId">
            <div class="form-group">
                <label for="thNewFromDate"><?php echo tms_t('label_new_departure_date','New Departure Date'); ?></label>
                <?php /* Round 14: same bare-text-box problem as the booking form. */ ?>
                <input type="date" name="new_fromdate" id="thNewFromDate" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
            </div>
            <div class="form-group">
                <label for="thNewToDate"><?php echo tms_t('label_new_return_date','New Return Date'); ?></label>
                <input type="date" name="new_todate" id="thNewToDate" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
                <?php echo tms_t('dest_date_change_instructions', "This sends a request to our team — dates won't change until it's reviewed and approved, since availability and pricing need a quick check first."); ?>
            </p>
            <div style="display:flex;gap:10px;">
                <button type="button" onclick="closeModModal()" class="ticket-btn" style="flex:1;justify-content:center;"><?php echo tms_t('btn_cancel','Cancel'); ?></button>
                <button type="submit" class="modify-btn" style="flex:1;background:var(--secondary);color:#fff;"><?php echo tms_t('btn_submit_request','Submit Request'); ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function openModModal(bkid) {
    document.getElementById('modBkId').value = bkid;
    document.getElementById('modModalOverlay').classList.add('open');
}
function closeModModal() {
    document.getElementById('modModalOverlay').classList.remove('open');
}
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>
