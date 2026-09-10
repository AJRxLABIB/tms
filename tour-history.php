<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
require_once('includes/payments/PaymentProviderInterface.php');
require_once('includes/payments/SandboxProvider.php');
require_once('includes/payments/PaymentService.php');
tms_require_user();
$paymentService = new PaymentService($dbh);

$error = '';
$msg   = '';

// Cancel booking (POST with CSRF — no longer a plain GET link)
if (isset($_POST['cancel_booking'])) {
    tms_csrf_validate();

    $bid   = tms_int($_POST['bkid'] ?? 0, 1);
    $email = $_SESSION['login'];

    // Fetch the booking's from-date and verify it belongs to this user
    $chk = $dbh->prepare("SELECT BookingId, FromDate, status FROM tblbooking
                           WHERE BookingId=:bid AND UserEmail=:email AND status != 2 LIMIT 1");
    $chk->bindParam(':bid',   $bid,   PDO::PARAM_INT);
    $chk->bindParam(':email', $email, PDO::PARAM_STR);
    $chk->execute();
    $booking = $chk->fetch(PDO::FETCH_OBJ);

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
            $upd = $dbh->prepare(
                "UPDATE tblbooking SET status=2, CancelledBy='u'" .
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

    $chk = $dbh->prepare("SELECT BookingId, FromDate, status, ModificationStatus FROM tblbooking
                           WHERE BookingId=:bid AND UserEmail=:email AND status != 2 LIMIT 1");
    $chk->execute([':bid' => $bid, ':email' => $email]);
    $booking = $chk->fetch(PDO::FETCH_OBJ);

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

// Fetch all bookings for this user
$uemail = $_SESSION['login'];
// RESILIENCE (Rule 2 -- never let a page start crashing just because a new
// migration hasn't been run yet): b.PaymentStatus was added by
// database/migrations/003_booking_payment_columns.sql. If that migration
// hasn't been applied on this database yet, selecting it would throw
// (PDO is configured ERRMODE_EXCEPTION) and take this whole page down for
// every visitor. Try the new column first; fall back to the pre-payment
// column list (and default PaymentStatus to 'unpaid' below) if it's
// missing, so this page works identically before and after the migration
// is applied.
$sqlWithPayment = "SELECT b.BookingId, b.PackageId, p.PackageName, b.FromDate, b.ToDate,
               b.Comment, b.status, b.RegDate, b.CancelledBy, b.UpdationDate,
               b.NoOfPersons, b.TotalCost, b.VerificationCode,
               b.RequestedFromDate, b.RequestedToDate, b.ModificationStatus, b.ModificationNote,
               b.RefundStatus, b.RefundNote, b.PaymentStatus
        FROM tblbooking b
        JOIN tbltourpackages p ON p.PackageId = b.PackageId
        WHERE b.UserEmail=:uemail
        ORDER BY b.BookingId DESC";
$sqlWithoutPayment = "SELECT b.BookingId, b.PackageId, p.PackageName, b.FromDate, b.ToDate,
               b.Comment, b.status, b.RegDate, b.CancelledBy, b.UpdationDate,
               b.NoOfPersons, b.TotalCost, b.VerificationCode,
               b.RequestedFromDate, b.RequestedToDate, b.ModificationStatus, b.ModificationNote,
               b.RefundStatus, b.RefundNote
        FROM tblbooking b
        JOIN tbltourpackages p ON p.PackageId = b.PackageId
        WHERE b.UserEmail=:uemail
        ORDER BY b.BookingId DESC";
try {
    $q = $dbh->prepare($sqlWithPayment);
    $q->bindParam(':uemail', $uemail, PDO::PARAM_STR);
    $q->execute();
} catch (PDOException $e) {
    error_log('[TMS] tour-history.php: PaymentStatus column not available yet (run database/migrate.php) -- falling back: ' . $e->getMessage());
    $q = $dbh->prepare($sqlWithoutPayment);
    $q->bindParam(':uemail', $uemail, PDO::PARAM_STR);
    $q->execute();
}
$bookings = $q->fetchAll(PDO::FETCH_OBJ);

$statusConfig = [
    0 => ['label' => 'Pending',   'class' => 'warning'],
    1 => ['label' => 'Confirmed', 'class' => 'success'],
    2 => ['label' => 'Cancelled', 'class' => 'danger'],
];
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | My Tour History</title>
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
      box.innerHTML = '<span style="font-size:10px;color:#94a3b8;">QR unavailable</span>';
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
.cancel-btn{background:var(--white);color:#e74c3c;border:1.5px solid #e74c3c;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;}
.cancel-btn:hover{background:#e74c3c;color:#fff;}
.ticket-btn{background:var(--white);color:var(--secondary);border:1.5px solid var(--secondary);border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.ticket-btn:hover{background:var(--secondary);color:#fff;}
.empty-state{text-align:center;padding:70px 20px;color:#aaa;}
.empty-state i{font-size:60px;display:block;margin-bottom:20px;color:#ddd;}

/* Round 2 additions: timeline, modification request, refund badge, QR */
.bk-timeline{display:flex;align-items:center;gap:4px;margin:12px 0 16px;flex-wrap:wrap;}
.bk-timeline .step{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:#b8c2d0;}
.bk-timeline .step.done{color:#22a06b;}
.bk-timeline .step.current{color:var(--secondary);}
.bk-timeline .step.bad{color:#e74c3c;}
.bk-timeline .dot{width:9px;height:9px;border-radius:50%;background:currentColor;flex-shrink:0;}
.bk-timeline .bar{width:26px;height:2px;background:#e5e9f0;}
.refund-badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:14px;margin-top:6px;}
.refund-requested{background:#fef3c7;color:#92400e;}
.refund-processed{background:#d1fae5;color:#065f46;}
.refund-rejected{background:#fee2e2;color:#991b1b;}
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
<?php include('includes/header.php'); ?>
<div class="tms-page-hero" style="min-height:<?php echo !empty($_SESSION['checkout_success']) ? '260px' : '180px'; ?>;">
    <div style="text-align:center;">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s"><?php echo tms_t('heading_booking_history','My Tour History'); ?></h1>
    <?php if (!empty($_SESSION['checkout_success'])): ?>
    <div class="tms-steps" style="margin-top:22px;">
      <div class="tms-step done"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label">Cart</div></div>
      <div class="tms-step done"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label">Checkout</div></div>
      <div class="tms-step done active"><div class="tms-step-circle"><i class="fa fa-check"></i></div><div class="tms-step-label">Confirmed</div></div>
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
                    <strong>Booking request sent!</strong><br>
                    <span style="font-weight:400;"><?php echo (int)$_SESSION['checkout_success']; ?> booking(s) created from your cart — they're pending confirmation from our team.</span>
                </div>
            </div>
            <?php unset($_SESSION['checkout_success']); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
        <?php elseif ($msg): ?>
            <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
        <?php endif; ?>

        <h3 class="wow fadeInDown animated" data-wow-delay=".1s" style="margin-bottom:24px;">
            <i class="fa fa-history" style="color:var(--secondary)"></i>
            Your Bookings
            <span style="font-size:14px;font-weight:400;color:#888;margin-left:8px;">(<?php echo count($bookings); ?> total)</span>
        </h3>

        <?php if (count($bookings) > 0): ?>
            <?php foreach ($bookings as $b):
                $sc = $statusConfig[$b->status] ?? ['label'=>'Unknown','class'=>'default'];
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
                                      echo $b->CancelledBy === 'a' ? ' (by Admin)' : ' (by You)';
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
                        ?>
                        <span class="label label-<?php echo $payBadge; ?>" style="font-size:12px;padding:5px 10px;">
                            <i class="fa fa-credit-card"></i> <?php echo ucfirst(str_replace('_',' ', $payStatus)); ?>
                        </span>
                        <?php if ($payStatus === 'unpaid' && $b->status != 2): ?>
                        <form method="POST" action="pay-now.php" style="display:inline;margin:0;">
                            <?php tms_csrf_field(); ?>
                            <input type="hidden" name="booking_id" value="<?php echo (int)$b->BookingId; ?>">
                            <button type="submit" class="label label-primary" style="border:none;font-size:12px;padding:5px 10px;cursor:pointer;">
                                <i class="fa fa-flask"></i> Pay Now (Sandbox)
                            </button>
                        </form>
                        <?php endif; ?>
                        <small style="color:#aaa;">Booked: <?php echo htmlspecialchars($b->RegDate, ENT_QUOTES,'UTF-8'); ?></small>
                    </div>
                </div>
                <div class="history-body">
                    <!-- Booking timeline -->
                    <div class="bk-timeline">
                        <div class="step done"><span class="dot"></span> Requested</div>
                        <div class="bar"></div>
                        <?php if ($b->status == 2): ?>
                        <div class="step bad"><span class="dot"></span> Cancelled</div>
                        <?php if ($b->RefundStatus): ?>
                        <div class="bar"></div>
                        <div class="step <?php echo $b->RefundStatus === 'processed' ? 'done' : 'current'; ?>"><span class="dot"></span> Refund <?php echo htmlspecialchars($b->RefundStatus, ENT_QUOTES,'UTF-8'); ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="step <?php echo $b->status == 1 ? 'done' : 'current'; ?>"><span class="dot"></span> Confirmed</div>
                        <div class="bar"></div>
                        <div class="step <?php echo ($b->status == 1 && $fromDate <= $today) ? 'done' : ''; ?>"><span class="dot"></span> Completed</div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div style="flex:1;min-width:220px;">
                            <div class="detail-row">
                                <i class="fa fa-briefcase"></i>
                                <div>
                                    <strong>Package:</strong>
                                    <a href="package-details.php?pkgid=<?php echo (int)$b->PackageId; ?>">
                                        <?php echo htmlspecialchars($b->PackageName, ENT_QUOTES,'UTF-8'); ?>
                                    </a>
                                </div>
                            </div>
                            <div class="detail-row">
                                <i class="fa fa-calendar"></i>
                                <div>
                                    <strong>From:</strong> <?php echo htmlspecialchars($b->FromDate, ENT_QUOTES,'UTF-8'); ?>
                                    &nbsp;&nbsp;<strong>To:</strong> <?php echo htmlspecialchars($b->ToDate, ENT_QUOTES,'UTF-8'); ?>
                                    <?php if (!empty($b->NoOfPersons)): ?>
                                    &nbsp;&nbsp;<strong>Travelers:</strong> <?php echo (int)$b->NoOfPersons; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($b->TotalCost)): ?>
                            <div class="detail-row">
                                <i class="fa fa-money"></i>
                                <div><strong>Total:</strong> <?php echo tms_currency((float)$b->TotalCost); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($b->Comment)): ?>
                            <div class="detail-row">
                                <i class="fa fa-comment-o"></i>
                                <div><strong>Note:</strong> <?php echo htmlspecialchars($b->Comment, ENT_QUOTES,'UTF-8'); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($b->status == 2 && !empty($b->UpdationDate)): ?>
                            <div class="detail-row">
                                <i class="fa fa-clock-o"></i>
                                <div><strong>Cancelled at:</strong> <?php echo htmlspecialchars($b->UpdationDate, ENT_QUOTES,'UTF-8'); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($b->RefundStatus): ?>
                            <span class="refund-badge refund-<?php echo htmlspecialchars($b->RefundStatus, ENT_QUOTES,'UTF-8'); ?>">
                                <i class="fa fa-undo"></i> Refund <?php echo htmlspecialchars(ucfirst($b->RefundStatus), ENT_QUOTES,'UTF-8'); ?>
                            </span>
                            <?php if (!empty($b->RefundNote)): ?><div style="font-size:12px;color:#94a3b8;margin-top:3px;"><?php echo htmlspecialchars($b->RefundNote, ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                            <?php endif; ?>

                            <?php if ($b->ModificationStatus === 'pending'): ?>
                            <span class="mod-badge">
                                <i class="fa fa-exchange"></i> Date change requested: <?php echo htmlspecialchars($b->RequestedFromDate, ENT_QUOTES,'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($b->RequestedToDate, ENT_QUOTES,'UTF-8'); ?> (pending review)
                            </span>
                            <?php elseif ($b->ModificationStatus === 'rejected'): ?>
                            <div style="font-size:12px;color:#e74c3c;margin-top:4px;"><i class="fa fa-times-circle"></i> Last date-change request was declined<?php echo !empty($b->ModificationNote) ? ': '.htmlspecialchars($b->ModificationNote, ENT_QUOTES,'UTF-8') : '.'; ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($b->VerificationCode) && $b->status != 2): ?>
                        <div style="text-align:center;">
                            <div class="qr-thumb tms-qr" style="width:120px;height:120px;display:inline-flex;align-items:center;justify-content:center;background:var(--white);border:1px solid #e2e8f0;border-radius:8px;padding:6px;box-sizing:border-box;"
                                 data-qr-value="TMS-BK<?php echo (int)$b->BookingId; ?>-<?php echo htmlspecialchars($b->VerificationCode, ENT_QUOTES, 'UTF-8'); ?>"></div>
                            <div style="font-size:10px;color:#aaa;margin-top:2px;">Scan to verify</div>
                        </div>
                        <?php endif; ?>

                        <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                            <a href="my-trip.php?id=<?php echo (int)$b->BookingId; ?>" class="ticket-btn" style="background:#0f2027;color:#fff;border-color:#0f2027;">
                                <i class="fa fa-compass"></i> My Trip
                            </a>
                            <?php if ($b->status == 1): ?>
                            <a href="ticket.php?bkid=<?php echo (int)$b->BookingId; ?>" class="ticket-btn">
                                <i class="fa fa-download"></i> Ticket
                            </a>
                            <?php endif; ?>
                            <a href="expense-tracker.php?booking=<?php echo (int)$b->BookingId; ?>" class="ticket-btn" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe;">
                                <i class="fa fa-money"></i> Expenses
                            </a>
                            <a href="wallet.php" class="ticket-btn" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;">
                                <i class="fa fa-briefcase"></i> Wallet
                            </a>
                            <?php if ($canModify): ?>
                            <button type="button" class="modify-btn" onclick="openModModal(<?php echo (int)$b->BookingId; ?>)">
                                <i class="fa fa-exchange"></i> Change Dates
                            </button>
                            <?php endif; ?>
                            <?php if ($canCancel): ?>
                            <form method="post" onsubmit="return confirm('Cancel booking #BK<?php echo (int)$b->BookingId; ?>?');">
                                <?php tms_csrf_field(); ?>
                                <input type="hidden" name="cancel_booking" value="1">
                                <input type="hidden" name="bkid" value="<?php echo (int)$b->BookingId; ?>">
                                <button type="submit" class="cancel-btn">
                                    <i class="fa fa-times"></i> Cancel
                                </button>
                            </form>
                            <?php elseif ($b->status != 2): ?>
                            <button class="cancel-btn" disabled
                                    title="Cannot cancel within 24 hours of departure"
                                    style="opacity:.5;cursor:not-allowed;">
                                <i class="fa fa-times"></i> Cancel
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
                <h3>No bookings yet</h3>
                <p>You haven't booked any tour packages.</p>
                <a href="package-list.php" class="btn btn-primary" style="margin-top:10px;">
                    <i class="fa fa-search"></i> Browse Packages
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Date-change request modal (shared across all cards; openModModal() fills in the booking id) -->
<div class="mod-modal-overlay" id="modModalOverlay">
    <div class="mod-modal">
        <h4><i class="fa fa-exchange" style="color:var(--secondary)"></i> Request Date Change</h4>
        <form method="post">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="request_modification" value="1">
            <input type="hidden" name="bkid" id="modBkId">
            <div class="form-group">
                <label>New Departure Date</label>
                <?php /* Round 14: same bare-text-box problem as the booking form. */ ?>
                <input type="date" name="new_fromdate" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
            </div>
            <div class="form-group">
                <label>New Return Date</label>
                <input type="date" name="new_todate" class="form-control" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
            </div>
            <p style="font-size:12px;color:#94a3b8;margin-bottom:16px;">
                This sends a request to our team — dates won't change until it's reviewed and approved,
                since availability and pricing need a quick check first.
            </p>
            <div style="display:flex;gap:10px;">
                <button type="button" onclick="closeModModal()" class="ticket-btn" style="flex:1;justify-content:center;">Cancel</button>
                <button type="submit" class="modify-btn" style="flex:1;background:var(--secondary);color:#fff;">Submit Request</button>
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

<?php include('includes/footer.php'); ?>
<?php include('includes/write-us.php'); ?>
</body>
</html>
