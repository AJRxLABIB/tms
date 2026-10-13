<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');

if(!empty($_SESSION['alogin'])){ header('location:dashboard.php'); exit; }

if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['a2fa_pending']);
    header('location:index.php');
    exit;
}

/**
 * SECURITY HARDENING (Phase 1 -- Admin/Auth):
 * This admin login previously had NO brute-force protection at all (unlike
 * the customer-facing login.php, which already used tms_login_lockout_
 * minutes_remaining()/tms_record_failed_login()/tms_clear_login_attempts()),
 * no CSRF token on its form, and its own bespoke password check instead of
 * the shared tms_verify_password() helper (which also auto-upgrades legacy
 * MD5 hashes to bcrypt on successful login). All three are fixed here by
 * reusing the exact same shared helpers/table the customer login already
 * uses -- nothing new to migrate, no schema change, no behavior change for
 * customer accounts.
 *
 * Admin attempts are tracked in the same tbl_login_attempts table as
 * customer attempts, but namespaced with an "admin:" prefix on the identifier
 * so an admin username and a customer email can never collide or share a
 * lockout counter.
 */
$error = '';

// ─── Step 2: verifying a TOTP code (only reached if Step 1 found 2FA enabled) ──
if (isset($_POST['btn-verify-2fa'])) {
    tms_csrf_validate();
    $pendingUser = $_SESSION['a2fa_pending'] ?? '';
    $code = trim($_POST['totp_code'] ?? '');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!$pendingUser) {
        // Session lost/expired mid-flow — send back to the start, not a dead end.
        header('location:index.php');
        exit;
    }

    $lockoutKey = '2fa:' . strtolower($pendingUser);

    // FIX (Round 66, master prompt item 44): tms_record_failed_login() below
    // was already filling tbl_login_attempts for this key on every wrong
    // TOTP code, but nothing ever called tms_login_lockout_minutes_remaining()
    // to actually check it first -- the exact same gap the password branch
    // below had. An attacker who got past step 1 (or brute-forced a 2FA
    // code directly, which is only ~1,000,000 possibilities) could try
    // unlimited codes with zero throttling.
    $waitMinutes = tms_login_lockout_minutes_remaining($dbh, $lockoutKey, $ip);
    if ($waitMinutes > 0) {
        $error = "Too many failed attempts. Please try again in {$waitMinutes} minute"
               . ($waitMinutes === 1 ? '' : 's') . '.';
    } else {
        $q = $dbh->prepare("SELECT id, TwoFASecret FROM admin WHERE UserName=:u AND TwoFAEnabled=1 LIMIT 1");
        $q->execute([':u' => $pendingUser]);
        $row = $q->fetch(PDO::FETCH_OBJ);

        if ($row && $row->TwoFASecret && tms_totp_verify($row->TwoFASecret, $code)) {
            tms_clear_login_attempts($dbh, $lockoutKey, $ip);
            unset($_SESSION['a2fa_pending']);
            $_SESSION['alogin'] = $pendingUser;
            session_regenerate_id(true);
            tms_log_activity($dbh, $pendingUser, 'Admin Login', 'Successful admin sign-in (2FA verified)');
            header('location:dashboard.php');
            exit;
        } else {
            tms_record_failed_login($dbh, $lockoutKey, $ip);
            tms_log_activity($dbh, $pendingUser, 'Admin 2FA Failed', 'Invalid TOTP code');
            $error = 'Invalid or expired code. Please try again.';
        }
    }
}

/**
 * SECURITY HARDENING (Phase 1 -- Admin/Auth):
 * This admin login previously had NO brute-force protection at all (unlike
 * the customer-facing login.php, which already used tms_login_lockout_
 * minutes_remaining()/tms_record_failed_login()/tms_clear_login_attempts()),
 * no CSRF token on its form, and its own bespoke password check instead of
 * the shared tms_verify_password() helper (which also auto-upgrades legacy
 * MD5 hashes to bcrypt on successful login). All three are fixed here by
 * reusing the exact same shared helpers/table the customer login already
 * uses -- nothing new to migrate, no schema change, no behavior change for
 * customer accounts.
 *
 * Admin attempts are tracked in the same tbl_login_attempts table as
 * customer attempts, but namespaced with an "admin:" prefix on the identifier
 * so an admin username and a customer email can never collide or share a
 * lockout counter.
 *
 * Phase 1c adds an optional second factor: if TwoFAEnabled is set for this
 * admin, a correct password no longer logs them in directly — it stores the
 * username in $_SESSION['a2fa_pending'] and re-renders this same page with
 * a TOTP code prompt instead (see the Step 2 handler above, and the HTML
 * further down that switches which form to show).
 */
