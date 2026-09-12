<?php
session_start();

// Audit log coverage (Round 52): admin/index.php already logs every
// 'Admin Login' (and failed login/2FA attempt), so a session ending with no
// matching 'Admin Logout' line left the trail one-sided -- no way to tell
// how long a session lasted, or whether it ended normally vs. just expired.
// Capture the actor and log it before the session is wiped below; this is
// wrapped defensively since a logout must always succeed even if logging
// can't (e.g. DB unreachable) -- same "never fail the caller" principle as
// tms_log_activity()'s own try/catch.
if (!empty($_SESSION['alogin'])) {
    try {
        $actor = $_SESSION['alogin'];
        include('includes/config.php');
        tms_log_activity($dbh, $actor, 'Admin Logout', 'Session ended');
    } catch (Throwable $e) { /* logging failure must never block logout */ }
}

$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: index.php");
exit;
?>
