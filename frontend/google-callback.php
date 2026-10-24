<?php
/** google-callback.php — Google redirects here after consent (see backend/auth/GoogleAuth.php). */
require_once 'backend/config.php';
require_once 'backend/auth/GoogleAuth.php';
header('Cache-Control: no-store');
if (!empty($_SESSION['login'])) { header('Location: index.php'); exit; }
$fail = function (string $msg) { $_SESSION['login_error'] = $msg; header('Location: login.php'); exit; };
if (!GoogleAuth::enabled()) $fail('Google sign-in is not available on this site.');

$r = GoogleAuth::complete($dbh, $_GET);
if (!$r['ok']) {
    error_log('[TMS Google] sign-in refused: ' . ($r['error'] ?? 'unknown'));
    $fail($r['error'] === 'denied' ? 'Google sign-in was cancelled.' : 'Google sign-in could not be completed. Please try again.');
}
try { GoogleAuth::migrate($dbh); } catch (Throwable $e) {}
$res = GoogleAuth::resolveUser($dbh, $r['claims']);
$safe = tms_safe_redirect($r['redirect']);
switch ($res['status']) {
    case 'login':
    case 'created':
        header('Location: ' . GoogleAuth::startSession($res['user'], $safe)); exit;
    case 'link_required':
        // An account with this e-mail already exists here: the visitor must prove they own it before Google is attached.
        $_SESSION['google_link_pending'] = ['user_id' => (int)$res['user']['id'], 'claims' => $r['claims'], 'redirect' => $safe, 't' => time()];
        header('Location: google-link.php'); exit;
    default:
        $fail('This account cannot sign in with Google.');
}
