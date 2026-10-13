<?php
/**
 * two-factor-setup.php — RBAC/Auth Phase 1c
 *
 * Lets the currently logged-in admin enable or disable TOTP-based 2FA on
 * their OWN account (an admin can't force 2FA onto someone else's account
 * from here — that would be its own can of worms around locking other
 * people out; each admin manages their own).
 *
 * Enable flow: generate a secret (kept in session only, not saved to the DB
 * yet) -> show QR + manual key -> admin proves they scanned it correctly by
 * entering a live code -> only THEN is the secret persisted and 2FA flipped
 * on. This avoids the classic bad flow of "save the secret first, ask for a
 * code after" which can lock someone out if their app config was wrong.
 *
 * Disable flow: requires the admin's current password AND a valid TOTP code
 * (not just a button click) — disabling 2FA is a security-relevant action.
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();

$uname = $_SESSION['alogin'];
$msg = ''; $msgType = '';

$q = $dbh->prepare("SELECT id, Password, TwoFAEnabled, TwoFASecret FROM admin WHERE UserName = :u LIMIT 1");
$q->execute([':u' => $uname]);
$me = $q->fetch(PDO::FETCH_OBJ);

if (!$me) {
    // Shouldn't happen (would mean the logged-in session outlived the account row)
    header('location:index.php');
    exit;
}

// ─── Enable: confirm the pending secret with a live code ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_enable'])) {
    tms_csrf_validate();
    $pendingSecret = $_SESSION['pending_2fa_secret'] ?? '';
    $code = $_POST['totp_code'] ?? '';

    if (!$pendingSecret) {
        $msg = 'Setup session expired — please generate a new QR code below.'; $msgType = 'error';
    } elseif (!tms_totp_verify($pendingSecret, $code)) {
        $msg = 'That code didn\'t match. Make sure your authenticator app\'s clock is correct and try the latest code.'; $msgType = 'error';
    } else {
        $dbh->prepare("UPDATE admin SET TwoFASecret = :s, TwoFAEnabled = 1 WHERE id = :id")
            ->execute([':s' => $pendingSecret, ':id' => $me->id]);
        unset($_SESSION['pending_2fa_secret']);
        tms_log_activity($dbh, $uname, '2FA Enabled', 'Two-factor authentication turned on');
        $msg = 'Two-factor authentication is now enabled on your account.'; $msgType = 'success';
        $me->TwoFAEnabled = 1;
    }
}

// ─── Regenerate the pending (not-yet-saved) secret / QR ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate'])) {
    tms_csrf_validate();
    unset($_SESSION['pending_2fa_secret']);
}

// ─── Disable: requires current password + a valid current code ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa'])) {
    tms_csrf_validate();
    $pw = $_POST['confirm_password'] ?? '';
    $code = $_POST['disable_totp_code'] ?? '';

    if (!$me->TwoFASecret || !tms_verify_password($pw, $me->Password, $me->id, $dbh, 'admin', 'id')) {
        $msg = 'Incorrect password.'; $msgType = 'error';
    } elseif (!tms_totp_verify($me->TwoFASecret, $code)) {
        $msg = 'Incorrect or expired code.'; $msgType = 'error';
    } else {
        $dbh->prepare("UPDATE admin SET TwoFASecret = NULL, TwoFAEnabled = 0 WHERE id = :id")
            ->execute([':id' => $me->id]);
        tms_log_activity($dbh, $uname, '2FA Disabled', 'Two-factor authentication turned off');
        $msg = 'Two-factor authentication has been disabled.'; $msgType = 'success';
        $me->TwoFAEnabled = 0;
    }
}

// Generate a pending secret to display if not already enabled and none pending yet
if (empty($me->TwoFAEnabled) && empty($_SESSION['pending_2fa_secret'])) {
    $_SESSION['pending_2fa_secret'] = tms_totp_generate_secret();
}

$pendingSecret = $_SESSION['pending_2fa_secret'] ?? '';
$otpUri = $pendingSecret ? tms_totp_provisioning_uri($pendingSecret, $uname, 'TMS Admin') : '';
$qrUrl  = $otpUri ? tms_totp_qr_url($otpUri) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Two-Factor Authentication</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:#17231E;margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;margin-bottom:20px;max-width:520px;}
.card h4{font-size:15px;font-weight:700;margin:0 0 14px;color:#17231E;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12.5px;font-weight:700;}
.status-on{background:#d1fae5;color:#065f46;}
.status-off{background:#fef3c7;color:#92400e;}
.qr-box{text-align:center;margin:18px 0;}
.qr-box img{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:#fff;}
.manual-key{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:14px;letter-spacing:1px;text-align:center;margin:12px 0;word-break:break-all;}
.form-row{margin-bottom:13px;}
.form-row label{display:block;font-size:11.5px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-row input{width:100%;padding:9px 12px;border:2px solid #e2e8f0;border-radius:8px;font-size:13.5px;outline:none;max-width:240px;}
.form-row input:focus{border-color:#176B50;}
.btn-primary-sm{background:#176B50;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;}
.btn-primary-sm:hover{background:#104B3A;}
.btn-danger-sm{background:#dc2626;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;}
.btn-danger-sm:hover{background:#b91c1c;}
.btn-ghost-sm{background:transparent;color:#475569;border:1px solid #cbd5e1;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-shield" style="color:#176B50;margin-right:10px;"></i>Two-Factor Authentication</h2>
  <span class="status-pill <?= $me->TwoFAEnabled ? 'status-on' : 'status-off' ?>">
    <i class="fa fa-<?= $me->TwoFAEnabled ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= $me->TwoFAEnabled ? 'Enabled' : 'Not enabled' ?>
  </span>
</div>

<?php if ($msg): ?><div class="alert-msg alert-<?= $msgType ?>"><i class="fa fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if (!$me->TwoFAEnabled): ?>
  <div class="card">
    <h4><i class="fa fa-qrcode"></i> Set Up Two-Factor Authentication</h4>
    <p style="font-size:13.5px;color:#64748b;margin:0 0 4px;">
      Scan this QR code with Google Authenticator, Authy, 1Password, or any standard TOTP app.
    </p>
    <div class="qr-box">
      <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES) ?>" width="220" height="220" alt="2FA QR code"
           onerror="this.style.display='none'; document.getElementById('qrFallbackNote').style.display='block';">
      <p id="qrFallbackNote" style="display:none;font-size:12.5px;color:#92400e;margin-top:8px;">
        Couldn't load the QR image — use the manual entry key below instead.
      </p>
    </div>
    <p style="font-size:12px;color:#94a3b8;margin-bottom:4px;">Or enter this key manually:</p>
    <div class="manual-key"><?= htmlspecialchars($pendingSecret) ?></div>

    <form method="post" style="margin-top:16px;">
      <?php tms_csrf_field(); ?>
      <div class="form-row">
        <label for="tfa_totp_code">Enter the 6-digit code from your app to confirm</label>
        <input type="text" id="tfa_totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
      </div>
      <button type="submit" name="confirm_enable" class="btn-primary-sm"><i class="fa fa-check"></i> Confirm &amp; Enable</button>
    </form>
    <form method="post" style="margin-top:10px;">
      <?php tms_csrf_field(); ?>
      <button type="submit" name="regenerate" class="btn-ghost-sm"><i class="fa fa-refresh"></i> Generate a new QR code</button>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <h4><i class="fa fa-check-circle" style="color:#059669;"></i> Two-factor authentication is active</h4>
    <p style="font-size:13.5px;color:#64748b;">You'll be asked for a code from your authenticator app every time you sign in.</p>
  </div>
  <div class="card">
    <h4><i class="fa fa-ban" style="color:#dc2626;"></i> Disable Two-Factor Authentication</h4>
    <p style="font-size:13px;color:#94a3b8;margin-bottom:14px;">Requires your current password and a valid code, since this reduces your account's security.</p>
    <form method="post">
      <?php tms_csrf_field(); ?>
      <div class="form-row">
        <label for="tfa_confirm_password">Current Password</label>
        <input type="password" id="tfa_confirm_password" name="confirm_password" required autocomplete="current-password">
      </div>
      <div class="form-row">
        <label for="tfa_disable_totp_code">Current 2FA Code</label>
        <input type="text" id="tfa_disable_totp_code" name="disable_totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
      </div>
      <button type="submit" name="disable_2fa" class="btn-danger-sm" onclick="return confirm('Disable two-factor authentication on your account?')"><i class="fa fa-ban"></i> Disable 2FA</button>
    </form>
  </div>
<?php endif; ?>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
