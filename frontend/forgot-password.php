<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');

/**
 * SECURITY FIX (Round 66, master prompt item 44): this page used to "verify"
 * identity with only a registered email + mobile number -- both are
 * guessable or easily obtainable facts about a person, not secrets -- and
 * reset the password immediately in that same request. Anyone who knew (or
 * found, e.g. on a business card or a data-broker leak) a customer's email
 * and phone number could take over their account outright, with zero access
 * to the victim's inbox or phone, and with no rate limiting at all.
 *
 * Now a two-step flow, same shape as every reputable reset flow and
 * consistent with this project's own tblapi_tokens pattern (api/index.php):
 *   Step 1 (request): email only. A single-use, cryptographically random,
 *   time-limited token is generated, its SHA-256 hash stored in
 *   tbl_password_resets, and the RAW token emailed as a link to the
 *   account's registered address (tms_send_email/tms_email_template,
 *   already imported via config.php). The response is identical whether or
 *   not the email matches an account, so this page can no longer be used to
 *   enumerate registered emails. Requests are rate-limited via the same
 *   tbl_login_attempts/tms_login_lockout_minutes_remaining() helpers
 *   login.php already uses, namespaced with a "pwreset:" prefix (same
 *   technique admin/index.php uses for its "admin:"/"2fa:" prefixes) so this
 *   can't be used to spam an inbox either.
 *   Step 2 (reset): the emailed link carries the token; only a valid,
 *   unused, unexpired token unlocks the new-password form. On success the
 *   token (and any other outstanding tokens for that account) are marked
 *   used, the normal login lockout counters are cleared, and a
 *   confirmation email is sent -- all exactly as before.
 */

const TMS_PWRESET_TOKEN_TTL_MINUTES = 30;

$error = '';
$msg   = '';
$token = tms_clean($_GET['token'] ?? $_POST['token'] ?? '');

// ── Step 2: a token is present -- validate it before showing the new-password form ──
$resetRow = null;
if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $q = $dbh->prepare("SELECT id, UserEmail, ExpiresAt FROM tbl_password_resets
                         WHERE TokenHash = :h AND UsedAt IS NULL AND ExpiresAt > NOW() LIMIT 1");
    $q->execute([':h' => $tokenHash]);
    $resetRow = $q->fetch(PDO::FETCH_OBJ);
    if (!$resetRow) {
        $error = 'This password reset link is invalid or has expired. Please request a new one below.';
        $token = ''; // fall back to the request form
    }
}

