<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Change Admin Password</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php

$error   = '';
$success = '';

if (isset($_POST['submit'])) {
    tms_csrf_validate();

    $currentPass = $_POST['currentpassword'] ?? '';
    $newPass     = $_POST['newpassword']      ?? '';
    $confirmPass = $_POST['confirmpassword']  ?? '';

    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'All fields are required.';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New password and confirm password do not match.';
    } else {
        $uname = $_SESSION['alogin'];
        $row = $dbh->prepare("SELECT id, Password FROM admin WHERE UserName=:uname LIMIT 1");
        $row->bindParam(':uname', $uname, PDO::PARAM_STR);
        $row->execute();
        $admin = $row->fetch(PDO::FETCH_OBJ);

        if ($admin && tms_verify_password($currentPass, $admin->Password, (int)$admin->id, $dbh, 'admin', 'id')) {
            $newHash = tms_hash_password($newPass);
            $upd = $dbh->prepare("UPDATE admin SET Password=:pwd WHERE UserName=:uname");
            $upd->bindParam(':pwd',   $newHash, PDO::PARAM_STR);
            $upd->bindParam(':uname', $uname,   PDO::PARAM_STR);
            $upd->execute();
            // Audit log coverage (Round 52). Never log the password itself,
            // only that a change happened -- same principle as the 2FA
            // enable/disable log lines in two-factor-setup.php.
            tms_log_activity($dbh, $uname, 'Admin Password Changed', 'Password changed via admin panel');
            $success = 'Password changed successfully!';
        } else {
            tms_log_activity($dbh, $uname, 'Admin Password Change Failed', 'Current password did not match');
            $error = 'Current password is incorrect.';
        }
    }
}
?>






    <div class="table-agile-info">
        <div class="card">
            <div class="card-header"><h3>Change Admin Password</h3></div>
            <div class="card-body" style="max-width:500px;">
                <?php if ($error):   ?><div class="alert-v6 danger"><?php  echo htmlspecialchars($error,   ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert-v6 success"><?php echo htmlspecialchars($success, ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
                <form method="post">
                    <?php tms_csrf_field(); ?>
                    <div class="form-group">
                        <label for="cpw_currentpassword">Current Password *</label>
                        <input type="password" id="cpw_currentpassword" name="currentpassword" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label for="cpw_newpassword">New Password * <small class="text-muted">(min 6 characters)</small></label>
                        <input type="password" id="cpw_newpassword" name="newpassword" class="form-control" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="cpw_confirmpassword">Confirm New Password *</label>
                        <input type="password" id="cpw_confirmpassword" name="confirmpassword" class="form-control" required autocomplete="new-password">
                    </div>
                    <button type="submit" name="submit" class="btn-v6 success">
                        <i class="fa fa-lock"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</div></div>

</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
