<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

/**
 * export-my-data.php — streams the logged-in user's own data (see
 * tms_export_user_data() in backend/config.php) as a downloadable JSON
 * file. Master prompt spec item 48 ("data export").
 *
 * GET only, login-gated by tms_require_user() -- no separate confirmation
 * needed since this is read-only and scoped entirely to $_SESSION['login'],
 * never a parameter, so there's no way to request someone else's data.
 */

$email = $_SESSION['login'];
$data = tms_export_user_data($dbh, $email);

tms_log_activity($dbh, $email, 'Data Export', 'Downloaded a personal data export via export-my-data.php.');

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$filename = 'tms-my-data-' . date('Ymd') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-store, private');
echo $json;