if(isset($_POST['btn-login'])){
    tms_csrf_validate();

    $uname = tms_clean($_POST['txtusername'] ?? '');
    $upass = $_POST['txtpassword'] ?? '';
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockoutKey = 'admin:' . strtolower($uname);

    if(!$uname || !$upass){
        $error = 'Please enter username and password.';
    } else {
        // FIX (Round 66, master prompt item 44): tms_record_failed_login()
        // and tms_clear_login_attempts() were both already being called
        // correctly below -- tbl_login_attempts was filling up with real
        // "admin:" rows -- but nothing on this page ever called
        // tms_login_lockout_minutes_remaining() to check them before
        // attempting a password verification. The customer-facing
        // login.php has checked this since Round 14; this admin login
        // never did, so admin accounts had zero real brute-force
        // protection despite every attempt being dutifully recorded.
        $waitMinutes = tms_login_lockout_minutes_remaining($dbh, $lockoutKey, $ip);
        if ($waitMinutes > 0) {
            $error = "Too many failed sign-in attempts. Please try again in {$waitMinutes} minute"
                   . ($waitMinutes === 1 ? '' : 's') . '.';
        } else {
        try {
            $q = $dbh->prepare("SELECT * FROM admin WHERE UserName=:u LIMIT 1");
            $q->execute([':u'=>$uname]);
            $admin = $q->fetch(PDO::FETCH_OBJ);

            // tms_verify_password() checks bcrypt first, falls back to the
            // legacy 32-char MD5 hash, and (on a successful legacy match)
            // transparently rehashes the stored password to bcrypt -- same
            // upgrade path already used for customer accounts.
            $valid = $admin && tms_verify_password($upass, $admin->Password, $admin->id, $dbh, 'admin', 'id');

            if($valid){
                tms_clear_login_attempts($dbh, $lockoutKey, $ip);

                if (!empty($admin->TwoFAEnabled) && !empty($admin->TwoFASecret)) {
                    // Password correct, but this account requires a TOTP
                    // code too. Regenerate the session ID now (before
                    // granting any privileged state) to close the window
                    // for session fixation across the 2FA step, then park
                    // the username and re-render this page with the code
                    // prompt instead of the dashboard redirect.
                    session_regenerate_id(true);
                    $_SESSION['a2fa_pending'] = $uname;
                    tms_log_activity($dbh, $uname, 'Admin Login', 'Password verified, awaiting 2FA code');
                } else {
                    $_SESSION['alogin'] = $uname;
                    session_regenerate_id(true);
                    tms_log_activity($dbh, $uname, 'Admin Login', 'Successful admin sign-in');
                    header('location:dashboard.php');
                    exit;
                }
            } else {
                tms_record_failed_login($dbh, $lockoutKey, $ip);
                tms_log_activity($dbh, $uname, 'Admin Login Failed', 'Invalid credentials');
                $error = 'Invalid username or password.';
            }
        } catch(Exception $e) {
            error_log('Admin login error: ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
        }
    }
}

$showingTwoFactorPrompt = !empty($_SESSION['a2fa_pending']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Admin Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;min-height:100vh;display:flex;background:#17231E;overflow:hidden;}
.login-left{
  flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:60px 40px;
  background:linear-gradient(135deg,#17231E 0%,#1e3a5f 50%,#1e293b 100%);
  position:relative;overflow:hidden;
}
.login-left::before{
  content:'';position:absolute;width:600px;height:600px;border-radius:50%;
  background:radial-gradient(circle,rgba(29,78,216,0.15),transparent 70%);
  top:-100px;left:-100px;
}
.login-left::after{
  content:'';position:absolute;width:400px;height:400px;border-radius:50%;
  background:radial-gradient(circle,rgba(124,58,237,0.12),transparent 70%);
  bottom:-50px;right:-50px;
}
.login-brand{position:relative;z-index:1;text-align:center;margin-bottom:48px;}
.brand-icon{
  width:72px;height:72px;border-radius:20px;margin:0 auto 20px;
  background:linear-gradient(135deg,#176B50,#1F8A66);
  display:flex;align-items:center;justify-content:center;font-size:32px;color:#fff;
}
.brand-title{font-family:'Inter',sans-serif;font-size:28px;font-weight:800;color:#fff;margin-bottom:8px;}
.brand-sub{font-size:14px;color:#94a3b8;}
.login-features{position:relative;z-index:1;display:flex;flex-direction:column;gap:16px;width:100%;max-width:320px;}
.feat-item{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px 16px;}
.feat-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.feat-text p{font-size:13.5px;font-weight:500;color:#e2e8f0;margin:0 0 2px;}
.feat-text span{font-size:12px;color:#64748b;}
.login-right{
  width:480px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  background:#fff;padding:60px 48px;
}
.login-form{width:100%;max-width:360px;}
.form-title{font-family:'Inter',sans-serif;font-size:26px;font-weight:800;color:#17231E;margin-bottom:6px;}
.form-sub{font-size:14px;color:#64748b;margin-bottom:36px;}
.form-label{display:block;font-size:13px;font-weight:500;color:#475569;margin-bottom:7px;}
.form-input{
  width:100%;padding:11px 16px;
  border:1.5px solid #e2e8f0;border-radius:10px;
  font-size:14px;font-family:'Inter',sans-serif;
  color:#17231E;outline:none;transition:all 0.2s;
  background:#f8fafc;
}
.form-input:focus{border-color:#176B50;background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,0.1);}
.field-group{margin-bottom:18px;}
.btn-login{
  width:100%;padding:13px;border:none;border-radius:10px;
  background:linear-gradient(135deg,#176B50,#1F8A66);color:#fff;
  font-size:15px;font-weight:600;font-family:'Inter',sans-serif;
  cursor:pointer;transition:all 0.2s;margin-top:6px;
  box-shadow:0 4px 14px rgba(29,78,216,0.35);
}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(29,78,216,0.4);}
.btn-login:active{transform:translateY(0);}
.error-box{background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13.5px;color:#991b1b;display:flex;align-items:center;gap:8px;}
.back-link{display:block;text-align:center;margin-top:24px;font-size:13px;color:#64748b;text-decoration:none;transition:color 0.2s;}
.back-link:hover{color:#176B50;}
@media(max-width:768px){
  body{flex-direction:column;}
  .login-left{display:none;}
  .login-right{width:100%;padding:40px 24px;}
}
</style>
</head>
<body>

<!-- Left Panel -->
<div class="login-left">
  <div class="login-brand">
    <div class="brand-icon">&#9992;</div>
    <div class="brand-title">TMS Admin</div>
    <div class="brand-sub">Tourism Management System v6</div>
  </div>
  <div class="login-features">
    <div class="feat-item">
      <div class="feat-icon" style="background:rgba(29,78,216,0.2);color:#7FD6AE;">&#128200;</div>
      <div class="feat-text"><p>Revenue Analytics</p><span>Real-time charts and reports</span></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon" style="background:rgba(5,150,105,0.2);color:#34d399;">&#128197;</div>
      <div class="feat-text"><p>Booking Management</p><span>Confirm, track, and manage bookings</span></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon" style="background:rgba(217,119,6,0.2);color:#fbbf24;">&#127991;</div>
      <div class="feat-text"><p>Coupons &amp; Loyalty</p><span>Create promo codes and rewards</span></div>
    </div>
    <div class="feat-item">
      <div class="feat-icon" style="background:rgba(124,58,237,0.2);color:#a78bfa;">&#9998;</div>
      <div class="feat-text"><p>Blog &amp; Content</p><span>Publish travel tips and guides</span></div>
    </div>
  </div>
</div>

<!-- Right Panel -->
<div class="login-right">
  <div class="login-form">
    <?php if ($showingTwoFactorPrompt): ?>
      <div class="form-title">Two-Factor Code</div>
      <div class="form-sub">Enter the 6-digit code from your authenticator app</div>

      <?php if($error): ?>
      <div class="error-box">&#9888; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?php tms_csrf_field(); ?>
        <div class="field-group">
          <label class="form-label" for="adminTotpCodeField">Authentication Code</label>
          <input type="text" id="adminTotpCodeField" name="totp_code" class="form-input" placeholder="123456" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code" autofocus>
        </div>
        <button type="submit" name="btn-verify-2fa" class="btn-login">Verify &#8594;</button>
      </form>

      <a href="index.php?cancel_2fa=1" class="back-link">&#8592; Back to sign in</a>
    <?php else: ?>
      <div class="form-title">Welcome back</div>
      <div class="form-sub">Sign in to your admin account</div>

      <?php if($error): ?>
      <div class="error-box">&#9888; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?php tms_csrf_field(); ?>
        <div class="field-group">
          <label class="form-label" for="adminUsernameField">Username</label>
          <input type="text" id="adminUsernameField" name="txtusername" class="form-input" placeholder="Admin username" required autocomplete="username">
        </div>
        <div class="field-group">
          <label class="form-label" for="adminPasswordField">Password</label>
          <input type="password" id="adminPasswordField" name="txtpassword" class="form-input" placeholder="••••••••" required autocomplete="current-password">
        </div>
        <button type="submit" name="btn-login" class="btn-login">Sign In &#8594;</button>
      </form>

      <a href="../index.php" class="back-link">&#8592; Back to website</a>
    <?php endif; ?>
  </div>
</div>

</body>
</html>