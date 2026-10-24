<?php
require_once __DIR__ . '/../backend/session-boot.php';
// Logout needs proof it was requested from our own page: the link carries the session's CSRF token (?t=) or the form posts it.
// A bare GET (an <img> or link on another site) shows a confirmation button instead of signing the visitor out.
$tok = (string)($_SESSION['csrf_token'] ?? '');
$given = (string)($_POST['csrf_token'] ?? $_GET['t'] ?? '');
if (!empty($_SESSION['login']) && ($tok === '' || !hash_equals($tok, $given))) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign out</title>'
       . '<body style="font-family:sans-serif;text-align:center;padding:60px 16px"><form method="post"><p>Sign out of your account?</p>'
       . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($tok, ENT_QUOTES, 'UTF-8') . '">'
       . '<button type="submit" style="padding:10px 22px;font-size:16px">Sign out</button> <a href="index.php">Cancel</a></form></body>';
    exit;
}
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 60*60,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
unset($_SESSION['login']);
session_destroy(); // destroy session
header("location:index.php");
exit;
?>

