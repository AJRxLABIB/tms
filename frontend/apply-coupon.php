<?php
/**
 * NOTE (Round 2 audit): this endpoint had zero callers anywhere in the
 * codebase — package-details.php's coupon UI only ever called
 * coupon-check.php (preview) and never this one, so a coupon could be
 * checked but never actually applied or marked used. Coupon application now
 * happens inline inside package-details.php's booking-submit handler, in the
 * same request that creates the booking, which is more robust than a
 * separate call keyed to a booking ID that didn't exist yet at the time the
 * old UI would have needed to call this. Left in place, unchanged, in case
 * anything external depends on it — but nothing in this codebase calls it.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
header('Content-Type: application/json');

$code = tms_clean($_POST['code'] ?? '');
$bookingId = tms_int($_POST['booking_id'] ?? 0, 1);
$email = $_SESSION['login'] ?? '';

if (!$code || !$email || !$bookingId) { echo json_encode(['success'=>false]); exit; }

$q = $dbh->prepare("SELECT * FROM tblcoupons WHERE Code=:c AND UserEmail=:e AND IsUsed=0 AND (ExpiryDate IS NULL OR ExpiryDate >= CURDATE()) LIMIT 1");
$q->execute([':c'=>$code,':e'=>$email]);
$coupon = $q->fetch(PDO::FETCH_OBJ);

if ($coupon) {
    $dbh->prepare("UPDATE tblcoupons SET IsUsed=1 WHERE id=:id")->execute([':id'=>$coupon->id]);
    $dbh->prepare("UPDATE tblbooking SET CouponCode=:c, DiscountApplied=:d WHERE BookingId=:bid AND UserEmail=:e")
        ->execute([':c'=>$code,':d'=>$coupon->DiscountPercent,':bid'=>$bookingId,':e'=>$email]);
    echo json_encode(['success'=>true,'discount'=>$coupon->DiscountPercent]);
} else {
    echo json_encode(['success'=>false,'msg'=>'Coupon invalid or already used.']);
}
