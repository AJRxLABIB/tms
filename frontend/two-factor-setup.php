<?php
/**
 * two-factor-setup.php — customer-facing Two-Factor Authentication
 * (Round 115 continuation, master prompt item 8 -- Auth & Security
 * Center: "Customer-facing 2FA... genuine gaps" per
 * AUDIT_REPORT_ROUND15.md Section 8).
 *
 * Deliberately mirrors admin/two-factor-setup.php's exact, already-working
 * flow (Rule 1 -- reuse, don't reinvent) rather than a new design:
 *
 * Enable flow: generate a secret (session-only, not saved yet) -> show QR +
 * manual key -> the customer proves they scanned it correctly by entering a
 * live code -> only THEN is the secret persisted and 2FA flipped on. This
 * avoids locking someone out from a misconfigured app before it's confirmed
 * working.
 *
 * Disable flow: requires the customer's current password AND a valid TOTP
 * code, not just a button click -- disabling 2FA is a security-relevant
 * action.
 *
 * Reuses backend/totp.php (already required by backend/config.php) exactly
 * as-is -- the same RFC 6238 implementation admin accounts already use,
 * just against tblusers instead of the admin table, via
 * tms_verify_password()'s existing table/pkCol parameters.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];
$msg = ''; $msgType = '';

$q = $dbh->prepare("SELECT id, Password, TwoFAEnabled, TwoFASecret FROM tblusers WHERE EmailId = :e LIMIT 1");
$q->execute([':e' => $email]);
$me = $q->fetch(PDO::FETCH_OBJ);

if (!$me) {
    // Shouldn't happen (would mean the logged-in session outlived the account row).
    header('location:index.php');
    exit;
}

// ─── Enable: confirm the pending secret with a live code ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_enable'])) {
    tms_csrf_validate();
    $pendingSecret = $_SESSION['pending_u2fa_secret'] ?? '';
    $code = $_POST['totp_code'] ?? '';

    if (!$pendingSecret) {
        $msg = 'Setup session expired — please generate a new QR code below.'; $msgType = 'error';
    } elseif (!tms_totp_verify($pendingSecret, $code)) {
        $msg = "That code didn't match. Make sure your authenticator app's clock is correct and try the latest code."; $msgType = 'error';
    } else {
        $dbh->prepare("UPDATE tblusers SET TwoFASecret = :s, TwoFAEnabled = 1 WHERE id = :id")
            ->execute([':s' => $pendingSecret, ':id' => $me->id]);
        unset($_SESSION['pending_u2fa_secret']);
        $msg = 'Two-factor authentication is now enabled on your account.'; $msgType = 'success';
        $me->TwoFAEnabled = 1;
    }
}

// ─── Regenerate the pending (not-yet-saved) secret / QR ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate'])) {
    tms_csrf_validate();
    unset($_SESSION['pending_u2fa_secret']);
}

// ─── Disable: requires current password + a valid current code ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa'])) {
    tms_csrf_validate();
    $pw = $_POST['confirm_password'] ?? '';
    $code = $_POST['disable_totp_code'] ?? '';

    if (!$me->TwoFASecret || !tms_verify_password($pw, $me->Password, (int)$me->id, $dbh)) {
        $msg = 'Incorrect password.'; $msgType = 'error';
    } elseif (!tms_totp_verify($me->TwoFASecret, $code)) {
        $msg = 'Incorrect or expired code.'; $msgType = 'error';
    } else {
        $dbh->prepare("UPDATE tblusers SET TwoFASecret = NULL, TwoFAEnabled = 0 WHERE id = :id")
            ->execute([':id' => $me->id]);
        $msg = 'Two-factor authentication has been disabled.'; $msgType = 'success';
        $me->TwoFAEnabled = 0;
    }
}

// Generate a pending secret to display if not already enabled and none pending yet.
if (empty($me->TwoFAEnabled) && empty($_SESSION['pending_u2fa_secret'])) {
    $_SESSION['pending_u2fa_secret'] = tms_totp_generate_secret();
}

$pendingSecret = $_SESSION['pending_u2fa_secret'] ?? '';
$otpUri = $pendingSecret ? tms_totp_provisioning_uri($pendingSecret, $email, 'TMS') : '';
$qrUrl  = $otpUri ? tms_totp_qr_url($otpUri) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Two-Factor Authentication</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.tms-page-hero{position:relative;min-height:220px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(23,107,80,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(28px,4vw,42px);font-weight:600;margin:0;}
.tfa-card{background:rgba(255,255,255,0.85);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  border:1px solid rgba(255,255,255,0.6);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:32px;max-width:460px;margin:-60px auto 24px;position:relative;z-index:3;}
.tfa-card h4{font-size:16px;font-weight:700;margin:0 0 14px;color:var(--text);}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:var(--success-bg);color:#065f46;}
.alert-error{background:var(--danger-bg);color:#991b1b;}
.status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12.5px;font-weight:700;margin-bottom:14px;}
.status-on{background:var(--success-bg);color:#065f46;}
.status-off{background:var(--warning-bg);color:#92400e;}
.qr-box{text-align:center;margin:18px 0;}
.qr-box img{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:#fff;}
.manual-key{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:14px;letter-spacing:1px;text-align:center;margin:12px 0;word-break:break-all;}
.form-row{margin-bottom:13px;}
.form-row label{display:block;font-size:11.5px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-row input{width:100%;padding:9px 12px;border:2px solid #e2e8f0;border-radius:8px;font-size:13.5px;outline:none;}
.form-row input:focus{border-color:var(--primary);}
.btn-primary-sm{background:var(--primary);color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;}
.btn-primary-sm:hover{opacity:.9;}
.btn-danger-sm{background:#dc2626;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;}
.btn-danger-sm:hover{background:#b91c1c;}
.btn-ghost-sm{background:transparent;color:#475569;border:1px solid #cbd5e1;padding:8px 16px;border-radius:8px;font-size:13px;cursor:pointer;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tms-page-hero">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s">Two-Factor Authentication</h1>
</div>

<div class="privacy">
    <div class="container">
        <div class="tfa-card">
            <span class="status-pill <?php echo $me->TwoFAEnabled ? 'status-on' : 'status-off'; ?>">
                <i class="fa fa-<?php echo $me->TwoFAEnabled ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo $me->TwoFAEnabled ? 'Enabled' : 'Not enabled'; ?>
            </span>

            <?php if ($msg): ?>
                <div class="alert-msg alert-<?php echo $msgType; ?>">
                    <i class="fa fa-<?php echo $msgType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if (!$me->TwoFAEnabled): ?>
                <h4><i class="fa fa-qrcode"></i> Set Up Two-Factor Authentication</h4>
                <p style="font-size:13.5px;color:#64748b;margin:0 0 4px;">
                    Scan this QR code with Google Authenticator, Authy, 1Password, or any standard TOTP app.
                </p>
                <div class="qr-box">
                    <img src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" width="220" height="220" alt="2FA QR code"
                         onerror="this.style.display='none'; document.getElementById('tfaQrFallback').style.display='block';">
                    <p id="tfaQrFallback" style="display:none;font-size:12.5px;color:#92400e;margin-top:8px;">
                        Couldn't load the QR image — use the manual entry key below instead.
                    </p>
                </div>
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Or enter this key manually:</p>
                <div class="manual-key"><?php echo htmlspecialchars($pendingSecret, ENT_QUOTES, 'UTF-8'); ?></div>

                <form method="post" style="margin-top:16px;">
                    <?php tms_csrf_field(); ?>
                    <div class="form-row">
                        <label for="tfaTotpCode">Enter the 6-digit code from your app to confirm</label>
                        <input type="text" id="tfaTotpCode" name="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
                    </div>
                    <button type="submit" name="confirm_enable" class="btn-primary-sm"><i class="fa fa-check"></i> Confirm &amp; Enable</button>
                </form>
                <form method="post" style="margin-top:10px;">
                    <?php tms_csrf_field(); ?>
                    <button type="submit" name="regenerate" class="btn-ghost-sm"><i class="fa fa-refresh"></i> Generate a new QR code</button>
                </form>
            <?php else: ?>
                <h4><i class="fa fa-check-circle" style="color:#059669;"></i> Two-factor authentication is active</h4>
                <p style="font-size:13.5px;color:#64748b;margin-bottom:20px;">You'll be asked for a code from your authenticator app every time you sign in.</p>

                <h4><i class="fa fa-ban" style="color:#dc2626;"></i> Disable Two-Factor Authentication</h4>
                <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Requires your current password and a valid code, since this reduces your account's security.</p>
                <form method="post">
                    <?php tms_csrf_field(); ?>
                    <div class="form-row">
                        <label for="tfaConfirmPassword">Current Password</label>
                        <input type="password" id="tfaConfirmPassword" name="confirm_password" required autocomplete="current-password">
                    </div>
                    <div class="form-row">
                        <label for="tfaDisableCode">Current 2FA Code</label>
                        <input type="text" id="tfaDisableCode" name="disable_totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
                    </div>
                    <button type="submit" name="disable_2fa" class="btn-danger-sm" onclick="return confirm('Disable two-factor authentication on your account?')"><i class="fa fa-ban"></i> Disable 2FA</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>
