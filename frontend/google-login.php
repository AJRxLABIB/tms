<?php
/** google-login.php — starts "Sign in with Google" (see backend/auth/GoogleAuth.php). */
require_once 'backend/config.php';
require_once 'backend/auth/GoogleAuth.php';
if (!empty($_SESSION['login'])) { header('Location: index.php'); exit; }
if (!GoogleAuth::enabled()) {
    $_SESSION['login_error'] = 'Google sign-in is not available on this site.';
    header('Location: login.php'); exit;
}
$redirect = tms_safe_redirect((string)($_GET['redirect'] ?? ($_SESSION['post_login_redirect'] ?? '')));
header('Cache-Control: no-store');
header('Location: ' . GoogleAuth::begin($redirect));
exit;
