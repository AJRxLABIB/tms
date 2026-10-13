<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.
if(isset($_POST['submit1']))
{
    tms_csrf_validate();
    $fname       = tms_clean($_POST['fname'] ?? '');
    $email       = tms_validate_email($_POST['email'] ?? '');
    $mobile      = tms_clean($_POST['mobileno'] ?? '');
    $subject     = tms_clean($_POST['subject'] ?? '');
    $description = tms_clean($_POST['description'] ?? '');

    if (empty($fname) || empty($email) || empty($subject) || empty($description)) {
        $error = "Please fill in all required fields.";
    } else {
        $sql = "INSERT INTO tblenquiry(FullName,EmailId,MobileNumber,Subject,Description) VALUES(:fname,:email,:mobile,:subject,:description)";
        $query = $dbh->prepare($sql);
        $query->bindParam(':fname',       $fname,       PDO::PARAM_STR);
        $query->bindParam(':email',       $email,       PDO::PARAM_STR);
        $query->bindParam(':mobile',      $mobile,      PDO::PARAM_STR);
        $query->bindParam(':subject',     $subject,     PDO::PARAM_STR);
        $query->bindParam(':description', $description, PDO::PARAM_STR);
        $query->execute();
        if ($dbh->lastInsertId()) {
            $msg = "Enquiry successfully submitted!";
        } else {
            $error = "Something went wrong. Please try again.";
        }
    }
}

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
<title>TMS | Tourism Management System</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="keywords" content="Tourism Management System In PHP" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Custom Theme files -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<!--animate-->
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
	<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
  <style>
		.errorWrap {
    padding: 10px;
    margin: 0 0 20px 0;
    background: var(--white);
    border-left: 4px solid #dd3d36;
    -webkit-box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
    box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
}
.succWrap{
    padding: 10px;
    margin: 0 0 20px 0;
    background: var(--white);
    border-left: 4px solid #5cb85c;
    -webkit-box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
    box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);
}
		</style>
</head>
<body>
<?php include('backend/header.php');?>
<div class="banner-1 ">
	<div class="container">
		<h1 class="wow zoomIn animated animated" data-wow-delay=".1s" style="visibility: visible; animation-delay: 0.5s; animation-name: zoomIn;">TMS-Tourism Management System</h1>
	</div>
</div>
<!--- /banner-1 ---->
<!--- privacy ---->
<div class="privacy">
	<div class="container">
<?php
// ROOT-CAUSE FIX: this used to do $pagetype = $_GET['type'] with no default
// (an undefined-index warning if missing) and only ever rendered whatever
// HTML happened to be stored in tblpages.detail for that type — 'aboutus'
// and 'contact' had NO actual page template at all (no About sections, no
// contact <form>, even though the enquiry-handling PHP above already existed
// and had nowhere to submit from), so those two routes looked "blank"/broken
// regardless of what was in the database. They now get real, dedicated
// templates. Everything else still falls back to the generic CMS renderer.
$pagetype = tms_clean($_GET['type'] ?? '');

$cms = null;
if ($pagetype !== '') {
    $sql = "SELECT type, detail FROM tblpages WHERE type=:pagetype LIMIT 1";
    $query = $dbh->prepare($sql);
    $query->bindParam(':pagetype', $pagetype, PDO::PARAM_STR);
    $query->execute();
    $cms = $query->fetch(PDO::FETCH_OBJ);
}

