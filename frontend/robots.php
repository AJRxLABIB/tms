<?php
/**
 * robots.php — served as /robots.txt (see .htaccess). Generated so the Disallow paths and the absolute Sitemap URL
 * follow the real install path ("/" or "/tourbuddy/"); the static robots.txt used a root-relative Sitemap line, which
 * crawlers reject, and root-only paths that were wrong for a /tourbuddy/ install.
 * Note: crawlers only read robots.txt at the HOST ROOT; for a /tourbuddy/ install, serve this output from there.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');   // '' or '/tourbuddy'

$private = [
    '/admin/', '/supplier/', '/includes/', '/backend/', '/api/', '/uploads/', '/data/', '/database/', '/scripts/', '/tests/',
    '/cart.php', '/checkout.php', '/payment.php', '/pay-now.php', '/tour-history.php', '/profile.php', '/wishlist.php',
    '/ticket.php', '/wallet.php', '/wallet-document.php', '/my-trip.php', '/my-marketplace-bookings.php',
    '/notifications.php', '/mark-notifications-read.php', '/user-register-ajax.php', '/check_availability.php',
    '/coupon-check.php', '/apply-coupon.php', '/export-my-data.php', '/two-factor-setup.php', '/change-password.php',
];
echo "User-agent: *\nAllow: {$dir}/\n";
foreach ($private as $p) echo "Disallow: {$dir}{$p}\n";
echo "\nSitemap: {$scheme}://{$host}{$dir}/sitemap.php\n";