// ── Step 1 handler: request a reset link ──
if (isset($_POST['submit_request'])) {
    tms_csrf_validate();

    $email = tms_validate_email($_POST['email'] ?? '');
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (empty($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $lockoutKey  = 'pwreset:' . strtolower($email);
        $waitMinutes = tms_login_lockout_minutes_remaining($dbh, $lockoutKey, $ip);
        if ($waitMinutes > 0) {
            $error = "Too many reset requests. Please try again in {$waitMinutes} minute"
                   . ($waitMinutes === 1 ? '' : 's') . '.';
        } else {
            // Every request counts against the throttle, whether or not the
            // email matches an account -- otherwise an attacker could tell a
            // real email from a fake one by whether they get locked out.
            tms_record_failed_login($dbh, $lockoutKey, $ip);

            $uq = $dbh->prepare("SELECT id, EmailId, FullName FROM tblusers WHERE EmailId = :e LIMIT 1");
            $uq->execute([':e' => $email]);
            $user = $uq->fetch(PDO::FETCH_OBJ);

            if ($user) {
                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);

                // FIX (Round 66 live testing): ExpiresAt used to be computed
                // with PHP's date()/strtotime() and later compared against
                // MySQL's NOW() (see the "ExpiresAt > NOW()" checks below).
                // That's only correct if PHP's configured timezone and the
                // MySQL server's clock agree -- verified live that they
                // don't on this environment (MySQL is several hours ahead
                // of PHP), which made a token appear already-expired the
                // instant it was issued. Computing the expiry in SQL keeps
                // both the write and every later read on the same MySQL
                // clock, so PHP's own timezone setting can't desync them.
                $dbh->prepare("INSERT INTO tbl_password_resets(UserEmail, TokenHash, ExpiresAt)
                               VALUES(:e, :h, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))")
                    ->execute([':e' => $user->EmailId, ':h' => $tokenHash, ':ttl' => TMS_PWRESET_TOKEN_TTL_MINUTES]);

                $scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base         = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                $resetLink    = "$base/forgot-password.php?token=" . urlencode($rawToken);

                tms_send_email($user->EmailId, $user->FullName, 'Reset your TMS password',
                    tms_email_template('Reset your password', '
                        <p>We received a request to reset your TMS account password. This link is valid for '
                        . TMS_PWRESET_TOKEN_TTL_MINUTES . ' minutes and can only be used once:</p>
                        <p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '"
                              style="display:inline-block;background:#3F84B1;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;font-weight:700;">Reset Password</a></p>
                        <p style="font-size:12px;color:#94a3b8;">If you did not request this, you can safely ignore this email -- your password will not change.</p>')
                );
                tms_log_system_event($dbh, 'auth', 'password_reset_requested', true, $user->EmailId);
            } else {
                tms_log_system_event($dbh, 'auth', 'password_reset_requested_unknown_email', false, $email);
            }

            // Identical response whether or not the account exists.
            $msg = "If an account exists for that email address, we've sent a link to reset your password. "
                 . 'It will expire in ' . TMS_PWRESET_TOKEN_TTL_MINUTES . ' minutes.';
        }
    }
}

// ── Step 2 handler: submit a new password against a validated token ──
if (isset($_POST['submit_reset'])) {
    tms_csrf_validate();

    $postedToken = tms_clean($_POST['token'] ?? '');
    $newPass     = $_POST['newpassword']     ?? '';
    $confirmPass = $_POST['confirmpassword'] ?? '';

    $tokenHash = hash('sha256', $postedToken);
    $q = $dbh->prepare("SELECT id, UserEmail FROM tbl_password_resets
                         WHERE TokenHash = :h AND UsedAt IS NULL AND ExpiresAt > NOW() LIMIT 1");
    $q->execute([':h' => $tokenHash]);
    $row = $q->fetch(PDO::FETCH_OBJ);

    if (!$row) {
        $error = 'This password reset link is invalid or has expired. Please request a new one below.';
        $token = '';
    } elseif (empty($newPass) || empty($confirmPass)) {
        $error = 'Please enter and confirm your new password.';
        $token = $postedToken;
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters.';
        $token = $postedToken;
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirm password do not match.';
        $token = $postedToken;
    } else {
        $newHash = tms_hash_password($newPass);
        $dbh->prepare("UPDATE tblusers SET Password = :pwd WHERE EmailId = :email")
            ->execute([':pwd' => $newHash, ':email' => $row->UserEmail]);

        // This token, and any other outstanding reset links for the same
        // account, are now spent -- an old email link can't be replayed
        // after a reset has already gone through.
        $dbh->prepare("UPDATE tbl_password_resets SET UsedAt = NOW() WHERE UserEmail = :e AND UsedAt IS NULL")
            ->execute([':e' => $row->UserEmail]);

        // A successful reset is also a legitimate reason to clear any
        // accumulated failed-login lockout for this account.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        tms_clear_login_attempts($dbh, $row->UserEmail, $ip);
        tms_clear_login_attempts($dbh, 'pwreset:' . strtolower($row->UserEmail), $ip);

        tms_send_email($row->UserEmail, '', 'Your TMS password was changed',
            tms_email_template('Password changed', '<p>This confirms that your TMS account password was just reset. If you did not do this, please contact support immediately.</p>')
        );
        tms_log_system_event($dbh, 'auth', 'password_reset_completed', true, $row->UserEmail);

        $msg   = 'Your password has been reset successfully. You can now sign in.';
        $token = '';
    }
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Recover Password</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.recover-box{max-width:440px;background:var(--white);border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.09);padding:32px;margin:0 auto;}
.recover-box h4{font-size:1.05em;font-weight:700;color:#444;margin-bottom:20px;}
.recover-box .form-group{margin-bottom:16px;}
.recover-box label{font-weight:600;color:#555;font-size:13px;margin-bottom:4px;display:block;}
.recover-box .form-control{border:2px solid #e0e0e0;border-radius:7px;height:42px;font-size:14px;}
.recover-box .form-control:focus{border-color:var(--secondary);box-shadow:none;outline:none;}
.btn-recover{background:var(--secondary);color:#fff;border:none;border-radius:7px;padding:11px 30px;font-weight:700;font-size:14px;cursor:pointer;transition:.2s;}
.btn-recover:hover{background:#2d6a97;}
.tms-page-hero{position:relative;min-height:220px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(15,163,163,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(28px,4vw,42px);font-weight:600;margin:0;}
.recover-box{background:rgba(255,255,255,0.7);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  border:1px solid rgba(255,255,255,0.6);box-shadow:var(--shadow-md);margin-top:-60px;position:relative;z-index:3;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tms-page-hero">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s">Recover Your Password</h1>
</div>

<div class="privacy">
    <div class="container">
        <div class="recover-box wow fadeInUp" data-wow-delay=".1s">

            <?php if ($resetRow && !$msg): ?>
                <h4><i class="fa fa-lock" style="color:var(--secondary)"></i> Choose a New Password</h4>
                <p style="font-size:13px;color:#888;margin-bottom:20px;">
                    Resetting the password for <strong><?php echo htmlspecialchars($resetRow->UserEmail, ENT_QUOTES, 'UTF-8'); ?></strong>.
                </p>

                <?php if ($error): ?>
                    <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
                <?php endif; ?>

                <form method="post">
                    <?php tms_csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-group">
                        <label>New Password * <small class="text-muted">(min 6 characters)</small></label>
                        <input type="password" name="newpassword" class="form-control"
                               placeholder="New Password" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password *</label>
                        <input type="password" name="confirmpassword" class="form-control"
                               placeholder="Repeat new password" required autocomplete="new-password">
                    </div>
                    <button type="submit" name="submit_reset" class="btn-recover">
                        <i class="fa fa-key"></i> Set New Password
                    </button>
                </form>

            <?php else: ?>
                <h4><i class="fa fa-lock" style="color:var(--secondary)"></i> Reset Password</h4>
                <p style="font-size:13px;color:#888;margin-bottom:20px;">
                    Enter your registered email and we'll send you a link to reset your password.
                </p>

                <?php if ($error): ?>
                    <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
                <?php elseif ($msg): ?>
                    <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?>
                        <br><a href="index.php" style="color:#27ae60;font-weight:600;">← Back to Home &amp; Sign In</a>
                    </div>
                <?php endif; ?>

                <?php if (!$msg): ?>
                <form method="post">
                    <?php tms_csrf_field(); ?>
                    <div class="form-group">
                        <label>Registered Email *</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="your@email.com" required autocomplete="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                    </div>
                    <button type="submit" name="submit_request" class="btn-recover">
                        <i class="fa fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>
                <?php endif; ?>
            <?php endif; ?>

            <p style="margin-top:18px;font-size:13px;color:#aaa;">
                Remember your password?
                <a href="#" data-toggle="modal" data-target="#myModal4" style="color:var(--secondary);font-weight:600;">Sign In</a>
            </p>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>
