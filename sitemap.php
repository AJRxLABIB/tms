<?php
/**
 * sitemap.php — dynamically generated XML sitemap.
 *
 * There's no build step or cron in this project, so rather than a static
 * sitemap.xml that goes stale the moment a package or blog post is added,
 * this generates the sitemap fresh on each request (search engines fetch it
 * a few times a day at most, so the extra queries are negligible).
 *
 * Served at /sitemap.php and referenced from robots.txt. The sitemap
 * protocol doesn't require a literal ".xml" filename — only the
 * "application/xml" Content-Type below matters.
 */
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');

header('Content-Type: application/xml; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function tms_sitemap_url(string $loc, string $changefreq = 'weekly', float $priority = 0.6): string {
    return "  <url>\n"
        . "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n"
        . "    <changefreq>$changefreq</changefreq>\n"
        . "    <priority>" . number_format($priority, 1) . "</priority>\n"
        . "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Static, high-value pages
$staticPages = [
    ['index.php', 'daily', 1.0],
    ['package-list.php', 'daily', 0.9],
    ['destinations.php', 'daily', 0.8],
    ['explore.php', 'daily', 0.7],
    ['blog.php', 'daily', 0.7],
    ['budget-planner.php', 'weekly', 0.5],
    ['custom-trip.php', 'weekly', 0.5],
    ['ai-recommend.php', 'weekly', 0.6],
    ['ai-planner.php', 'weekly', 0.6],
    ['virtual-guide.php', 'weekly', 0.5],
    ['image-analyzer.php', 'weekly', 0.5],
    ['weather.php', 'weekly', 0.4],
    ['emergency.php', 'monthly', 0.3],
    ['reviews.php', 'weekly', 0.5],
    ['register.php', 'monthly', 0.4],
    ['login.php', 'monthly', 0.3],
];
foreach ($staticPages as [$path, $freq, $pri]) {
    echo tms_sitemap_url("$base/$path", $freq, $pri);
}

// Static content pages (About, Terms, Privacy, Contact, etc.)
try {
    $types = $dbh->query("SELECT DISTINCT type FROM tblpages")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($types as $type) {
        echo tms_sitemap_url("$base/page.php?type=" . urlencode($type), 'monthly', 0.4);
    }
} catch (Exception $e) { /* table not present yet — skip quietly */ }

// Tour packages
try {
    $pkgs = $dbh->query("SELECT PackageId FROM tbltourpackages ORDER BY PackageId DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pkgs as $pid) {
        echo tms_sitemap_url("$base/package-details.php?pkgid=" . (int)$pid, 'weekly', 0.8);
    }
} catch (Exception $e) { /* skip quietly */ }

// Blog posts
try {
    $posts = $dbh->query("SELECT id FROM tblblog ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($posts as $bid) {
        echo tms_sitemap_url("$base/blog-post.php?id=" . (int)$bid, 'monthly', 0.6);
    }
} catch (Exception $e) { /* skip quietly */ }

echo '</urlset>' . "\n";
