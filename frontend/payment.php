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
require('backend/config.php');
require_once('backend/payments/PaymentProviderInterface.php');
require_once('backend/payments/SandboxProvider.php');
require_once('backend/payments/PaymentService.php');

tms_require_user();
$email = $_SESSION['login'];

$bookingIds = $_SESSION['pending_payment_booking_ids'] ?? [];
$bookingIds = array_values(array_filter(array_map('intval', (array)$bookingIds)));

$paymentService = new PaymentService($dbh);
$bookings = [];
// Round 84 (master prompt items 8/37 -- "never use floating-point
// arithmetic for money"): TotalCost is a whole-currency-unit INT column
// (no cents/paisa subunit at this layer) -- (int) is exact here. The
// (float) cast a few lines down into PaymentService::createPendingPayment()
// is left as-is: that function's float parameter is a deliberate, contained
// conversion boundary into AmountMinor (integer minor units), same as
// tms_dynamic_price()'s $basePrice parameter elsewhere.
$totalAmount = 0;
$error = '';

if (empty($bookingIds)) {
    $error = tms_t('msg_nothing_pending_payment', "There's nothing waiting for payment right now.");
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
        $error = tms_t('msg_could_not_verify_bookings', "We couldn't verify one or more of those bookings for your account.");
        $bookings = [];
    } else {
        foreach ($bookings as $b) {
            $totalAmount += (int)$b->TotalCost;
        }
    }
}

$sandboxOk = PaymentService::sandboxAllowed();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$sandboxOk) {
    tms_csrf_validate();
    $error = tms_t('msg_online_payment_unavailable', 'Online payment is not available yet. Your booking is saved as Pending and our team will contact you to arrange payment.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate(); // exits with 403 on a bad/missing token -- see backend/config.php
    if (!empty($bookings)) {
        $allOk = true; $refusedBooking = false;
        try {
            foreach ($bookings as $b) {
                $payment = $paymentService->createPendingPayment((int)$b->BookingId, (float)$b->TotalCost);
                $confirmed = $paymentService->confirmSandboxPayment((int)$payment['PaymentId'], $email);
                $allOk = $allOk && $confirmed;
            }
        } catch (DomainException $e) {
            // cancelled / already paid / vanished booking: nothing is charged for it
            error_log('[TourBuddy Payments] payment.php refused: ' . $e->getMessage());
            $allOk = false; $refusedBooking = true;
        } catch (PDOException $e) {
            error_log('[TourBuddy Payments] payment.php: payment tables unavailable (run database/migrate.php): ' . $e->getMessage());
            $allOk = false;
        }
        unset($_SESSION['pending_payment_booking_ids']);
        if ($allOk) {
            $_SESSION['payment_success'] = count($bookings);
            header('Location: tour-history.php');
            exit;
        } else {
            $error = $refusedBooking ? 'One of these bookings is already paid or has been cancelled, so no payment was taken for it. Please check My Tours.' : 'Payment could not be processed right now, but your booking itself was already saved as Pending -- our team can confirm it manually. (Site operator: this usually means database/migrate.php has not been run yet.)';
        }
    }
}
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | Payment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<link rel="stylesheet" href="css/tms-account.css?v=1">
<style>.pay-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px dashed var(--tms-border);font-size:15px}</style>
</head><body>
<?php include('backend/header.php'); ?>
<main class="tms-acct" style="max-width:640px;">
  <ol class="tms-stepper" aria-label="Payment progress">
    <li class="done"><span>Details</span></li>
    <li class="now" aria-current="step"><span>Pay</span></li>
    <li><span>Done</span></li>
  </ol>
  <h1 style="font-size:28px;font-weight:800;margin:0 0 18px;"><?php echo tms_t('heading_payment','Payment'); ?></h1>
  <?php if ($error): ?>
    <div class="tms-alert bad" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <p><a class="tms-btn-ghost" href="tour-history.php"><?php echo tms_t('link_go_to_my_trips','Go to My Trips'); ?></a></p>
  <?php else: ?>
  <section class="tms-card">
    <?php if ($sandboxOk): ?>
    <div class="tms-sandbox"><i class="fa fa-flask" aria-hidden="true"></i> Test mode: no real money moves</div>
    <p style="font-size:13.5px;color:var(--tms-muted);margin:0 0 14px;"><?php echo tms_t('dest_sandbox_payment_banner', '<strong>Sandbox payment mode.</strong> No real payment provider is connected yet (no merchant account exists for this project), so no real money moves here. This is a fully working test-mode flow so booking confirmation isn\'t purely a manual admin step anymore.'); ?></p>
    <?php endif; ?>
    <?php foreach ($bookings as $b): ?>
      <div class="pay-row"><span><?php echo htmlspecialchars($b->PackageName ?: ('Booking #'.$b->BookingId), ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)$b->NoOfPersons; ?> <?php echo tms_t('label_traveller_word','traveler(s)'); ?>)</span><span><?php echo tms_currency((int)$b->TotalCost); ?></span></div>
    <?php endforeach; ?>
    <div class="tms-summary"><div class="row total"><span><?php echo tms_t('label_total','Total'); ?></span><span><?php echo tms_currency($totalAmount); ?></span></div></div>
    <?php if ($sandboxOk): ?>
    <form method="POST">
      <?php tms_csrf_field(); ?>
      <button type="submit" class="tms-btn-pay"><i class="fa fa-lock" aria-hidden="true"></i> <?php echo tms_t('label_pay_prefix','Pay'); ?> <?php echo tms_currency($totalAmount); ?> <?php echo tms_t('label_sandbox_suffix','(Sandbox)'); ?></button>
    </form>
    <?php else: ?>
    <div class="tms-alert ok" style="margin-top:18px;"><i class="fa fa-info-circle" aria-hidden="true"></i> <span><?php echo htmlspecialchars(tms_t('msg_online_payment_unavailable', 'Online payment is not available yet. Your booking is saved as Pending and our team will contact you to arrange payment.'), ENT_QUOTES, 'UTF-8'); ?></span></div>
    <?php endif; ?>
    <p style="font-size:13px;color:var(--tms-muted);margin-top:14px;text-align:center;"><?php echo tms_t('dest_skip_payment_note', 'You can also skip this — your booking stays Pending and our team can confirm it manually, exactly like before.'); ?></p>
  </section>
  <?php endif; ?>
</main>
<?php include('backend/footer.php'); ?>
</body></html>
