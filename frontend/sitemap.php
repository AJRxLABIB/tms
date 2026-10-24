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
include('backend/config.php');

header('Content-Type: application/xml; charset=utf-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

function tms_sitemap_url(string $loc, string $changefreq = 'weekly', float $priority = 0.6, ?string $lastmod = null): string {
    // lastmod only from a real stored timestamp (W3C date); never "now".
    $lm = ($lastmod !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $lastmod, $m) && $m[1] !== '0000-00-00') ? "    <lastmod>{$m[1]}</lastmod>\n" : '';
    return "  <url>\n"
        . "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n"
        . $lm
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
    ['hotels.php', 'daily', 0.8],
    ['activities.php', 'daily', 0.8],
    ['destinations.php', 'daily', 0.8],
    ['blog.php', 'daily', 0.7],
    ['bd-guide.php', 'monthly', 0.6],
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
    try {
        $pkgs = $dbh->query("SELECT PackageId, COALESCE(UpdationDate, Creationdate) AS lm FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . " ORDER BY PackageId DESC")->fetchAll(PDO::FETCH_NUM);
    } catch (Exception $e) { // older schema without the date columns: ids only
        $pkgs = array_map(fn($i) => [$i, null], $dbh->query("SELECT PackageId FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . " ORDER BY PackageId DESC")->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($pkgs as [$pid, $lm]) {
        echo tms_sitemap_url("$base/package-details.php?pkgid=" . (int)$pid, 'weekly', 0.8, $lm);
    }
} catch (Exception $e) { /* skip quietly */ }

// Blog posts
try {
    try {
        $posts = $dbh->query("SELECT id, COALESCE(UpdatedAt, CreatedAt) FROM tblblog ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
    } catch (Exception $e) { // table without the date columns: ids only
        $posts = array_map(fn($i) => [$i, null], $dbh->query("SELECT id FROM tblblog ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($posts as [$bid, $lm]) {
        echo tms_sitemap_url("$base/blog-post.php?id=" . (int)$bid, 'monthly', 0.6, $lm);
    }
} catch (Exception $e) { /* skip quietly */ }

// Hotels (Round 37 sample-data marketplace)
try {
    $hotels = $dbh->query("SELECT HotelId FROM tblhotels WHERE Status=1 ORDER BY HotelId DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($hotels as $hid) {
        echo tms_sitemap_url("$base/hotel-details.php?id=" . (int)$hid, 'weekly', 0.6);
    }
} catch (Exception $e) { /* table not present yet — skip quietly */ }

// Activities (Round 37 sample-data marketplace)
try {
    $activities = $dbh->query("SELECT ActivityId FROM tblactivities WHERE Status=1 ORDER BY ActivityId DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($activities as $aid) {
        echo tms_sitemap_url("$base/activity-details.php?id=" . (int)$aid, 'weekly', 0.6);
    }
} catch (Exception $e) { /* table not present yet — skip quietly */ }

echo '</urlset>' . "\n";
