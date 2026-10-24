<?php
/**
 * backend/brand.php — the TourBuddy logo lockup, shared by the header, footer and sign-in pages.
 * Uses images/logo/mark.png (made from the full logo by `php scripts/make_brand_assets.php`) when it exists;
 * otherwise a plain icon, so the site never shows a broken image.
 */
if (!function_exists('tms_brand_mark_html')) {
    function tms_brand_mark_html(string $size = '36'): string
    {
        $root = dirname(__DIR__);
        $px = max(16, (int)$size);
        if (is_file($root . '/images/logo/mark.png')) {
            $v = (int)@filemtime($root . '/images/logo/mark.png');
            return '<img class="tms-brand-img" src="images/logo/mark.png?v=' . $v . '" width="' . $px . '" height="' . $px . '" alt="" decoding="async">'
                 . '<span class="tms-wordmark"><b class="w1">Tour</b><b class="w2">Buddy</b></span>';
        }
        return '<span class="tmsn-logo-mark"><i class="fa fa-globe" aria-hidden="true"></i></span>'
             . '<span class="tms-wordmark"><b class="w1">Tour</b><b class="w2">Buddy</b></span>';
    }
}
