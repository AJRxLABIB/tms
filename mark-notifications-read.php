<?php
/**
 * mark-notifications-read.php — tiny AJAX endpoint called from the topbar
 * notification bell (includes/header.php) the moment a signed-in visitor
 * opens their notification dropdown. Marks all of that user's notifications
 * as read. No response body is needed — the browser fires this and moves on.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['login'])) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

try {
    $dbh->prepare("UPDATE tbl_notifications SET IsRead=1 WHERE UserEmail=:e AND IsRead=0")
        ->execute([':e' => $_SESSION['login']]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('[TMS Notify] mark-read failed: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}
exit;
