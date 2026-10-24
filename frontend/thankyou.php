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
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Confirmation </title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<style>.contact h3{color:var(--text);}</style>
<!-- Custom Theme files -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<!--animate-->
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
	<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<!-- ACCESSIBILITY FIX (Round 67, master prompt item 32): every other page
     that uses WOW.js/animate.css also loads css/modern-theme.css, whose
     global @media (prefers-reduced-motion: reduce) rule throttles these
     scroll-triggered animations for users who've asked their OS/browser to
     minimize motion (WCAG 2.3.3/2.2.2) -- this is the one page in the app
     that uses WOW.js but never links modern-theme.css, so it was the one
     page where those animations ran unthrottled regardless of that
     preference. Rather than add the whole stylesheet (this page doesn't
     use any of its other styling and pulling it in wholesale risks
     unrelated visual changes -- Rule 73), just the one rule this page
     actually needs is repeated here, identical to modern-theme.css's own. -->
<style>
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.001ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.001ms !important;
    scroll-behavior: auto !important;
  }
}
</style>
<!--//end-animate-->
</head>
<body>
<?php include('backend/header.php');?>
<div class="banner-1 ">
	<div class="container">
		<h1 class="wow zoomIn animated animated" data-wow-delay=".1s" style="visibility: visible; animation-delay: 0.5s; animation-name: zoomIn;"> TMS- Tourism Management System</h1>
	</div>
</div>
<!--- /banner-1 ---->
<!--- contact ---->
<div class="contact">
	<div class="container">
	<h3> Confirmation</h3>
		<div class="col-md-10 contact-left">
			<div class="con-top animated wow fadeInUp animated" data-wow-duration="400ms" data-wow-delay=".1s" style="visibility: visible; animation-duration: 1200ms; animation-delay: 500ms; animation-name: fadeInUp;">
	

              <h4><?php
                  // Round 14: $_SESSION['msg'] is only ever set by
                  // backend/write-us.php, so a direct visit (or a refresh after
                  // it was consumed) raised "Undefined array key" plus an
                  // htmlentities(null) deprecation and printed an empty heading.
                  echo htmlentities($_SESSION['msg'] ?? "Thanks — we've received your message and will get back to you shortly.");
                  unset($_SESSION['msg']);
              ?></h4>
            
			</div>
		
			<div class="clearfix"></div>
	</div>
</div>
<!--- /contact ---->
<?php include('backend/footer.php');?>
<!-- sign -->
<!-- write us -->
<?php include('backend/write-us.php');?>	
<!-- //write us -->
</body>
</html>