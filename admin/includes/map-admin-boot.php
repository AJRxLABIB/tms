<?php
/**
 * Shared bootstrap for the map administration pages. Include AFTER includes/config.php.
 * Gives each page: admin authentication + role check, $actor, $places (MapPlaces), e(), a "migrations missing" guard.
 * Allowed roles: superadmin (implicit), manager, content_manager. The same check is repeated on every page, so
 * hiding the sidebar link is never the security boundary.
 */
require_once dirname(__DIR__, 2) . '/backend/services/MapPlaces.php';
require_once dirname(__DIR__, 2) . '/backend/services/MapAdmin.php';
require_once dirname(__DIR__, 2) . '/backend/services/MapImages.php';
tms_require_admin();
tms_admin_require_role($dbh, ['manager', 'content_manager']);
$actor  = (string)($_SESSION['alogin'] ?? 'admin');
$places = new MapPlaces($dbh);
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
try {
    foreach (['tbl_map_places', 'tbl_map_place_aliases', 'tbl_map_place_images', 'tbl_map_corrections', 'tbl_map_audit', 'tbl_map_duplicate_dismissals'] as $tmsMapTable) {
        $dbh->query('SELECT 1 FROM ' . $tmsMapTable . ' LIMIT 1');
    }
} catch (Throwable $e) {
    http_response_code(503);
    exit('The map tables are missing. Run: php database/migrate.php (migrations 041 and 043).');
}
