<?php
/**
 * scripts/profile_pages.php — measure how fast the main pages respond. CLI only.
 *
 *   php scripts/profile_pages.php http://localhost/tms            (each page requested 3 times, anonymous)
 *   php scripts/profile_pages.php http://localhost/tms --runs=5
 *   php scripts/profile_pages.php http://localhost/tms --cookie="PHPSESSID=abc123"   (signed-in view)
 *
 * Prints, per page: HTTP status, time to first byte (server think time), total time, size, and the Server-Timing header
 * when the site runs with TMS_PROFILE=1 in .env (adds boot/db marks). The first run is slow because PHP/MySQL are cold;
 * compare the later ones. A TTFB above ~300 ms on localhost means server-side work (queries, outbound HTTP) is the
 * problem; a small TTFB with a long browser load means scripts/images/CDN requests are the problem (check the Network tab).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$base = rtrim((string)($argv[1] ?? ''), '/');
if ($base === '' || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Usage: php scripts/profile_pages.php http://localhost/tms [--runs=3] [--cookie=\"PHPSESSID=...\"]\n");
    exit(2);
}
$runs = 3; $cookie = '';
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--runs=(\d+)$/', $a, $m)) { $runs = max(1, min(10, (int)$m[1])); }
    if (str_starts_with($a, '--cookie=')) { $cookie = substr($a, 9); }
}
if (!function_exists('curl_init')) { fwrite(STDERR, "The curl extension is required.\n"); exit(2); }

$pages = [
    'index.php', 'destinations.php', 'package-list.php', 'package-details.php?pkgid=1', 'hotels.php', 'activities.php',
    'blog.php', 'explore.php', 'compare.php', 'page.php?type=aboutus', 'health.php',
    'api/explore.php?action=search&q=cox', 'api/ai.php?action=crowd_prediction&destination=Bali',
];

printf("%-44s %4s %8s %8s %8s  %s\n", 'page', 'http', 'ttfb ms', 'total ms', 'KB', 'Server-Timing');
foreach ($pages as $p) {
    $rows = [];
    for ($i = 0; $i < $runs; $i++) {
        $headers = '';
        $ch = curl_init($base . '/' . $p);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$headers) { $headers .= $h; return strlen($h); },
        ]);
        if ($cookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        $st = '';
        if (preg_match('/^Server-Timing:\s*(.+)$/mi', $headers, $m)) { $st = trim($m[1]); }
        $rows[] = [
            'code' => $err ? 'ERR' : (string)$info['http_code'],
            'ttfb' => round(($info['starttransfer_time'] ?? 0) * 1000),
            'total' => round(($info['total_time'] ?? 0) * 1000),
            'kb' => round(strlen((string)$body) / 1024, 1),
            'st' => $st,
        ];
    }
    $last = end($rows);
    $ttfb = array_column($rows, 'ttfb'); $tot = array_column($rows, 'total');
    printf("%-44s %4s %8s %8s %8s  %s\n", substr($p, 0, 44), $last['code'],
        ($runs > 1 ? min($ttfb) . '-' . max($ttfb) : $ttfb[0]), ($runs > 1 ? min($tot) . '-' . max($tot) : $tot[0]), $last['kb'], $last['st']);
}
echo "\nTTFB/total shown as min-max over {$runs} run(s). Slow rows (> 800 ms) deserve a look; send this table to your developer.\n";
