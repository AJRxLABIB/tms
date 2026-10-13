<?php
/**
 * SiteStats.php — real, database-derived counts for public/marketing sections.
 *
 * Replaces hardcoded marketing numbers ("3,200+ bookings", "700M+" ...) that
 * had no data behind them. Each figure is independent: if a query fails or
 * returns 0, that figure is simply omitted by the caller -- never invented.
 */
if (!function_exists('tms_site_stats')) {
    /**
     * @return array{packages:int,destinations:int,hotels:int,activities:int,reviews:int,avg_rating:?float}
     */
    function tms_site_stats(PDO $dbh): array {
        $out = ['packages' => 0, 'destinations' => 0, 'hotels' => 0, 'activities' => 0, 'reviews' => 0, 'avg_rating' => null];
        $one = static function (string $sql) use ($dbh): ?array {
            try {
                $r = $dbh->query($sql)->fetch(PDO::FETCH_ASSOC);
                return is_array($r) ? $r : null;
            } catch (Throwable $e) {
                error_log('[TMS SiteStats] ' . $e->getMessage());
                return null;
            }
        };
        if ($r = $one("SELECT COUNT(*) AS c, COUNT(DISTINCT NULLIF(TRIM(PackageLocation),'')) AS d FROM tbltourpackages WHERE Status=1")) {
            $out['packages'] = (int)$r['c'];
            $out['destinations'] = (int)$r['d'];
        }
        if ($r = $one("SELECT COUNT(*) AS c FROM tblhotels")) { $out['hotels'] = (int)$r['c']; }
        if ($r = $one("SELECT COUNT(*) AS c FROM tblactivities")) { $out['activities'] = (int)$r['c']; }
        if ($r = $one("SELECT COUNT(*) AS c, AVG(Rating) AS a FROM tblreviews WHERE Status=1 AND Rating BETWEEN 1 AND 5")) {
            $out['reviews'] = (int)$r['c'];
            $out['avg_rating'] = $out['reviews'] > 0 ? round((float)$r['a'], 1) : null;
        }
        return $out;
    }
}
