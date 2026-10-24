<?php
/**
 * backend/Seo.php — small schema.org (JSON-LD) builders for tms_seo_meta()'s 'jsonld' option.
 *
 * Rules (Rule 3 / "never present demo data as live"):
 *  - Only fields backed by real columns are emitted; empty values are dropped, never invented.
 *  - Hotel / activity markup is emitted ONLY for rows that are not sample data (IsSampleData = 0). Demo rows return
 *    null, so search engines are never told a demonstration listing is a real, bookable property.
 *  - Every builder returns null when it has nothing truthful to say; callers pass the result straight to
 *    tms_seo_meta(['jsonld' => ...]), which ignores empty values.
 */

if (!function_exists('tms_seo_abs_url')) {
    /** Absolute URL for a site-relative path ("package-details.php?pkgid=3"), using the current request's scheme and host. */
    function tms_seo_abs_url(string $relative): string {
        if (preg_match('#^https?://#i', $relative)) return $relative;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $scheme . '://' . $host . $dir . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('tms_seo_clean')) {
    /** Drops null / '' / empty-array members (keeps 0 and "0"). */
    function tms_seo_clean(array $a): array {
        foreach ($a as $k => $v) {
            if (is_array($v)) { $v = tms_seo_clean($v); $a[$k] = $v; }
            if ($v === null || $v === '' || $v === []) unset($a[$k]);
        }
        return $a;
    }
}

if (!function_exists('tms_seo_itemlist_jsonld')) {
    /**
     * ItemList for a page of listed packages.
     * @param iterable<object> $rows  needs PackageId and PackageName
     */
    function tms_seo_itemlist_jsonld(iterable $rows, string $detailPage = 'package-details.php', string $idParam = 'pkgid'): ?array {
        $items = []; $pos = 0;
        foreach ($rows as $r) {
            $id = (int)($r->PackageId ?? 0);
            $name = trim((string)($r->PackageName ?? ''));
            if ($id <= 0 || $name === '') continue;
            $items[] = [
                '@type'    => 'ListItem',
                'position' => ++$pos,
                'url'      => tms_seo_abs_url($detailPage . '?' . $idParam . '=' . $id),
                'name'     => $name,
            ];
        }
        if (!$items) return null;
        return ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items];
    }
}

if (!function_exists('tms_seo_hotel_jsonld')) {
    /** Hotel markup for a real (non-sample) tblhotels row; null for demo rows. */
    function tms_seo_hotel_jsonld(object $h, string $url): ?array {
        if ((int)($h->IsSampleData ?? 1) !== 0) return null;
        $name = trim((string)($h->HotelName ?? ''));
        if ($name === '') return null;
        $out = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Hotel',
            'name'        => $name,
            'url'         => $url,
            'description' => trim((string)($h->Description ?? '')),
            'image'       => trim((string)($h->HotelImage ?? '')),
            'address'     => tms_seo_clean([
                '@type'           => 'PostalAddress',
                'addressLocality' => trim((string)($h->City ?? '')),
                'addressCountry'  => trim((string)($h->Country ?? '')),
            ]),
        ];
        if (($h->StarRating ?? 0) > 0) $out['starRating'] = ['@type' => 'Rating', 'ratingValue' => (int)$h->StarRating];
        if (($h->PricePerNight ?? 0) > 0) $out['priceRange'] = 'BDT ' . (int)$h->PricePerNight . ' per night';
        return tms_seo_clean($out);
    }
}

if (!function_exists('tms_seo_activity_jsonld')) {
    /** TouristAttraction markup for a real (non-sample) tblactivities row; null for demo rows. */
    function tms_seo_activity_jsonld(object $a, string $url): ?array {
        if ((int)($a->IsSampleData ?? 1) !== 0) return null;
        $name = trim((string)($a->ActivityName ?? ''));
        if ($name === '') return null;
        $out = [
            '@context'    => 'https://schema.org',
            '@type'       => 'TouristAttraction',
            'name'        => $name,
            'url'         => $url,
            'description' => trim((string)($a->Description ?? '')),
            'image'       => trim((string)($a->ActivityImage ?? '')),
            'address'     => tms_seo_clean([
                '@type'           => 'PostalAddress',
                'addressLocality' => trim((string)($a->City ?? '')),
                'addressCountry'  => trim((string)($a->Country ?? '')),
            ]),
        ];
        return tms_seo_clean($out);
    }
}
