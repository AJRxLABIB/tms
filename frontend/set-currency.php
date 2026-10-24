<?php
/**
 * set-currency.php — AJAX endpoint for the global "See Price In" currency
 * selector (Task 5 fix). Persists the visitor's chosen display currency in
 * the session; every subsequent tms_currency() call across the app (package
 * cards, package details, cart, checkout, wishlist, compare, budget
 * planner...) reads it from there and converts+formats accordingly. Mirrors
 * the pattern already used by language.php for the language switcher.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
header('Content-Type: application/json');

$code = strtoupper(trim($_POST['currency'] ?? ''));

if (!array_key_exists($code, TMS_CURRENCIES)) {
    echo json_encode(['ok' => false, 'msg' => 'Unsupported currency']);
    exit;
}

$_SESSION['currency'] = $code;
echo json_encode(['ok' => true, 'currency' => $code]);
