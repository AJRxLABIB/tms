<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<title>TourBuddy | Confirmation </title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link rel="stylesheet" href="css/tms-account.css?v=2">
</head>
<body>
<?php include('backend/header.php');?>
<?php $tyMsg = (string)($_SESSION['msg'] ?? "Thanks — we've received your message and will get back to you shortly."); unset($_SESSION['msg']); ?>
<div class="tms-confetti" aria-hidden="true"><?php for ($i = 0; $i < 30; $i++) echo '<i></i>'; ?></div>
<main class="tms-acct" style="max-width:640px;text-align:center;">
  <section class="tms-card" style="padding:40px 28px;">
    <div class="tms-done-icon"><i class="fa fa-check" aria-hidden="true"></i></div>
    <h1 style="font-size:28px;font-weight:800;margin:0 0 8px;">All done!</h1>
    <p role="status" style="font-size:17px;color:var(--tms-muted);margin:0 0 24px;"><?php echo htmlentities($tyMsg); ?></p>
    <h2 style="font-size:16px;text-align:left;">What happens next</h2>
    <ul class="tms-next" style="text-align:left;">
      <li><i class="fa fa-envelope-o" aria-hidden="true"></i> We reply to the email on your account.</li>
      <li><i class="fa fa-suitcase" aria-hidden="true"></i> Your bookings stay in <a href="tour-history.php">My Tour History</a>.</li>
      <li><i class="fa fa-compass" aria-hidden="true"></i> Keep exploring while you wait.</li>
    </ul>
    <div class="tms-actions" style="justify-content:center;margin:24px 0 0;">
      <a class="tms-btn-ghost" href="package-list.php"><i class="fa fa-search" aria-hidden="true"></i> Browse packages</a>
      <a class="tms-btn-ghost" href="dashboard.php"><i class="fa fa-home" aria-hidden="true"></i> My dashboard</a>
    </div>
  </section>
</main>

<?php include('backend/footer.php');?>

<!-- write us -->
<?php include('backend/write-us.php');?>	
<!-- //write us -->
</body>
</html>