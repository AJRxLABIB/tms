<?php
/**
 * Shared page head for the personal-planning pages (saved-places.php, my-trips.php, trip-planner.php).
 * Only prints the <head>; each page includes backend/header.php and backend/footer.php itself so their globals stay in scope.
 */
function tms_trips_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** @param list<string> $scripts script paths relative to the site root, loaded with defer in this order */
function tms_trips_head(string $title, bool $withMap, array $scripts): void
{
    ?>
<!DOCTYPE HTML>
<html lang="<?php echo tms_trips_e(tms_active_language()); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<meta name="robots" content="noindex">
<title>TourBuddy | <?php echo tms_trips_e($title); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<?php if ($withMap): ?>
<link rel="preconnect" href="https://tiles.openfreemap.org" crossorigin>
<link rel="stylesheet" href="js/vendor/maplibre/maplibre-gl.css">
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-map-ui.css'); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo tms_asset_ver('css/tms-trips.css'); ?>">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<?php if ($withMap): ?>
<script src="js/vendor/maplibre/maplibre-gl-csp.js" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-maplibre.js'); ?>" defer></script>
<script src="<?php echo tms_asset_ver('js/tms-map-pages.js'); ?>" defer></script>
<?php endif; ?>
<?php foreach ($scripts as $s): ?>
<script src="<?php echo tms_asset_ver($s); ?>" defer></script>
<?php endforeach; ?>
</head>
<?php
}
