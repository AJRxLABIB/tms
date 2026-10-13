<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
header('Content-Type: application/json');

$code = tms_clean($_POST['code'] ?? '');
$email = $_SESSION['login'] ?? '';

if (!$code || !$email) { echo json_encode(['valid'=>false,'msg'=>'Invalid request']); exit; }

$q = $dbh->prepare("SELECT * FROM tblcoupons WHERE Code=:c AND (UserEmail=:e OR UserEmail IS NULL) AND IsUsed=0 AND (ExpiryDate IS NULL OR ExpiryDate >= CURDATE()) LIMIT 1");
$q->execute([':c'=>$code,':e'=>$email]);
$coupon = $q->fetch(PDO::FETCH_OBJ);

if ($coupon) {
    echo json_encode(['valid'=>true,'discount'=>$coupon->DiscountPercent,'msg'=>'Valid! '.$coupon->DiscountPercent.'% discount will be applied when you book.']);
} else {
    echo json_encode(['valid'=>false,'msg'=>'Invalid or expired coupon code.']);
}