// ROOT-CAUSE FIX: switching the site language (via language.php) already
// re-translates the nav/footer chrome through tms_t(), but this page's own
// body content — the About Us mission/vision/story text and the Contact
// form labels — was 100% hardcoded English with no translation call at
// all, so it silently stayed English no matter what language was active
// (visible as: Bangla nav, English "About Us" heading/paragraphs below it).
// Uses the same tms_translate_batch() + DB cache already used by
// package-details.php for package name/description, so translations are
// only ever AI-called once per string per language and instant after that.
$T = [];
if (in_array($pagetype, ['aboutus', 'contact'], true) && tms_active_language() !== 'en') {
    if ($pagetype === 'aboutus') {
        $T = tms_translate_batch([
            'heading'          => 'About Us',
            'intro'            => "TMS (Tourism Management System) helps travellers discover, plan and book unforgettable trips across Bangladesh and beyond — from the sandy shores of Cox's Bazar to the hill tracts of Bandarban and destinations worldwide.",
            'mission_title'    => 'Our Mission',
            'mission_body'     => 'To make trustworthy, affordable and unforgettable travel accessible to everyone — pairing local expertise with modern technology so every trip we plan is safe, well-organised, and genuinely memorable.',
            'vision_title'     => 'Our Vision',
            'vision_body'      => 'To become the most trusted tourism platform in Bangladesh and the wider region, known for reliability, transparent pricing, and putting travellers first at every step of their journey.',
            'story_title'      => 'Our Story',
            'story_body'       => "TMS started with a simple frustration: booking a trip shouldn't mean juggling five different websites for packages, weather, maps and reviews. We built a single place where you can explore any destination, see live weather and nearby places, compare packages, and book — all without leaving the site.",
            'stat_packages'    => 'Tour Packages',
            'stat_travellers'  => 'Happy Travellers',
            'stat_destinations'=> 'Destinations',
            'stat_support'     => 'Support',
            'why_title'        => 'Why Choose Us',
            'why_1'            => 'Locally vetted packages, no hidden fees',
            'why_2'            => 'Live weather & nearby-places for every destination',
            'why_3'            => 'Secure booking with instant confirmation',
            'why_4'            => 'Multilingual support, 24/7 human help',
        ], tms_active_language(), $dbh);
    } else {
        $T = tms_translate_batch([
            'heading'      => 'Contact Us',
            'intro'        => 'Questions about a package, a booking, or planning a custom trip? Send us a message and our team will get back to you shortly.',
            'lbl_fullname' => 'Full Name *',
            'lbl_email'    => 'Email *',
            'lbl_phone'    => 'Phone',
            'lbl_subject'  => 'Subject *',
            'lbl_message'  => 'Message *',
            'btn_send'     => 'Send Message',
            'office_title' => 'Our Office',
            'office_addr'  => 'Gulshan Avenue, Dhaka 1212, Bangladesh',
            'phone_title'  => 'Phone',
            'email_title'  => 'Email',
        ], tms_active_language(), $dbh);
    }
}
/** Look up a translated string with an English fallback (mirrors tms_t()'s
 *  fallback behaviour, but for this page's locally-scoped $T array). */
function tms_page_t(array $T, string $key, string $fallback): string {
    return $T[$key] ?? $fallback;
}
?>

