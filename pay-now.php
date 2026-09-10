<?php
/**
 * pay-now.php — lets a signed-in user pay for an already-existing Pending/
 * unpaid booking from tour-history.php, using the same payment.php flow
 * checkout.php uses for a brand-new booking. Ownership is verified before
 * anything else happens (Rule 44 -- no IDOR via a guessed booking_id).
 */
require('includes/config.php');
tms_require_user();
tms_csrf_validate();

$email = $_SESSION['login'];
$bookingId = (int)($_POST['booking_id'] ?? 0);

if ($bookingId > 0) {
    $check = $dbh->prepare("SELECT BookingId FROM tblbooking WHERE BookingId = :id AND UserEmail = :email");
    $check->execute([':id' => $bookingId, ':email' => $email]);
    if ($check->fetchColumn()) {
        $_SESSION['pending_payment_booking_ids'] = [$bookingId];
    }
}

header('Location: payment.php');
exit;
