<?php
/**
 * scripts/make_brand_assets.php — builds the small logo files from the full TourBuddy logo.
 *
 * Save the full logo as  images/logo/tourbuddy-logo.png  (or .jpg), then run:  php scripts/make_brand_assets.php
 * Writes (needs the PHP GD extension, which XAMPP has):
 *   images/logo/mark.png      pin + traveller only (header, footer, sign-in), transparent-looking white background trimmed
 *   images/favicon-32.png, images/icon-192.png, images/icon-512.png   (browser tab, PWA / home-screen icons)
 * It trims the mark by finding the non-white area above the "TourBuddy" wordmark, so no hand-measured crop is needed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('gd')) { fwrite(STDERR, "PHP GD extension is not enabled (php.ini: extension=gd).\n"); exit(1); }
$root = dirname(__DIR__);
$src = null;
foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) { if (is_file("$root/images/logo/tourbuddy-logo.$ext")) { $src = "$root/images/logo/tourbuddy-logo.$ext"; break; } }
if (!$src) { fwrite(STDERR, "Save the logo as images/logo/tourbuddy-logo.png first.\n"); exit(1); }
$im = @imagecreatefromstring((string)file_get_contents($src));
if (!$im) { fwrite(STDERR, "Could not read the logo image.\n"); exit(1); }
$w = imagesx($im); $h = imagesy($im);

// Mark = everything above the wordmark: scan the top 66% for non-white pixels.
$limitY = (int)($h * 0.66);
$minX = $w; $minY = $limitY; $maxX = 0; $maxY = 0;
for ($y = 0; $y < $limitY; $y += 2) {
    for ($x = 0; $x < $w; $x += 2) {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
        if ($r < 235 || $g < 235 || $b < 235) { if ($x < $minX) $minX = $x; if ($x > $maxX) $maxX = $x; if ($y < $minY) $minY = $y; if ($y > $maxY) $maxY = $y; }
    }
}
if ($maxX <= $minX || $maxY <= $minY) { fwrite(STDERR, "Could not find the mark in the image.\n"); exit(1); }
$bw = $maxX - $minX; $bh = $maxY - $minY; $side = (int)(max($bw, $bh) * 1.08);
$cx = (int)(($minX + $maxX) / 2); $cy = (int)(($minY + $maxY) / 2);

function tms_square(GdImage $im, int $cx, int $cy, int $side, int $out, float $pad): GdImage {
    $dst = imagecreatetruecolor($out, $out);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    $inner = (int)round($out * (1 - 2 * $pad));
    imagecopyresampled($dst, $im, (int)(($out - $inner) / 2), (int)(($out - $inner) / 2), $cx - intdiv($side, 2), $cy - intdiv($side, 2), $inner, $inner, $side, $side);
    return $dst;
}
$jobs = [['images/logo/mark.png', 256, 0.0], ['images/favicon-32.png', 32, 0.0], ['images/icon-192.png', 192, 0.08], ['images/icon-512.png', 512, 0.10]];
foreach ($jobs as [$rel, $px, $pad]) {
    $o = tms_square($im, $cx, $cy, $side, $px, $pad);
    imagepng($o, "$root/$rel", 9); imagedestroy($o);
    echo "wrote $rel ({$px}px)\n";
}
echo "Done. Hard-refresh the browser (Ctrl+F5) to see the new logo.\n";
