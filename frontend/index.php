<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');

// Batch 1 (landing page + auth gating): the site used to drop every visitor
// straight into this homepage. Now this is the gate — a guest sees the new
// public marketing landing page (backend/landing.php) and must sign in or
// register before reaching the actual system. Everything below this block
// is completely unchanged and only ever renders for a logged-in user.
if (empty($_SESSION['login'])) {
    include('backend/landing.php');
    exit;
}

// Fetch random packages for homepage
// (previously ORDER BY RAND() LIMIT 8 — a full-table sort that got noticeably heavier
// once the catalog grew to 400+ packages; replaced with an indexed pool + PHP shuffle)
$featuredPkgs = [];
try {
    $pool = $dbh->query("SELECT PackageId, PackageName, PackageImage, PackagePrice, PackageLocation, PackageType, PackageDuration, AvgRating FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . " ORDER BY PackageId DESC LIMIT 60")->fetchAll(PDO::FETCH_OBJ);
    shuffle($pool);
    $featuredPkgs = array_slice($pool, 0, 8);
} catch(Exception $e) {}

// PERSONALIZATION (Round 115 continuation, "TourBuddy V2" prompt -- confirmed
// real gap: this homepage always showed the same random pool to every
// logged-in user, with zero use of the booking-history/wishlist/
// recently-viewed signal already tracked elsewhere in the app). Purely
// additive -- $featuredPkgs above and its section below are completely
// unchanged, still rendered for every user; this only adds a second,
// personalized section that appears ABOVE it when real signal exists.
// See backend/services/PersonalizedHomepage.php for why this is
// deterministic SQL, not an AI call, on every homepage pageview.
require_once 'backend/services/PersonalizedHomepage.php';
$recommended = ['packages' => [], 'reason' => 'none'];
try {
    $recommended = PersonalizedHomepage::forUser($dbh, $_SESSION['login'], 8);
} catch (Exception $e) {}
$recommendedReasonLabel = [
    'booking_history'  => tms_t('home_sub_recommended_booking', 'Because you\'ve booked trips like this before'),
    'wishlist'         => tms_t('home_sub_recommended_wishlist', 'Based on packages in your wishlist'),
    'recently_viewed'  => tms_t('home_sub_recommended_viewed', 'Based on packages you recently viewed'),
][$recommended['reason']] ?? '';

// Fetch blog posts
$blogPosts = [];
try {
    $stmt = $dbh->prepare("SELECT * FROM tblblog ORDER BY CreatedAt DESC LIMIT 3");
    $stmt->execute();
    $blogPosts = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) {}

// Fetch reviews
$reviews = [];
try {
    $stmt = $dbh->prepare("SELECT * FROM tblreviews WHERE Status=1 ORDER BY PostingDate DESC LIMIT 3");
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Premium Tourism Management System</title>
<meta name="description" content="Discover the world with TourBuddy - your premium travel partner. Book tour packages, explore destinations, and plan your perfect trip.">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0F4C81">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">

<!-- Bootstrap -->
<link href="css/bootstrap.min2.css" rel="stylesheet">
<!-- Font Awesome -->
<link href="css/font-awesome.min2.css" rel="stylesheet">
<!-- Animate.css -->
<link href="css/animate.css" rel="stylesheet">
<!-- TourBuddy Modern Theme -->
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<!-- Scripts -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW({offset:80}).init()});</script>
</head>
<body class="tmsn-hero tms-home">

<?php include('backend/header.php'); ?>

<!-- Flash Messages -->
<?php if(isset($_SESSION['reg_success'])): ?>
<div style="background:linear-gradient(135deg,#00b09b,#96c93d);color:#fff;padding:14px 20px;text-align:center;font-size:15px;font-weight:600;">
    <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['reg_success']); unset($_SESSION['reg_success']); ?>
</div>
<?php endif; ?>

<link href="<?php echo tms_asset_ver('css/tms-pages.css'); ?>" rel="stylesheet">
<?php
$tms_home_signed_in = true;
include('backend/home-sections.php');
?>

<?php
// index.php used to carry its own hand-copied footer + dark-mode block instead
// of the shared backend/footer.php — meaning every future footer/dark-mode fix
// (translations, the theme system, etc.) had to be applied twice, and silently
// drifted out of sync (this copy was still on the old translation-less English
// links and the old, disconnected dark-mode toggle). Switched to the same
// include every other page uses.
include('backend/footer.php');
include('backend/write-us.php');
?>

</body>
</html>
