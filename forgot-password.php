<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');

$error = '';
$msg   = '';

if (isset($_POST['submit50'])) {
    tms_csrf_validate();

    $email      = tms_validate_email($_POST['email'] ?? '');
    $mobile     = tms_clean($_POST['mobile'] ?? '');
    $newPass    = $_POST['newpassword']     ?? '';
    $confirmPass = $_POST['confirmpassword'] ?? '';

    if (empty($email) || empty($mobile) || empty($newPass) || empty($confirmPass)) {
        $error = 'All fields are required.';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirm password do not match.';
    } else {
        $sql = "SELECT id FROM tblusers WHERE EmailId=:email AND MobileNumber=:mobile LIMIT 1";
        $q   = $dbh->prepare($sql);
        $q->bindParam(':email',  $email,  PDO::PARAM_STR);
        $q->bindParam(':mobile', $mobile, PDO::PARAM_STR);
        $q->execute();
        $user = $q->fetch(PDO::FETCH_OBJ);

        if ($user) {
            $newHash = tms_hash_password($newPass);
            $upd = $dbh->prepare("UPDATE tblusers SET Password=:pwd WHERE EmailId=:email AND MobileNumber=:mobile");
            $upd->bindParam(':pwd',    $newHash, PDO::PARAM_STR);
            $upd->bindParam(':email',  $email,   PDO::PARAM_STR);
            $upd->bindParam(':mobile', $mobile,  PDO::PARAM_STR);
            $upd->execute();
            tms_send_email($email, '', 'Your TMS password was changed',
                tms_email_template('Password changed', '<p>This confirms that your TMS account password was just reset. If you did not do this, please contact support immediately.</p>')
            );
            $msg = 'Your password has been reset successfully. You can now sign in.';
        } else {
            $error = 'No account found with that email and mobile number.';
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
<title>TMS | Recover Password</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<link href="css/modern-theme.css" rel="stylesheet">
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
<?php include('includes/header.php'); ?>
<div class="tms-page-hero">
    <h1 class="wow zoomIn animated" data-wow-delay=".1s">Recover Your Password</h1>
</div>

<div class="privacy">
    <div class="container">
        <div class="recover-box wow fadeInUp" data-wow-delay=".1s">
            <h4><i class="fa fa-lock" style="color:var(--secondary)"></i> Reset Password</h4>
            <p style="font-size:13px;color:#888;margin-bottom:20px;">
                Enter your registered email and mobile number to reset your password.
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
                <div class="form-group">
                    <label>Registered Mobile Number *</label>
                    <input type="text" name="mobile" class="form-control"
                           placeholder="Your registered mobile number" required
                           value="<?php echo htmlspecialchars($_POST['mobile'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                </div>
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
                <button type="submit" name="submit50" class="btn-recover">
                    <i class="fa fa-key"></i> Reset Password
                </button>
            </form>
            <?php endif; ?>

            <p style="margin-top:18px;font-size:13px;color:#aaa;">
                Remember your password?
                <a href="#" data-toggle="modal" data-target="#myModal4" style="color:var(--secondary);font-weight:600;">Sign In</a>
            </p>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
<?php include('includes/signin.php'); ?>
<?php include('includes/write-us.php'); ?>
</body>
</html>
