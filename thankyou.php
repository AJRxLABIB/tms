<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Confirmation </title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<!-- Custom Theme files -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<!--animate-->
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
	<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<!--//end-animate-->
</head>
<body>
<?php include('includes/header.php');?>
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
                  // includes/write-us.php, so a direct visit (or a refresh after
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
<?php include('includes/footer.php');?>
<!-- sign -->
<!-- write us -->
<?php include('includes/write-us.php');?>	
<!-- //write us -->
</body>
</html>