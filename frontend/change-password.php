<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$error = '';
$msg   = '';

if (isset($_POST['submit5'])) {
    tms_csrf_validate();

    $currentPass  = $_POST['password']        ?? '';
    $newPass      = $_POST['newpassword']      ?? '';
    $confirmPass  = $_POST['confirmpassword']  ?? '';

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'All fields are required.';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirm password do not match.';
    } else {
        $email = $_SESSION['login'];
        $row = $dbh->prepare("SELECT id, Password FROM tblusers WHERE EmailId=:email LIMIT 1");
        $row->bindParam(':email', $email, PDO::PARAM_STR);
        $row->execute();
        $user = $row->fetch(PDO::FETCH_OBJ);

        if ($user && tms_verify_password($currentPass, $user->Password, (int)$user->id, $dbh)) {
            $newHash = tms_hash_password($newPass);
            $upd = $dbh->prepare("UPDATE tblusers SET Password=:pwd WHERE EmailId=:email");
            $upd->bindParam(':pwd',   $newHash, PDO::PARAM_STR);
            $upd->bindParam(':email', $email,   PDO::PARAM_STR);
            $upd->execute();
            $msg = 'Your password has been changed successfully.';
        } else {
            $error = 'Your current password is incorrect.';
        }
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
<title>TMS | Change Password</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.succWrap{padding:10px;margin:0 0 20px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.pwd-strength{height:6px;border-radius:4px;margin-top:6px;transition:width .3s,background .3s;}
.tms-page-hero{position:relative;min-height:220px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(15,163,163,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(28px,4vw,42px);font-weight:600;margin:0;}
.pwd-card{background:rgba(255,255,255,0.7);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  border:1px solid rgba(255,255,255,0.6);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:32px;max-width:440px;margin:-60px auto 60px;position:relative;z-index:3;}
.pwd-card .form-group b{color:var(--text);font-family:var(--font-body);font-weight:700;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>
<div class="tms-page-hero">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s">Change Password</h1>
</div>

<div class="privacy">
    <div class="container">
        <form name="chngpwd" method="post" class="pwd-card">
            <?php tms_csrf_field(); ?>

            <?php if ($error): ?>
                <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php elseif ($msg): ?>
                <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="form-group">
                <b>Current Password</b>
                <input type="password" name="password" class="form-control" placeholder="Current Password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <b>New Password</b>
                <input type="password" name="newpassword" id="newpassword" class="form-control" placeholder="At least 6 characters" required autocomplete="new-password" oninput="checkStrength(this.value)">
                <div id="strengthBar" class="pwd-strength" style="width:0;background:#ddd;"></div>
                <small id="strengthText" style="color:#888;font-size:12px;"></small>
            </div>
            <div class="form-group">
                <b>Confirm New Password</b>
                <input type="password" name="confirmpassword" class="form-control" placeholder="Confirm Password" required autocomplete="new-password">
            </div>
            <button type="submit" name="submit5" class="btn btn-primary">Change Password</button>
        </form>
    </div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>

<script>
function checkStrength(val) {
    var bar = document.getElementById('strengthBar');
    var txt = document.getElementById('strengthText');
    if (!val) { bar.style.width='0'; txt.textContent=''; return; }
    var score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    var pct = (score / 5 * 100) + '%';
    var colors = ['#e74c3c','#e67e22','#f1c40f','#2ecc71','#27ae60'];
    var labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
    bar.style.width = pct;
    bar.style.background = colors[score-1] || '#ddd';
    txt.textContent = labels[score-1] || '';
    txt.style.color = colors[score-1] || '#ddd';
}
</script>
</body>
</html>
