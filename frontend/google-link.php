<?php
/**
 * google-link.php — an existing TourBuddy account has the same e-mail as the Google account. Google is only attached after the visitor
 * proves they own the TourBuddy account by entering its password (someone could have registered this address here beforehand).
 */
require_once 'backend/config.php';
require_once 'backend/auth/GoogleAuth.php';
header('Cache-Control: no-store');
if (!empty($_SESSION['login'])) { header('Location: index.php'); exit; }
$p = $_SESSION['google_link_pending'] ?? null;
if (!is_array($p) || time() - (int)($p['t'] ?? 0) > GoogleAuth::STATE_TTL) { unset($_SESSION['google_link_pending']); header('Location: login.php'); exit; }
if (isset($_GET['cancel'])) { unset($_SESSION['google_link_pending']); header('Location: login.php'); exit; }

$email = (string)$p['claims']['email']; $error = ''; $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $wait = tms_login_lockout_minutes_remaining($dbh, $email, $ip);
    if ($wait > 0) {
        $error = "Too many failed attempts. Please try again in {$wait} minute" . ($wait === 1 ? '' : 's') . '.';
    } else {
        $st = $dbh->prepare('SELECT id, EmailId, FullName, Password, DeletedAt, TwoFAEnabled, TwoFASecret FROM tblusers WHERE id = ? AND EmailId = ? LIMIT 1');
        $st->execute([(int)$p['user_id'], $email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && empty($u['DeletedAt']) && tms_verify_password((string)($_POST['Password'] ?? ''), (string)$u['Password'], (int)$u['id'], $dbh)) {
            tms_clear_login_attempts($dbh, $email, $ip);
            GoogleAuth::link($dbh, (int)$u['id'], $p['claims']);
            unset($_SESSION['google_link_pending']);
            header('Location: ' . GoogleAuth::startSession($u, tms_safe_redirect((string)$p['redirect']))); exit;
        }
        tms_record_failed_login($dbh, $email, $ip);
        $error = 'That password is not correct.';
    }
}
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Connect Google — TourBuddy</title>
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0E3B2D,#104B3A 55%,#176B50);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}
    .card{background:#fff;border-radius:16px;max-width:420px;width:100%;padding:28px 32px;box-shadow:0 24px 60px rgba(0,0,0,.4)}
    h1{font-size:20px;margin:0 0 8px;color:#104B3A} p{font-size:14px;color:#4a5568;line-height:1.5}
    label{display:block;font-size:12px;font-weight:700;color:#4a5568;text-transform:uppercase;margin:16px 0 6px}
    input{width:100%;box-sizing:border-box;height:44px;border:2px solid #e2e8f0;border-radius:8px;padding:0 12px;font-size:14px}
    input:focus{border-color:#176B50;outline:none}
    button{margin-top:16px;width:100%;height:46px;border:0;border-radius:8px;background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;font-weight:700;font-size:15px;cursor:pointer}
    .err{background:#fff5f5;color:#c53030;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:12px}
    a{color:#176B50;font-weight:700;font-size:13px}
  </style>
</head>
<body>
<main class="card">
  <h1>Connect your Google account</h1>
  <p>A TourBuddy account already exists for <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>. Enter its TourBuddy password once to confirm it is yours; after that you can sign in with Google.</p>
  <?php if ($error): ?><div class="err" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
  <form method="POST" action="google-link.php">
    <?php tms_csrf_field(); ?>
    <label for="pw">TourBuddy password</label>
    <input type="password" id="pw" name="Password" required autofocus autocomplete="current-password" maxlength="100">
    <button type="submit">Connect and sign in</button>
  </form>
  <p style="margin-top:16px"><a href="forgot-password.php">Forgot your password?</a> &nbsp;·&nbsp; <a href="google-link.php?cancel=1">Cancel</a></p>
</main>
</body>
</html>
