<?php
/** Pure tests for backend/ContentHelpers.php (no DB). php tests/unit/content_helpers_test.php */
require_once __DIR__ . '/../../backend/ContentHelpers.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }

// Filler detection: the exact strings seen on the live sample listings.
foreach (['Varies by season — check regional forecasts before booking', 'Regional climate — pack according to season',
          'Varies by attraction (many free, some ticketed)', 'Varies by site — most attractions 9 AM - 6 PM',
          'Bali Ubud Rice Terraces area hotels (budget to luxury options available)', 'Regional farm-to-table cuisine',
          'Reachable via regional airports, trains or long-distance buses connecting to Bali.', 'Bali regional hospital/clinic', ''] as $f) {
    t('filler: ' . substr($f, 0, 40), tms_is_filler_text($f));
}
foreach (['Open daily 8 AM to 5 PM, closed on public holidays', 'Entry 20 BDT for adults', 'Best from November to February', 'Hotel Sea Crown'] as $r) {
    t('real text kept: ' . substr($r, 0, 40), !tms_is_filler_text($r));
}

$generated = [
    'is_sample' => true, 'history' => 'Bali Ubud has long been a significant destination in Bali, valued for terraces.',
    'best_time_to_visit' => 'Varies by season — check regional forecasts before booking', 'climate' => 'Regional climate — pack according to season',
    'entry_fee' => 'Varies by attraction (many free, some ticketed)', 'opening_hours' => 'Varies by site — most attractions 9 AM - 6 PM',
    'adventure_level' => 2, 'family_suitability' => 4, 'couple_suitability' => 2, 'solo_suitability' => 5,
    'budget_estimate' => '$50-100/day (budget)', 'luxury_estimate' => '$200-400/day (luxury)',
    'activities' => ['Photography', 'Boating'], 'local_foods' => ['Regional farm-to-table cuisine', 'Local specialty dishes'],
    'nearby_hotels' => ['Bali area hotels (budget to luxury options available)'], 'nearby_restaurants' => ['Local restaurants near Bali'],
    'transportation_guide' => 'Reachable via regional airports, trains or long-distance buses connecting to Bali.',
];
t('generated meta is detected', tms_meta_is_generated($generated));
t('sample flag is read', tms_meta_is_sample($generated) && !tms_meta_is_sample(['x' => 1]) && !tms_meta_is_sample(null));
$c = tms_clean_package_meta($generated);
t('generated: filler scalars removed', !isset($c['best_time_to_visit'], $c['climate'], $c['entry_fee'], $c['opening_hours'], $c['transportation_guide']));
t('generated: random suitability stars removed', !isset($c['adventure_level']) && !isset($c['family_suitability']) && !isset($c['solo_suitability']));
t('generated: formula estimates removed', !isset($c['budget_estimate']) && !isset($c['luxury_estimate']));
t('generated: filler lists emptied and dropped', !isset($c['nearby_hotels']) && !isset($c['nearby_restaurants']) && !isset($c['local_foods']));
t('generated: template history dropped', !isset($c['history']));
t('generated: real-looking activities kept', ($c['activities'] ?? []) === ['Photography', 'Boating']);

$curated = ['is_sample' => true, 'history' => 'Named after Captain Hiram Cox in the late 18th century.', 'best_time_to_visit' => 'November to March',
            'family_suitability' => 4, 'budget_estimate' => '৳3,000-5,000/day', 'nearby_hotels' => ['Hotel Sea Crown', 'Long Beach Hotel']];
t('curated meta is not "generated"', !tms_meta_is_generated($curated));
$cc = tms_clean_package_meta($curated);
t('curated: everything real is kept', $cc === $curated);

// Images.
t('stock url detected', tms_is_stock_url('https://images.unsplash.com/photo-1?w=800') && !tms_is_stock_url('pkg123.jpg') && !tms_is_stock_url('https://example.com/a.jpg'));
t('specific keyword matches', tms_stock_photo_is_specific("Cox's Bazar Sea Beach", "Cox's Bazar, Bangladesh", 'Beach'));
t('no keyword -> not specific (Bali rice terraces)', !tms_stock_photo_is_specific('Bali Ubud Rice Terraces', 'Bali, Indonesia', 'Nature'));
$stock = 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=800&q=80';
$a = tms_card_image($stock, 'Bali Ubud Rice Terraces', 'Nature', 'Bali, Indonesia');
t('mismatch-prone stock photo becomes an illustration', $a['kind'] === 'placeholder' && strpos($a['src'], 'data:image/svg+xml;base64,') === 0 && $a['caption'] === 'Illustration');
$b = tms_card_image($stock, "Cox's Bazar Sea Beach", 'Beach', "Cox's Bazar, Bangladesh");
t('keyword-matched stock photo kept with a "Representative image" caption', $b['kind'] === 'stock' && $b['src'] === $stock && $b['caption'] === 'Representative image');
$d = tms_card_image('mypkg.jpg', 'Anything', 'Nature', 'X');
t('own upload is shown as-is from the upload folder', $d['kind'] === 'own' && $d['src'] === 'admin/pacakgeimages/mypkg.jpg' && $d['caption'] === '');
$e = tms_card_image('', 'Anything', 'Beach', 'X');
t('missing image -> illustration', $e['kind'] === 'placeholder');
$svg = base64_decode(substr($a['src'], strlen('data:image/svg+xml;base64,')));
t('placeholder is valid-looking SVG with no text content', strpos($svg, '<svg') === 0 && strpos($svg, '<text') === false);
t('onerror attribute escapes and swaps once', strpos(tms_img_onerror($a['fallback']), 'this.onerror=null') !== false && strpos(tms_img_onerror($a['fallback']), '"') !== 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
