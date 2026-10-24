<?php
/**
 * scripts/map_sync_places.php — copy tour packages that already have real coordinates into the map places table.
 *
 *   php scripts/map_sync_places.php            dry run: lists what would be added or flagged
 *   php scripts/map_sync_places.php --apply    inserts the new places
 *
 * Rules (nothing is invented):
 *  - only tbltourpackages rows with valid MapLat/MapLng that are publicly bookable are copied;
 *  - generated sample packages (PackageMeta.is_sample) are copied with is_demo = 1 so the map labels them as demo data;
 *  - rows start UNVERIFIED (is_verified = 0). An administrator verifies them;
 *  - an existing place is never overwritten, so an administrator's corrected coordinates survive. A package whose coordinates now
 *    differ from its place by more than 200 m is only reported;
 *  - destination guides (tbl_bd_destination_guides) have no coordinates, so they are not copied (no guessing).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/services/MapPlaces.php';

$apply = in_array('--apply', $argv ?? [], true);
$svc = new MapPlaces($dbh);

try { $dbh->query('SELECT 1 FROM tbl_map_places LIMIT 1'); }
catch (Throwable $e) { fwrite(STDERR, "tbl_map_places is missing. Run: php database/migrate.php (migration 041)\n"); exit(1); }

$pub = defined('TMS_PKG_PUBLIC_SQL') ? TMS_PKG_PUBLIC_SQL : 'Status=1';
$rows = $dbh->query("SELECT PackageId, PackageName, PackageType, PackageLocation, Country, Region, MapLat, MapLng, PackageMeta, PackageFetures
                     FROM tbltourpackages WHERE $pub AND MapLat IS NOT NULL AND MapLat <> '' AND MapLng IS NOT NULL AND MapLng <> ''")->fetchAll(PDO::FETCH_ASSOC);

$existing = [];
foreach ($dbh->query("SELECT PlaceId, SourceRef, Lat, Lng FROM tbl_map_places WHERE Source = 'package'")->fetchAll(PDO::FETCH_ASSOC) as $e) $existing[$e['SourceRef']] = $e;

$added = 0; $skipped = 0; $invalid = 0; $moved = 0;
echo ($apply ? 'APPLY' : 'DRY RUN') . ': ' . count($rows) . " package(s) with coordinates.\n";
foreach ($rows as $r) {
    $ref = (string)$r['PackageId'];
    $lat = (float)$r['MapLat']; $lng = (float)$r['MapLng'];
    if (!MapPlaces::validCoords($lat, $lng)) { $invalid++; echo "  invalid coordinates, skipped: #{$ref} {$r['PackageName']}\n"; continue; }
    if (isset($existing[$ref])) {
        $skipped++;
        $d = MapPlaces::haversineM($lat, $lng, (float)$existing[$ref]['Lat'], (float)$existing[$ref]['Lng']);
        if ($d > 200) { $moved++; echo sprintf("  differs by %d m from its place (not changed): #%s %s\n", $d, $ref, $r['PackageName']); }
        continue;
    }
    $meta = json_decode((string)$r['PackageMeta'], true);
    $demo = (is_array($meta) && !empty($meta['is_sample'])) || str_contains((string)$r['PackageMeta'], '"is_sample": true') || str_contains((string)$r['PackageMeta'], '"is_sample":true');
    $loc = trim((string)$r['PackageLocation']);
    $locality = trim(explode(',', $loc)[0]);
    $in = [
        'name' => (string)$r['PackageName'], 'category' => MapPlaces::categoryForPackageType((string)$r['PackageType']), 'subcategory' => (string)$r['PackageType'],
        'lat' => $lat, 'lng' => $lng, 'locality' => $locality, 'region' => (string)$r['Region'], 'country' => (string)$r['Country'],
        'source' => 'package', 'source_ref' => $ref, 'package_id' => (int)$r['PackageId'], 'is_demo' => $demo ? 1 : 0, 'status' => 'active',
    ];
    echo "  " . ($apply ? 'add' : 'would add') . ": #{$ref} {$r['PackageName']} [{$in['category']}]" . ($demo ? ' (demo)' : '') . "\n";
    if (!$apply) { $added++; continue; }
    $res = $svc->create($in, 'sync', ['allow_duplicates' => true]);
    if ($res['ok']) $added++; else echo '    FAILED: ' . json_encode($res['errors']) . "\n";
}
echo "Done. added={$added} already-present={$skipped} invalid={$invalid} coordinates-differ={$moved}\n";
