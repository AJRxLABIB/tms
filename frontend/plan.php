<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
// Public hub: one place for every planning / preparation / account tool that used to sit
// in the header mega menu and utility bar. No database access needed.
$tmsLogged = !empty($_SESSION['login']);
// [file, title, description, icon]
$planPrimary = [
    ['explore.php',        'Explore Mode',       'Travel any destination — no booking needed.', 'fa-compass'],
    ['tour-map.php',       'Tourism Map',        'Find places, get directions, plan stops.',     'fa-map-marker'],
    ['budget-planner.php', 'Budget Planner',     'Estimate what your trip will cost.',           'fa-calculator'],
    ['ai-recommend.php',   'AI Recommender',     'Trip ideas that match your style.',            'fa-lightbulb-o'],
    ['compare.php',        'Compare Packages',   'Side-by-side comparison.',                     'fa-columns'],
    ['custom-trip.php',    'Custom Trip',        'Build your own itinerary.',                    'fa-paint-brush'],
];
$planPrepare = [
    ['weather.php',        'Weather',            'Forecasts for your dates.',                    'fa-cloud'],
    ['emergency.php',      'Safety & Emergency', 'Emergency numbers and tips.',                  'fa-shield'],
    ['travel-notices.php', 'Travel Notices',     'Entry rules and advisories, with sources.',    'fa-info-circle'],
    ['packing-assistant.php', 'Packing List',    'What to bring for this trip.',                 'fa-briefcase'],
];
$planMore = [
    ['transport-compare.php', 'Transport Compare', 'Fastest, cheapest and greenest way to go.',  'fa-random'],
    ['heatmap.php',        'Travel Heat Map',    'Where travellers are booking most.',           'fa-fire'],
    ['flight-status.php',  'Flight Tracker',     'Live aircraft position (beta).',               'fa-plane'],
    ['destinations.php',   'Destinations',       'Browse places by region.',                     'fa-globe'],
    ['expense-tracker.php','Expense Tracker',    'Log spending during your trip.',               'fa-money'],
    ['wallet.php',         'Travel Wallet',      'Tickets and documents, secured.',              'fa-credit-card'],
    ['bd-guide.php',       'Bangladesh Guide',   'Local tips and practical info.',               'fa-book'],
    ['language.php',       'Language',           'Change the site language.',                    'fa-language'],
];
$planAccount = [
    ['wishlist.php',   'Wishlist',        'Packages you saved.',                 'fa-heart-o'],
    ['alerts.php',     'Price Alerts',    'Get told when prices drop.',          'fa-bell-o'],
    ['group-trips.php','Group Trips',     'Plan together with friends.',         'fa-users'],
    ['loyalty.php',    'Loyalty Points',  'Earn and redeem rewards.',            'fa-gift'],
    ['referral.php',   'Refer Friends',   'Share your code.',                    'fa-share-alt'],
    ['reviews.php',    'Reviews',         'What travellers say.',                'fa-star-o'],
    ['ai-memory.php',  'My AI memory',    'See and edit what the assistant remembers.', 'fa-bookmark-o'],
    ['privacy.php',    'Privacy & My Data','Download or delete your data.',      'fa-lock'],
];
function tms_plan_cards(array $items): void {
    foreach ($items as $it) {
        echo '<a class="tmsn-card" href="' . htmlspecialchars($it[0], ENT_QUOTES, 'UTF-8') . '">'
           . '<span class="tmsn-card-ico"><i class="fa ' . htmlspecialchars($it[3], ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i></span>'
           . '<span><strong>' . htmlspecialchars($it[1], ENT_QUOTES, 'UTF-8') . '</strong><span class="d">' . htmlspecialchars($it[2], ENT_QUOTES, 'UTF-8') . '</span></span></a>';
    }
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TourBuddy | <?php echo htmlspecialchars(tms_t('plan_title', 'Plan your trip'), ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
</head>
<body>
<?php include('backend/header.php'); ?>
<section class="tmsn-plan-hero">
    <h1><?php echo tms_t('plan_title', 'Plan your trip'); ?></h1>
    <p><?php echo tms_t('plan_sub', 'Everything for planning, in one place. Pick a tool to start.'); ?></p>
</section>
<div class="tmsn-plan-wrap">
    <div class="tmsn-plan-grid">
        <a class="tmsn-card is-featured" href="ai-planner.php">
            <span class="tmsn-card-ico"><i class="fa fa-magic" aria-hidden="true"></i></span>
            <span><strong><?php echo tms_t('plan_ai', 'Ask the AI trip planner'); ?></strong><span class="d"><?php echo tms_t('plan_ai_d', 'Tell us where, when and your budget — get a day-by-day plan.'); ?></span></span>
        </a>
    </div>

    <h2><?php echo tms_t('plan_sec_plan', 'Plan'); ?></h2>
    <div class="tmsn-plan-grid"><?php tms_plan_cards($planPrimary); ?></div>

    <h2><?php echo tms_t('plan_sec_prepare', 'Prepare'); ?></h2>
    <div class="tmsn-plan-grid"><?php tms_plan_cards($planPrepare); ?></div>

    <?php if ($tmsLogged): ?>
    <h2><?php echo tms_t('plan_sec_account', 'Your account'); ?></h2>
    <div class="tmsn-plan-grid"><?php tms_plan_cards($planAccount); ?></div>
    <?php endif; ?>

    <details class="tmsn-more">
        <summary><?php echo tms_t('plan_more', 'More tools'); ?></summary>
        <div class="tmsn-plan-grid" style="margin-top:12px"><?php tms_plan_cards($planMore); ?></div>
    </details>
</div>
<?php include('backend/footer.php'); ?>
</body>
</html>
