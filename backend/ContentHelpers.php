<?php
/**
 * backend/ContentHelpers.php — presentation helpers for placeholder content.
 *
 * Why: generated sample packages (data/generate_packages.py) carry boilerplate that reads like fact
 * ("Varies by season — check regional forecasts", "<Place> area hotels (budget to luxury options available)",
 * random 1-5 suitability stars) and stock photos picked by category, so a Bali page could show a city skyline.
 * These helpers keep that material from being presented as real information. Pure functions, no DB access.
 */

if (!function_exists('tms_filler_phrases')) {
    /** Lower-case fragments that mark generic, non-informative filler text. */
    function tms_filler_phrases(): array {
        return [
            'varies by season', 'varies by attraction', 'varies by site', 'varies by destination',
            'regional climate', 'pack according to season', 'check regional forecasts',
            'budget to luxury options available', 'farm-to-table cuisine', 'local specialty dishes',
            'regional specialties', 'traditional local cuisine', 'traditional local specialties',
            'local restaurants near', 'nearby attractions around', 'regional hospital/clinic',
            'reachable via regional airports', 'long-distance buses connecting to',
            'regional lodge cuisine', 'hearty regional cuisine', 'heritage-region street food',
        ];
    }
}

if (!function_exists('tms_is_filler_text')) {
    /** True when the text is empty or contains a known filler phrase. */
    function tms_is_filler_text($text): bool {
        $t = mb_strtolower(trim((string)$text), 'UTF-8');
        if ($t === '' || $t === 'n/a') return true;
        foreach (tms_filler_phrases() as $p) {
            if (strpos($t, $p) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('tms_meta_is_generated')) {
    /** True when package meta was produced from the generic template rather than written for the destination. */
    function tms_meta_is_generated(array $meta): bool {
        $h = mb_strtolower((string)($meta['history'] ?? ''), 'UTF-8');
        if (strpos($h, 'has long been a significant destination in') !== false) return true;
        foreach ((array)($meta['nearby_hotels'] ?? []) as $x) {
            if (strpos(mb_strtolower((string)$x, 'UTF-8'), 'budget to luxury options available') !== false) return true;
        }
        return false;
    }
}

if (!function_exists('tms_meta_is_sample')) {
    function tms_meta_is_sample($meta): bool {
        return is_array($meta) && !empty($meta['is_sample']);
    }
}

if (!function_exists('tms_clean_package_meta')) {
    /**
     * Returns the meta with filler removed. For template-generated meta the invented numbers are removed too:
     * the 1-5 suitability stars are random and the per-day estimates are a price formula, not research.
     */
    function tms_clean_package_meta(array $meta): array {
        $generated = tms_meta_is_generated($meta);
        $scalarKeys = ['best_time_to_visit', 'climate', 'entry_fee', 'opening_hours', 'transportation_guide', 'budget_estimate', 'luxury_estimate'];
        foreach ($scalarKeys as $k) {
            if (isset($meta[$k]) && tms_is_filler_text($meta[$k])) unset($meta[$k]);
        }
        foreach (['nearby_hotels', 'nearby_restaurants', 'nearby_attractions', 'nearby_hospitals', 'local_foods', 'activities'] as $k) {
            if (!isset($meta[$k]) || !is_array($meta[$k])) continue;
            $meta[$k] = array_values(array_filter($meta[$k], static function ($x) { return !tms_is_filler_text($x); }));
            if (!$meta[$k]) unset($meta[$k]);
        }
        if ($generated) {
            foreach (['adventure_level', 'family_suitability', 'couple_suitability', 'solo_suitability', 'budget_estimate', 'luxury_estimate'] as $k) {
                unset($meta[$k]);
            }
            // A history that is only the template sentence adds nothing.
            unset($meta['history']);
        }
        return $meta;
    }
}

if (!function_exists('tms_stock_photo_is_specific')) {
    /**
     * Stock photos are chosen by category keyword. When none of the specific keywords match, the picker falls back to
     * a generic city photo, which is how a rice-terrace package ended up with a skyline. Returns true only when a
     * specific keyword (beach, waterfall, mountain ...) matched the destination text.
     */
    function tms_stock_photo_is_specific(string $name, string $location, string $type): bool {
        $h = mb_strtolower($name . ' ' . $location . ' ' . $type, 'UTF-8');
        foreach (['waterfall', 'falls', 'forest', 'jungle', 'sundarban', 'mountain', 'hill', 'peak', 'himalay', 'everest', 'desert', 'dune', 'sahara',
                  'lighthouse', 'lake', 'river', 'glacier', 'canyon', 'gorge', 'volcano', 'crater', 'temple', 'pagoda', 'shrine', 'monastery',
                  'mosque', 'church', 'fort', 'palace', 'ruins', 'castle', 'national park', 'sanctuary', 'safari', 'tiger', 'wetland',
                  'backwater', 'mangrove', 'beach', 'coast', 'bay', "cox's bazar", 'island', 'trek', 'hiking'] as $kw) {
            if (strpos($h, $kw) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('tms_is_stock_url')) {
    function tms_is_stock_url(string $img): bool {
        return (bool)preg_match('#^https?://images\.unsplash\.com/#i', trim($img));
    }
}

if (!function_exists('tms_placeholder_image')) {
    /**
     * On-brand illustrated placeholder as an inline SVG data URI (no network, never broken). Colours follow the
     * category so cards stay distinguishable; it carries no text, so it cannot claim to show the real place.
     */
    function tms_placeholder_image(string $category = '', string $seed = ''): string {
        $c = mb_strtolower($category, 'UTF-8');
        $pal = ['#104B3A', '#1F8A66']; $glyph = 'M0 240 L120 120 L190 190 L270 90 L400 240 Z';   // mountains
        if (preg_match('/beach|island|sea|coast/', $c))        { $pal = ['#0E5A6B', '#2FA3A0']; $glyph = 'M0 200 Q50 170 100 200 T200 200 T300 200 T400 200 L400 240 L0 240 Z'; }
        elseif (preg_match('/city|heritage|histor|relig/', $c)) { $pal = ['#2B3A55', '#5B7BA6']; $glyph = 'M20 240 V150 H70 V240 M90 240 V100 H150 V240 M170 240 V170 H230 V240 M250 240 V120 H320 V240 M340 240 V160 H385 V240 Z'; }
        elseif (preg_match('/wild|nature|forest|park/', $c))    { $pal = ['#17543A', '#4C9A62']; $glyph = 'M60 240 L100 130 L140 240 Z M150 240 L200 100 L250 240 Z M260 240 L310 140 L360 240 Z'; }
        elseif (preg_match('/advent|trek|desert/', $c))         { $pal = ['#7A4F0E', '#C98A2B']; $glyph = 'M0 240 Q100 140 200 200 T400 170 L400 240 Z'; }
        $id = 'g' . substr(md5($category . $seed), 0, 6);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 240" preserveAspectRatio="xMidYMid slice">'
             . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $pal[0] . '"/><stop offset="1" stop-color="' . $pal[1] . '"/></linearGradient></defs>'
             . '<rect width="400" height="240" fill="url(#' . $id . ')"/>'
             . '<circle cx="320" cy="64" r="26" fill="#F7F8F5" fill-opacity=".35"/>'
             . '<path d="' . $glyph . '" fill="#F7F8F5" fill-opacity=".22"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}

if (!function_exists('tms_card_image')) {
    /**
     * Decides what a package card/hero image should be.
     * @return array{src:string,fallback:string,kind:string,caption:string}  kind: own | stock | placeholder
     */
    function tms_card_image(string $imageField, string $name, string $type, string $location): array {
        $fallback = tms_placeholder_image($type, $name);
        $img = trim($imageField);
        if ($img === '') return ['src' => $fallback, 'fallback' => $fallback, 'kind' => 'placeholder', 'caption' => 'Illustration'];
        if (!tms_is_stock_url($img)) {
            $src = preg_match('#^https?://#i', $img) ? $img : 'admin/pacakgeimages/' . $img;
            return ['src' => $src, 'fallback' => $fallback, 'kind' => 'own', 'caption' => ''];
        }
        if (!tms_stock_photo_is_specific($name, $location, $type)) {
            return ['src' => $fallback, 'fallback' => $fallback, 'kind' => 'placeholder', 'caption' => 'Illustration'];
        }
        return ['src' => $img, 'fallback' => $fallback, 'kind' => 'stock', 'caption' => 'Representative image'];
    }
}

if (!function_exists('tms_img_onerror')) {
    /** Attribute that swaps a broken image for the placeholder exactly once. */
    function tms_img_onerror(string $fallbackDataUri): string {
        return ' onerror="this.onerror=null;this.src=\'' . htmlspecialchars($fallbackDataUri, ENT_QUOTES, 'UTF-8') . '\';"';
    }
}
