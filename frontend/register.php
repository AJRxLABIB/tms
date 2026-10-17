<?php
/**
 * register.php — TMS AI Travel Copilot
 *
 * ROOT-CAUSE FIX (audit finding): the topbar "Sign Up" modal used by nearly
 * every page (backend/header.php) posts here, but used to send different
 * field names and no reg_token, so it silently failed every time with
 * "Session expired" — nobody could ever sign up via the visible UI, only by
 * navigating to /register.php directly. Now uses the same project-wide CSRF
 * helper as the rest of the app, so both the modal and this standalone page
 * work correctly.
 */
require_once 'backend/config.php';
require_once 'backend/auth/GoogleAuth.php';

// Redirect if already logged in
if (!empty($_SESSION['login'])) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();

    $fullName = trim(strip_tags($_POST['FullName']     ?? ''));
    $mobile   = trim(strip_tags($_POST['MobileNumber'] ?? ''));
    $email    = filter_var(trim($_POST['EmailId'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['Password']  ?? '';
    $confirm  = $_POST['CPassword'] ?? '';

    if (!tms_rate_limit($dbh, 'register', tms_client_ip(), 10)) {
        $error = 'Too many sign-up attempts from this network. Please try again in an hour.';
    } elseif (empty($fullName) || empty($mobile) || !$email || empty($password)) {
        $error = 'All fields are required.';
    } elseif (strlen($fullName) < 3) {
        $error = 'Full name must be at least 3 characters.';
    } elseif (($pwErr = tms_password_error($password)) !== null) {
        $error = $pwErr;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check duplicate email
        $stmt = $dbh->prepare("SELECT id FROM tblusers WHERE EmailId = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists. Please sign in.';
        } else {
            // Referral: prefer an explicit ref field the form submitted,
            // falling back to the 30-day cookie set when they first clicked
            // a referral link (see backend/config.php).
            $refCode = tms_clean($_POST['ref'] ?? ($_COOKIE['tms_ref'] ?? ''));
            $referrerEmail = null;
            if ($refCode !== '') {
                $refStmt = $dbh->prepare("SELECT EmailId FROM tblusers WHERE ReferralCode = :c LIMIT 1");
                $refStmt->execute([':c' => $refCode]);
                $referrerEmail = $refStmt->fetchColumn() ?: null;
            }
            $myReferralCode = tms_generate_referral_code($dbh);

            // Insert user
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $ins  = $dbh->prepare(
                "INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate, ReferralCode, ReferredBy)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?)"
            );
            $ins->execute([$fullName, $mobile, $email, $hash, $myReferralCode, $referrerEmail]);

            // Seed loyalty points
            try {
                $dbh->prepare(
                    "INSERT IGNORE INTO tblloyalty (UserEmail, Points, TotalEarned) VALUES (?,0,0)"
                )->execute([$email]);
            } catch (Exception $e) {}

            // Referral bonus: 50 points each for the new user and whoever referred them
            if ($referrerEmail && $referrerEmail !== $email) {
                foreach ([$email, $referrerEmail] as $rewardEmail) {
                    $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(:e,50,50)
                        ON DUPLICATE KEY UPDATE Points=Points+50, TotalEarned=TotalEarned+50")->execute([':e'=>$rewardEmail]);
                }
                try {
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                        ->execute([':e'=>$email, ':d'=>'Signed up via a referral link']);
                    $dbh->prepare("INSERT INTO tblloyalty_log(UserEmail,Points,Description) VALUES(:e,50,:d)")
                        ->execute([':e'=>$referrerEmail, ':d'=>'Referred a new traveler ('.$email.')']);
                } catch (Exception $e) {
                    error_log('[TMS Loyalty] Log insert failed (registration still succeeded): ' . $e->getMessage());
                }
                try { tms_notify($dbh, $referrerEmail, "🎉 Someone joined TMS using your referral link — you both earned 50 loyalty points!", 'referral.php'); } catch (Exception $e) {}
                setcookie('tms_ref', '', time() - 3600, '/'); // consumed — clear it
            }

            tms_send_email($email, $fullName, 'Welcome to TMS Travel!', tms_email_template(
                'Welcome aboard, ' . $fullName . '!',
                '<p>Your TMS account is ready. Start exploring tour packages, save your favorites to your wishlist, and earn loyalty points with every booking and review.</p>
                 <p>Your personal referral code is <strong>' . htmlspecialchars($myReferralCode, ENT_QUOTES, 'UTF-8') . '</strong> — share it with friends and you\'ll both earn 50 points when they sign up. Find your referral link anytime on the <strong>Refer Friends</strong> page.</p>'
            ));

            $success = 'Account created successfully! You can now sign in.';
        }
    }
}
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
  <title>Register — TMS AI Travel Copilot</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <?php if(file_exists('css/modern-theme.css')): ?>
  <link rel="stylesheet" href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <?php endif; ?>
  <style>
    body{background:linear-gradient(135deg,#0E3B2D,#104B3A 55%,#176B50);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:var(--font-body,'Inter',sans-serif);padding:20px 0;}
    .register-wrap{width:100%;max-width:480px;padding:16px;}
    .register-card{background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.4);overflow:hidden;}
    .register-header{background:linear-gradient(135deg,#176B50,#104B3A);padding:28px 32px 22px;text-align:center;color:#fff;}
    .register-header .logo{font-size:26px;font-weight:800;letter-spacing:-1px;}
    .register-header .logo span{opacity:.7;font-size:12px;font-weight:400;display:block;margin-top:2px;}
    .register-header h2{font-size:17px;font-weight:600;margin:10px 0 0;opacity:.9;}
    .register-body{padding:26px 32px 30px;}
    .form-group{margin-bottom:15px;}
    .form-group label{font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:block;}
    .input-icon{position:relative;}
    .input-icon .fi{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;pointer-events:none;}
    .input-icon input{padding-left:34px;}
    /* ACCESSIBILITY FIX (Round 69, WCAG 2.5.8 target size): padding:0 and
       no explicit box left this button's clickable area at roughly the
       13px icon glyph itself on both axes -- well under the 24px minimum.
       An explicit 24x24 flex-centered box fixes this without moving the
       icon's visible position (right nudged from 10px to 4px to
       compensate for the wider box). */
    .input-icon .eye-btn{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:0;font-size:13px;width:24px;height:24px;display:flex;align-items:center;justify-content:center;}
    .form-control{width:100%;border:2px solid #e2e8f0;border-radius:8px;height:44px;font-size:14px;transition:.2s;}
    .form-control:focus{border-color:#176B50;box-shadow:0 0 0 3px rgba(23,107,80,.15);outline:none;}
    .btn-register{background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;border:none;border-radius:8px;height:46px;font-size:15px;font-weight:700;width:100%;transition:.2s;cursor:pointer;}
    .btn-register:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(23,107,80,.4);}
    .btn-register:disabled{opacity:.7;transform:none;}
    .alert{border-radius:8px;font-size:13px;padding:11px 14px;border:none;margin-bottom:16px;}
    .alert-danger{background:#fff5f5;color:#c53030;}
    .alert-success{background:#f0fff4;color:#276749;}
    .signin-link{text-align:center;margin-top:18px;font-size:13px;color:var(--text-muted);}
    .signin-link a{color:#176B50;font-weight:700;text-decoration:none;}
    .strength-bar{height:4px;border-radius:2px;margin-top:5px;background:#e2e8f0;overflow:hidden;}
    .strength-fill{height:100%;border-radius:2px;transition:.3s;width:0;}
  </style>
</head>
<body>

<div class="register-wrap">
  <div class="register-card">

    <div class="register-header">
      <div class="logo"><i class="fa fa-globe"></i> TMS <span>AI Travel Copilot</span></div>
      <h2><i class="fa fa-user-plus"></i> Create Your Account</h2>
    </div>

    <div class="register-body">

      <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <i class="fa fa-check-circle"></i> <?= htmlspecialchars($success) ?>
          <div class="mt-2"><a href="index.php" style="font-weight:700;">← Go to Homepage to Sign In</a></div>
        </div>
      <?php else: ?>

      <form method="POST" action="register.php" id="regForm">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="ref" value="<?= htmlspecialchars($_COOKIE['tms_ref'] ?? '', ENT_QUOTES) ?>">

        <div class="form-group">
          <label for="regFullNameField">Full Name</label>
          <div class="input-icon">
            <i class="fa fa-user fi"></i>
            <input type="text" name="FullName" id="regFullNameField" class="form-control"
                   placeholder="Enter your full name"
                   value="<?= htmlspecialchars($_POST['FullName'] ?? '', ENT_QUOTES) ?>"
                   required minlength="3" maxlength="100">
          </div>
        </div>

        <div class="form-group">
          <label for="regMobileField">Mobile Number</label>
          <div class="input-icon">
            <i class="fa fa-phone fi"></i>
            <input type="tel" name="MobileNumber" id="regMobileField" class="form-control"
                   placeholder="01XXXXXXXXX"
                   value="<?= htmlspecialchars($_POST['MobileNumber'] ?? '', ENT_QUOTES) ?>"
                   required maxlength="15">
          </div>
        </div>

        <div class="form-group">
          <label for="regEmailFieldStandalone">Email Address</label>
          <div class="input-icon">
            <i class="fa fa-envelope fi"></i>
            <input type="email" name="EmailId" id="regEmailFieldStandalone" class="form-control"
                   placeholder="you@example.com"
                   value="<?= htmlspecialchars($_POST['EmailId'] ?? '', ENT_QUOTES) ?>"
                   required maxlength="100">
          </div>
        </div>

        <div class="form-group">
          <label for="passField">Password</label>
          <div class="input-icon">
            <i class="fa fa-lock fi"></i>
            <input type="password" name="Password" id="passField" class="form-control"
                   placeholder="Min. 6 characters"
                   required minlength="8" maxlength="100"
                   oninput="checkStrength(this.value)"
                   aria-describedby="strengthFill">
            <button type="button" class="eye-btn" onclick="togglePass('passField',this)" aria-label="Show password">
              <i class="fa fa-eye" aria-hidden="true"></i>
            </button>
          </div>
          <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
        </div>

        <div class="form-group">
          <label for="cpassField">Confirm Password</label>
          <div class="input-icon">
            <i class="fa fa-lock fi"></i>
            <input type="password" name="CPassword" id="cpassField" class="form-control"
                   placeholder="Repeat your password"
                   required minlength="8" maxlength="100">
            <button type="button" class="eye-btn" onclick="togglePass('cpassField',this)" aria-label="Show password">
              <i class="fa fa-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-register mt-1" id="regBtn">
          <i class="fa fa-user-plus"></i> Create Account
        </button>
      </form>

      <?php endif; ?>

      <?php echo GoogleAuth::button('', 'Sign up with Google'); ?>
      <div class="signin-link">
        Already have an account? <a href="index.php">Sign In</a>
      </div>

    </div>
  </div>
</div>

<script>
function togglePass(id, btn) {
  var f = document.getElementById(id);
  var i = btn.querySelector('i');
  f.type = f.type === 'password' ? 'text' : 'password';
  i.className = f.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
  // ACCESSIBILITY FIX (Round 55, master prompt item 32): icon-only button
  // had no accessible name (an <i> font-icon conveys nothing to a screen
  // reader) -- aria-label now reflects what the button does next.
  btn.setAttribute('aria-label', f.type === 'password' ? 'Show password' : 'Hide password');
}

function checkStrength(val) {
  var fill = document.getElementById('strengthFill');
  var s = 0;
  if (val.length >= 6)  s++;
  if (val.length >= 10) s++;
  if (/[A-Z]/.test(val)) s++;
  if (/[0-9]/.test(val)) s++;
  if (/[^A-Za-z0-9]/.test(val)) s++;
  var c = ['#fc8181','#f6ad55','#f6e05e','#68d391','#48bb78'];
  var w = ['20%','40%','60%','80%','100%'];
  fill.style.width      = s ? w[s-1] : '0';
  fill.style.background = s ? c[s-1] : 'transparent';
}

document.getElementById('regForm').addEventListener('submit', function(e) {
  var p  = document.getElementById('passField').value;
  var cp = document.getElementById('cpassField').value;
  if (p !== cp) {
    e.preventDefault();
    tmsToast('Passwords do not match. Please check and try again.');
    return;
  }
  var btn = document.getElementById('regBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating account…';
});
</script>

</body>
</html>