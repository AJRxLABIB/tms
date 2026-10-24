<?php
/**
 * register.php — TourBuddy AI Travel Copilot
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

// A protected page sent the visitor here with ?redirect=: remember it (validated, same-site paths only) for after sign-in.
if (isset($_GET['redirect']) && is_string($_GET['redirect'])) {
    $tbRedirect = tms_safe_redirect($_GET['redirect'], '');
    if ($tbRedirect !== '') $_SESSION['post_login_redirect'] = $tbRedirect;
}

// Redirect if already logged in
if (!empty($_SESSION['login'])) {
    header('Location: ' . tms_safe_redirect($_SESSION['post_login_redirect'] ?? '', 'index.php')); unset($_SESSION['post_login_redirect']);
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
                    error_log('[TourBuddy Loyalty] Log insert failed (registration still succeeded): ' . $e->getMessage());
                }
                try { tms_notify($dbh, $referrerEmail, "🎉 Someone joined TourBuddy using your referral link — you both earned 50 loyalty points!", 'referral.php'); } catch (Exception $e) {}
                setcookie('tms_ref', '', time() - 3600, '/'); // consumed — clear it
            }

            tms_send_email($email, $fullName, 'Welcome to TourBuddy Travel!', tms_email_template(
                'Welcome aboard, ' . $fullName . '!',
                '<p>Your TourBuddy account is ready. Start exploring tour packages, save your favorites to your wishlist, and earn loyalty points with every booking and review.</p>
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
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register — TourBuddy AI Travel Copilot</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('tms-theme')||'light');}catch(e){}</script>
  <link rel="stylesheet" href="css/tms-theme.css?v=1">
  <link rel="stylesheet" href="css/tms-account.css?v=2">
  <script src="js/tms-motion.js?v=1" defer></script>
</head>
<body class="tms-auth-page">
<main class="tms-auth">
  <aside class="tms-auth-art" style="--auth-img:url('../images/c2.jpg')">
    <a class="brand" href="index.php"><?php require_once __DIR__ . '/../backend/brand.php'; echo tms_brand_mark_html('40'); ?></a>
    <h2>Your next adventure starts with one small step.</h2>
    <p>Create a free account to plan trips, save places and keep every ticket in one spot.</p>
    <div class="tms-scene" aria-hidden="true"><i class="s-cloud"></i><i class="s-far"></i><i class="s-near"></i><i class="s-palm"></i><i class="s-wave"></i><i class="s-wave w2"></i><i class="s-plane"></i></div>
  </aside>

  <section class="tms-auth-main">
    <div class="tms-auth-card">
      <h1>Create account</h1>
      <p class="lead">It takes less than a minute.</p>

      <div class="tms-alert bad" id="authErr" role="alert"<?= $error ? '' : ' hidden' ?>><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <span id="authErrText"><?= htmlspecialchars((string)$error) ?></span></div>
      <?php $errAttr = $error ? ' aria-invalid="true"' : ''; ?>

      <?php if ($success): ?>
        <div class="tms-alert ok" role="status">
          <i class="fa fa-check-circle" aria-hidden="true"></i>
          <span><?= htmlspecialchars($success) ?> <a href="index.php">Go to the homepage to sign in</a></span>
        </div>
      <?php else: ?>

      <form method="POST" action="register.php" id="regForm">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="ref" value="<?= htmlspecialchars($_COOKIE['tms_ref'] ?? '', ENT_QUOTES) ?>">

        <div class="tms-field">
          <label for="regFullNameField">Full name</label>
          <div class="tms-input">
            <i class="fa fa-user fi" aria-hidden="true"></i>
            <input type="text" name="FullName" id="regFullNameField" placeholder="Your full name" autocomplete="name"
                   value="<?= htmlspecialchars($_POST['FullName'] ?? '', ENT_QUOTES) ?>"
                   required minlength="3" maxlength="100"<?= $errAttr ?>>
          </div>
        </div>
        <div class="tms-field">
          <label for="regMobileField">Mobile number</label>
          <div class="tms-input">
            <i class="fa fa-phone fi" aria-hidden="true"></i>
            <input type="tel" name="MobileNumber" id="regMobileField" placeholder="01XXXXXXXXX" autocomplete="tel"
                   value="<?= htmlspecialchars($_POST['MobileNumber'] ?? '', ENT_QUOTES) ?>"
                   required maxlength="15"<?= $errAttr ?>>
          </div>
        </div>
        <div class="tms-field">
          <label for="regEmailFieldStandalone">Email</label>
          <div class="tms-input">
            <i class="fa fa-envelope fi" aria-hidden="true"></i>
            <input type="email" name="EmailId" id="regEmailFieldStandalone" placeholder="you@example.com" autocomplete="email"
                   value="<?= htmlspecialchars($_POST['EmailId'] ?? '', ENT_QUOTES) ?>"
                   required maxlength="100"<?= $errAttr ?>>
          </div>
        </div>
        <div class="tms-field">
          <label for="passField">Password</label>
          <div class="tms-input has-eye">
            <i class="fa fa-lock fi" aria-hidden="true"></i>
            <input type="password" name="Password" id="passField" placeholder="At least 8 characters" autocomplete="new-password"
                   required minlength="8" maxlength="100" oninput="checkStrength(this.value)" aria-describedby="strengthHint">
            <button type="button" class="eye-btn" onclick="togglePass('passField',this)" aria-label="Show password"><i class="fa fa-eye" aria-hidden="true"></i></button>
          </div>
          <div class="tms-strength"><div id="strengthFill"></div></div>
          <p class="tms-hint" id="strengthHint">Use 8+ characters. Mix letters, numbers and symbols for a stronger one.</p>
        </div>
        <div class="tms-field">
          <label for="cpassField">Confirm password</label>
          <div class="tms-input has-eye">
            <i class="fa fa-lock fi" aria-hidden="true"></i>
            <input type="password" name="CPassword" id="cpassField" placeholder="Repeat your password" autocomplete="new-password"
                   required minlength="8" maxlength="100"<?= $errAttr ?>>
            <button type="button" class="eye-btn" onclick="togglePass('cpassField',this)" aria-label="Show password"><i class="fa fa-eye" aria-hidden="true"></i></button>
          </div>
        </div>

        <button type="submit" class="tms-btn-primary" id="regBtn">
          <i class="fa fa-user-plus" aria-hidden="true"></i> Create account
        </button>
      </form>

      <?php endif; ?>

      <div class="or">or</div>
      <?php echo GoogleAuth::button('', 'Sign up with Google'); ?>
      <p class="alt">Already have an account? <a href="login.php">Sign in</a></p>
    </div>
  </section>
</main>

<script>
function togglePass(id, btn) {
  var f = document.getElementById(id);
  var i = btn.querySelector('i');
  f.type = f.type === 'password' ? 'text' : 'password';
  i.className = f.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
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

var regForm = document.getElementById('regForm');
if (regForm) regForm.addEventListener('submit', function(e) {
  var cp = document.getElementById('cpassField');
  if (document.getElementById('passField').value !== cp.value) {
    e.preventDefault();
    var box = document.getElementById('authErr');
    document.getElementById('authErrText').textContent = 'Passwords do not match. Please check and try again.';
    box.hidden = false;
    cp.setAttribute('aria-invalid', 'true');
    cp.setAttribute('aria-describedby', 'authErr');
    cp.focus();
    return;
  }
  var btn = document.getElementById('regBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating account…';
});
</script>

</body>
</html>
