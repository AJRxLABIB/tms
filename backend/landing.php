<?php
/**
 * includes/landing.php — Public Landing Page
 *
 * Batch 1 (landing page + auth gating). Rendered by index.php for any
 * visitor who isn't signed in. Reuses includes/header.php (which is already
 * guest-aware — it shows Sign In / Sign Up buttons and the auth modals when
 * $_SESSION['login'] is empty) and includes/footer.php, so the login/signup
 * forms, CSRF handling, dark mode toggle, and design language are exactly
 * the same as the rest of the site — nothing here is a parallel system.
 *
 * This file is only ever include()'d from index.php, never requested
 * directly, so it doesn't repeat session_start()/config.php.
 */
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy | Smart AI-Powered Travel Marketplace</title>
<?php
// NEW (Round 118, master prompt item 53 -- SEO): this is the site's real
// public homepage -- frontend/index.php only ever renders this file for a
// signed-out visitor (see the doc comment at the top of this file), so
// index.php itself is never what a search crawler or a shared link sees,
// and was never worth wiring. This page was missing canonical/OpenGraph
// tags entirely (a hand-written <title>/<meta description> only) despite
// being the single highest-SEO-value page on the whole site. No fabricated
// data -- same 'no image unless a real one exists' convention already
// used by every other tms_seo_meta() call site.
tms_seo_meta([
    'title'       => 'TourBuddy | Smart AI-Powered Travel Marketplace',
    'description' => 'TourBuddy is an AI-powered travel marketplace — smart trip planning, live weather, multi-currency pricing, an AI travel assistant, and a marketplace of tour packages, all in one place. Sign up free to start planning.',
]);
?>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">

<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/animate.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">

<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW({offset:80}).init()});</script>
</head>
<body class="tmsn-hero tms-home">

<?php
$tms_landing_mode = true;
$tms_home_signed_in = false;
include('backend/header.php');
?>
<link href="<?php echo tms_asset_ver('css/tms-pages.css'); ?>" rel="stylesheet">
<?php
include('backend/home-sections.php');
include('backend/footer.php');
?>
</body>
</html>