<?php if ($pagetype === 'aboutus'): ?>
	<h3 class="wow fadeInDown"><i class="fa fa-info-circle" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'heading', 'About Us'), ENT_QUOTES, 'UTF-8'); ?></h3>
	<?php if ($cms && trim(strip_tags($cms->detail)) !== ''): ?>
	<p><?php echo $cms->detail; ?></p>
	<?php else: ?>
	<p><?php echo htmlspecialchars(tms_page_t($T, 'intro', "TMS (Tourism Management System) helps travellers discover, plan and book unforgettable trips across Bangladesh and beyond — from the sandy shores of Cox's Bazar to the hill tracts of Bandarban and destinations worldwide."), ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endif; ?>

	<div class="row wow fadeInUp" style="margin-top:20px;">
		<div class="col-sm-6" style="margin-bottom:20px;">
			<div class="pkg-card" style="padding:24px;height:100%;">
				<h4 style="font-weight:700;"><i class="fa fa-bullseye" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'mission_title', 'Our Mission'), ENT_QUOTES, 'UTF-8'); ?></h4>
				<p style="color:#555;"><?php echo htmlspecialchars(tms_page_t($T, 'mission_body', 'To make trustworthy, affordable and unforgettable travel accessible to everyone — pairing local expertise with modern technology so every trip we plan is safe, well-organised, and genuinely memorable.'), ENT_QUOTES, 'UTF-8'); ?></p>
			</div>
		</div>
		<div class="col-sm-6" style="margin-bottom:20px;">
			<div class="pkg-card" style="padding:24px;height:100%;">
				<h4 style="font-weight:700;"><i class="fa fa-eye" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'vision_title', 'Our Vision'), ENT_QUOTES, 'UTF-8'); ?></h4>
				<p style="color:#555;"><?php echo htmlspecialchars(tms_page_t($T, 'vision_body', 'To become the most trusted tourism platform in Bangladesh and the wider region, known for reliability, transparent pricing, and putting travellers first at every step of their journey.'), ENT_QUOTES, 'UTF-8'); ?></p>
			</div>
		</div>
	</div>

	<div class="pkg-card wow fadeInUp" style="padding:24px;margin-bottom:20px;">
		<h4 style="font-weight:700;"><i class="fa fa-book" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'story_title', 'Our Story'), ENT_QUOTES, 'UTF-8'); ?></h4>
		<p style="color:#555;"><?php echo htmlspecialchars(tms_page_t($T, 'story_body', "TMS started with a simple frustration: booking a trip shouldn't mean juggling five different websites for packages, weather, maps and reviews. We built a single place where you can explore any destination, see live weather and nearby places, compare packages, and book — all without leaving the site."), ENT_QUOTES, 'UTF-8'); ?></p>
	</div>

	<div class="row wow fadeInUp" style="text-align:center;margin:30px 0;">
		<div class="col-xs-6 col-sm-3" style="margin-bottom:20px;">
			<h2 style="color:var(--secondary);font-weight:800;" data-counter="400">0</h2><p style="color:#666;"><?php echo htmlspecialchars(tms_page_t($T, 'stat_packages', 'Tour Packages'), ENT_QUOTES, 'UTF-8'); ?></p>
		</div>
		<div class="col-xs-6 col-sm-3" style="margin-bottom:20px;">
			<h2 style="color:var(--secondary);font-weight:800;" data-counter="1900">0</h2><p style="color:#666;"><?php echo htmlspecialchars(tms_page_t($T, 'stat_travellers', 'Happy Travellers'), ENT_QUOTES, 'UTF-8'); ?></p>
		</div>
		<div class="col-xs-6 col-sm-3" style="margin-bottom:20px;">
			<h2 style="color:var(--secondary);font-weight:800;" data-counter="60">0</h2><p style="color:#666;"><?php echo htmlspecialchars(tms_page_t($T, 'stat_destinations', 'Destinations'), ENT_QUOTES, 'UTF-8'); ?></p>
		</div>
		<div class="col-xs-6 col-sm-3" style="margin-bottom:20px;">
			<h2 style="color:var(--secondary);font-weight:800;">24/7</h2><p style="color:#666;"><?php echo htmlspecialchars(tms_page_t($T, 'stat_support', 'Support'), ENT_QUOTES, 'UTF-8'); ?></p>
		</div>
	</div>

	<div class="pkg-card wow fadeInUp" style="padding:24px;">
		<h4 style="font-weight:700;margin-bottom:14px;"><i class="fa fa-thumbs-up" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'why_title', 'Why Choose Us'), ENT_QUOTES, 'UTF-8'); ?></h4>
		<div class="row">
			<div class="col-sm-6"><p style="color:#555;"><i class="fa fa-check-circle" style="color:#27ae60"></i> <?php echo htmlspecialchars(tms_page_t($T, 'why_1', 'Locally vetted packages, no hidden fees'), ENT_QUOTES, 'UTF-8'); ?></p></div>
			<div class="col-sm-6"><p style="color:#555;"><i class="fa fa-check-circle" style="color:#27ae60"></i> <?php echo htmlspecialchars(tms_page_t($T, 'why_2', 'Live weather & nearby-places for every destination'), ENT_QUOTES, 'UTF-8'); ?></p></div>
			<div class="col-sm-6"><p style="color:#555;"><i class="fa fa-check-circle" style="color:#27ae60"></i> <?php echo htmlspecialchars(tms_page_t($T, 'why_3', 'Secure booking with instant confirmation'), ENT_QUOTES, 'UTF-8'); ?></p></div>
			<div class="col-sm-6"><p style="color:#555;"><i class="fa fa-check-circle" style="color:#27ae60"></i> <?php echo htmlspecialchars(tms_page_t($T, 'why_4', 'Multilingual support, 24/7 human help'), ENT_QUOTES, 'UTF-8'); ?></p></div>
		</div>
	</div>

