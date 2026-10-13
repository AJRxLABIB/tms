<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
$error = '';
$msg = '';
if(empty($_SESSION['login']))
	{
header('location:index.php');
exit;
}
else{
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
<title>TMS | My Support Tickets</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="keywords" content="Tourism Management System In PHP" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<!-- Custom Theme files -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<!--animate-->
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
	<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>

  <style>
.errorWrap{padding:10px;margin:0 0 20px;background:#fff;border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.succWrap{padding:10px;margin:0 0 20px;background:#fff;border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:8px;}
.tms-page-hero{position:relative;min-height:220px;display:flex;align-items:center;justify-content:center;overflow:hidden;
  background:radial-gradient(ellipse 80% 60% at 30% 0%, rgba(15,163,163,0.35), transparent 60%),
  linear-gradient(160deg, var(--primary-dark) 0%, var(--primary) 55%, #0C3A4A 100%);}
.tms-page-hero h1{position:relative;z-index:2;color:#fff;font-family:var(--font-display);font-size:clamp(28px,4vw,42px);font-weight:600;margin:0;}
.tms-page-hero p{position:relative;z-index:2;color:rgba(255,255,255,0.8);margin-top:8px;font-size:14px;}
.ticket-panel{background:rgba(255,255,255,0.75);backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);
  border:1px solid rgba(255,255,255,0.6);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:8px;max-width:920px;margin:-60px auto 60px;position:relative;z-index:3;}
.ticket-row{display:flex;gap:16px;align-items:flex-start;padding:18px 20px;border-bottom:1px solid rgba(15,76,129,0.08);}
.ticket-row:last-child{border-bottom:none;}
.ticket-icon{flex:0 0 40px;height:40px;width:40px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--primary-light));
  display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;}
.ticket-body{flex:1;min-width:0;}
.ticket-top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;}
.ticket-ref{font-family:var(--font-mono);font-size:12px;color:var(--text-muted);}
.ticket-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em;}
.ticket-status.open{background:#fef3c7;color:#92400e;}
.ticket-status.answered{background:#d1fae5;color:#065f46;}
.ticket-issue{font-weight:700;color:var(--text);font-size:15px;margin-bottom:4px;}
.ticket-desc{color:var(--text-muted);font-size:13.5px;margin-bottom:8px;}
.ticket-remark{background:var(--bg);border-left:3px solid var(--secondary);padding:8px 12px;border-radius:0 8px 8px 0;font-size:13px;color:var(--text);}
.ticket-remark strong{color:var(--secondary);}
.ticket-date{font-size:11.5px;color:var(--text-light);margin-top:6px;}
.ticket-empty{text-align:center;padding:60px 20px;color:var(--text-muted);}
.ticket-empty i{font-size:40px;color:var(--secondary-light);margin-bottom:14px;display:block;}
		</style>
</head>
<body>
<?php include('backend/header.php');?>
<div class="tms-page-hero">
	<h1 class="wow zoomIn animated" data-wow-delay=".1s">My Support Tickets</h1>
	<p>Track every issue you've raised and the admin team's response, in one place.</p>
</div>
<div class="ticket-panel wow fadeInUp" data-wow-delay=".1s">
<?php if($error){?><div class="errorWrap" style="margin:16px 20px 0;"><strong>ERROR</strong>: <?php echo htmlentities($error); ?></div><?php }
		else if($msg){?><div class="succWrap" style="margin:16px 20px 0;"><strong>SUCCESS</strong>: <?php echo htmlentities($msg); ?></div><?php }?>
<?php
$uemail=$_SESSION['login'];
$sql = "SELECT * from tblissues where UserEmail=:uemail ORDER BY id DESC";
$query = $dbh->prepare($sql);
$query -> bindParam(':uemail', $uemail, PDO::PARAM_STR);
$query->execute();
$results=$query->fetchAll(PDO::FETCH_OBJ);
if($query->rowCount() > 0):
	foreach($results as $result):
		$answered = !empty($result->AdminRemark);
?>
	<div class="ticket-row">
		<div class="ticket-icon"><i class="fa fa-life-ring"></i></div>
		<div class="ticket-body">
			<div class="ticket-top">
				<span class="ticket-ref">#TKT-<?php echo htmlentities($result->id);?></span>
				<span class="ticket-status <?php echo $answered ? 'answered' : 'open'; ?>">
					<?php echo $answered ? 'Answered' : 'Awaiting Response'; ?>
				</span>
			</div>
			<div class="ticket-issue"><?php echo htmlentities($result->Issue);?></div>
			<div class="ticket-desc"><?php echo htmlentities($result->Description);?></div>
			<?php if($answered): ?>
			<div class="ticket-remark"><strong>Admin response:</strong> <?php echo htmlentities($result->AdminRemark);?></div>
			<?php endif; ?>
			<div class="ticket-date">
				<i class="fa fa-clock-o"></i> Raised <?php echo htmlentities($result->PostingDate);?>
				<?php if($answered && !empty($result->AdminremarkDate)): ?>
					&nbsp;·&nbsp; Answered <?php echo htmlentities($result->AdminremarkDate);?>
				<?php endif; ?>
			</div>
		</div>
	</div>
<?php	endforeach;
else: ?>
	<div class="ticket-empty">
		<i class="fa fa-life-ring"></i>
		No support tickets yet. Use the <strong>Write Us</strong> widget to raise one — we'll answer it right here.
	</div>
<?php endif; ?>
</div>

<!--- footer-top ---->
<!--- /footer-top ---->
<?php include('backend/footer.php');?>
<!-- write us -->
<?php include('backend/write-us.php');?>
</body>
</html>
<?php } ?>
