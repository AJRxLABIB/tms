<?php
/**
 * payment.php — the new step between "Confirm Booking" and "My Trip"
 * (Rule 10: Search -> Package -> ... -> Review -> Payment -> Confirmation).
 *
 * Before this file existed, checkout.php created a booking and sent the
 * customer straight to tour-history.php with status=0 (Pending) and no
 * payment step of any kind -- confirmation was a manual admin action only.
 * That still works exactly as before (nothing here removes it -- Rule 2),
 * but now a customer can also pay immediately through the sandbox provider,
 * which marks the booking PaymentStatus='paid' and auto-confirms it
 * (status=1). If they close this page without paying, the booking stays
 * Pending/unpaid exactly like every booking did before this file existed,
 * and an admin can still confirm it by hand from admin/manage-bookings.php.
 *
 * The booking IDs to pay for come from $_SESSION (set by checkout.php right
 * after creating them), never from the URL/query string, and every booking
 * looked up here is re-checked against the signed-in user's own email
 * before anything is shown or touched (Rule 44 -- no IDOR via guessed
 * booking IDs).
 */
require('includes/config.php');
require_once('includes/payments/PaymentProviderInterface.php');
require_once('includes/payments/SandboxProvider.php');
require_once('includes/payments/PaymentService.php');

tms_require_user();
$email = $_SESSION['login'];

$bookingIds = $_SESSION['pending_payment_booking_ids'] ?? [];
$bookingIds = array_values(array_filter(array_map('intval', (array)$bookingIds)));

$paymentService = new PaymentService($dbh);
$bookings = [];
$totalFloat = 0.0;
$error = '';

if (empty($bookingIds)) {
    $error = "There's nothing waiting for payment right now.";
} else {
    $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
    $stmt = $dbh->prepare(
        "SELECT b.*, p.PackageName FROM tblbooking b
         LEFT JOIN tbltourpackages p ON p.PackageId = b.PackageId
         WHERE b.BookingId IN ($placeholders) AND b.UserEmail = ?"
    );
    $stmt->execute([...$bookingIds, $email]);
    $bookings = $stmt->fetchAll(PDO::FETCH_OBJ);

    if (count($bookings) !== count($bookingIds)) {
        // Some requested ID didn't belong to this user or doesn't exist --
        // fail closed rather than silently showing a partial/wrong total.
        $error = "We couldn't verify one or more of those bookings for your account.";
        $bookings = [];
    } else {
        foreach ($bookings as $b) {
            $totalFloat += (float)$b->TotalCost;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate(); // exits with 403 on a bad/missing token -- see includes/config.php
    if (!empty($bookings)) {
        $allOk = true;
        try {
            foreach ($bookings as $b) {
                $payment = $paymentService->createPendingPayment((int)$b->BookingId, (float)$b->TotalCost);
                $confirmed = $paymentService->confirmSandboxPayment((int)$payment['PaymentId'], $email);
                $allOk = $allOk && $confirmed;
            }
        } catch (PDOException $e) {
            error_log('[TMS Payments] payment.php: payment tables unavailable (run database/migrate.php): ' . $e->getMessage());
            $allOk = false;
        }
        unset($_SESSION['pending_payment_booking_ids']);
        if ($allOk) {
            $_SESSION['payment_success'] = count($bookings);
            header('Location: tour-history.php');
            exit;
        } else {
            $error = 'Payment could not be processed right now, but your booking itself was already saved as Pending -- our team can confirm it manually. (Site operator: this usually means database/migrate.php has not been run yet.)';
        }
    }
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | Payment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.sandbox-banner{background:#fff7e6;border:1px solid #f0c36d;color:#8a6100;border-radius:10px;padding:14px 18px;font-size:13.5px;margin-bottom:22px;}
.pay-box{background:var(--white);border-radius:12px;padding:26px;box-shadow:0 3px 15px rgba(0,0,0,0.08);max-width:560px;margin:0 auto;}
.pay-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.pay-total{display:flex;justify-content:space-between;font-size:19px;font-weight:800;color:#1e293b;border-top:2px solid #eee;margin-top:14px;padding-top:16px;}
html[data-theme="dark"] .pay-total{color:var(--text) !important;}
.btn-pay{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:14px;font-weight:700;font-size:15px;width:100%;cursor:pointer;margin-top:18px;}
.btn-pay:hover{background:#2d6a97;}
</style>
</head><body>
<?php include('includes/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-credit-card"></i> Payment</h2></div></div>
<div class="container" style="margin-bottom:60px;">
  <div class="sandbox-banner">
    <i class="fa fa-flask"></i> <strong>Sandbox payment mode.</strong> No real payment provider is connected yet (no merchant account exists for this project), so no real money moves here. This is a fully working test-mode flow so booking confirmation isn't purely a manual admin step anymore.
  </div>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <p><a href="tour-history.php">Go to My Trips</a></p>
  <?php else: ?>
  <div class="pay-box">
    <?php foreach ($bookings as $b): ?>
      <div class="pay-row"><span><?php echo htmlspecialchars($b->PackageName ?: ('Booking #'.$b->BookingId), ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)$b->NoOfPersons; ?> traveler<?php echo ((int)$b->NoOfPersons===1?'':'s'); ?>)</span><span><?php echo tms_currency((float)$b->TotalCost); ?></span></div>
    <?php endforeach; ?>
    <div class="pay-total"><span>Total</span><span><?php echo tms_currency($totalFloat); ?></span></div>
    <form method="POST">
      <?php tms_csrf_field(); ?>
      <button type="submit" class="btn-pay"><i class="fa fa-flask"></i> Pay <?php echo tms_currency($totalFloat); ?> (Sandbox)</button>
    </form>
    <p style="font-size:12px;color:#888;margin-top:14px;text-align:center;">You can also skip this — your booking stays Pending and our team can confirm it manually, exactly like before.</p>
  </div>
  <?php endif; ?>
</div>
<?php include('includes/footer.php'); ?>
</body></html>