<?php elseif ($pagetype === 'contact'): ?>
	<h3 class="wow fadeInDown"><i class="fa fa-envelope" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'heading', 'Contact Us'), ENT_QUOTES, 'UTF-8'); ?></h3>
	<?php if ($cms && trim(strip_tags($cms->detail)) !== ''): ?>
	<p><?php echo $cms->detail; ?></p>
	<?php else: ?>
	<p><?php echo htmlspecialchars(tms_page_t($T, 'intro', 'Questions about a package, a booking, or planning a custom trip? Send us a message and our team will get back to you shortly.'), ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endif; ?>

	<div class="row wow fadeInUp">
		<div class="col-md-7" style="margin-bottom:20px;">
			<div class="pkg-card" style="padding:24px;">
				<?php if (!empty($error)): ?>
					<div class="errorWrap"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php elseif (!empty($msg)): ?>
					<div class="succWrap"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
				<?php endif; ?>
				<form method="post" action="page.php?type=contact">
					<?php tms_csrf_field(); ?>
					<div class="form-group">
						<label for="pageFullNameField" style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars(tms_page_t($T, 'lbl_fullname', 'Full Name *'), ENT_QUOTES, 'UTF-8'); ?></label>
						<input type="text" name="fname" id="pageFullNameField" class="form-control" required maxlength="100"
							value="<?php echo htmlspecialchars($_POST['fname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
					</div>
					<div class="row">
						<div class="col-sm-6">
							<div class="form-group">
								<label for="pageEmailField" style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars(tms_page_t($T, 'lbl_email', 'Email *'), ENT_QUOTES, 'UTF-8'); ?></label>
								<input type="email" name="email" id="pageEmailField" class="form-control" required maxlength="150"
									value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
							</div>
						</div>
						<div class="col-sm-6">
							<div class="form-group">
								<label for="pagePhoneField" style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars(tms_page_t($T, 'lbl_phone', 'Phone'), ENT_QUOTES, 'UTF-8'); ?></label>
								<input type="text" name="mobileno" id="pagePhoneField" class="form-control" maxlength="20"
									value="<?php echo htmlspecialchars($_POST['mobileno'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
							</div>
						</div>
					</div>
					<div class="form-group">
						<label for="pageSubjectField" style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars(tms_page_t($T, 'lbl_subject', 'Subject *'), ENT_QUOTES, 'UTF-8'); ?></label>
						<input type="text" name="subject" id="pageSubjectField" class="form-control" required maxlength="200"
							value="<?php echo htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
					</div>
					<div class="form-group">
						<label for="pageMessageField" style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars(tms_page_t($T, 'lbl_message', 'Message *'), ENT_QUOTES, 'UTF-8'); ?></label>
						<textarea name="description" id="pageMessageField" class="form-control" rows="5" required maxlength="2000"><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
					</div>
					<button type="submit" name="submit1" class="btn btn-primary" style="padding:10px 30px;">
						<i class="fa fa-paper-plane"></i> <?php echo htmlspecialchars(tms_page_t($T, 'btn_send', 'Send Message'), ENT_QUOTES, 'UTF-8'); ?>
					</button>
				</form>
			</div>
		</div>
		<div class="col-md-5" style="margin-bottom:20px;">
			<div class="pkg-card" style="padding:24px;margin-bottom:16px;">
				<h5 style="font-weight:700;"><i class="fa fa-map-marker" style="color:#e74c3c"></i> <?php echo htmlspecialchars(tms_page_t($T, 'office_title', 'Our Office'), ENT_QUOTES, 'UTF-8'); ?></h5>
				<p style="color:#666;font-size:14px;"><?php echo htmlspecialchars(tms_page_t($T, 'office_addr', 'Gulshan Avenue, Dhaka 1212, Bangladesh'), ENT_QUOTES, 'UTF-8'); ?></p>
				<h5 style="font-weight:700;margin-top:14px;"><i class="fa fa-phone" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'phone_title', 'Phone'), ENT_QUOTES, 'UTF-8'); ?></h5>
				<p style="color:#666;font-size:14px;">Toll: +880 1759-443004</p>
				<h5 style="font-weight:700;margin-top:14px;"><i class="fa fa-envelope-o" style="color:var(--secondary)"></i> <?php echo htmlspecialchars(tms_page_t($T, 'email_title', 'Email'), ENT_QUOTES, 'UTF-8'); ?></h5>
				<p style="color:#666;font-size:14px;">support@tms.example.com</p>
			</div>
			<div id="contactMap" style="height:220px;border-radius:8px;overflow:hidden;"></div>
		</div>
	</div>
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
	<script>
	window.addEventListener('load', function () {
		if (typeof L === 'undefined') return;
		var map = L.map('contactMap', { scrollWheelZoom: false }).setView([23.7925, 90.4078], 14);
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; OpenStreetMap contributors'
		}).addTo(map);
		L.marker([23.7925, 90.4078]).addTo(map).bindPopup('TMS Office — Gulshan, Dhaka');
	});
	</script>

<?php elseif ($cms): ?>
	<h3 class="wow fadeInDown"><?php echo htmlspecialchars($pagetype, ENT_QUOTES, 'UTF-8'); ?></h3>
	<p><?php echo $cms->detail; ?></p>
<?php else: ?>
	<div style="text-align:center;padding:60px 20px;">
		<i class="fa fa-file-o" style="font-size:50px;color:#ddd;display:block;margin-bottom:16px;"></i>
		<h3 style="color:#aaa;">Page not found</h3>
		<a href="index.php" class="btn btn-primary" style="margin-top:10px;">Back to Home</a>
	</div>
<?php endif; ?>

	</div>
</div>
<!--- /privacy ---->
<!--- footer-top ---->
<!--- /footer-top ---->
<?php include('backend/footer.php');?>
<!-- write us -->
<?php include('backend/write-us.php');?>
</body>
</html>