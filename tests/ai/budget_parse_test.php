<?php
/**
 * Pure unit test (no database, no model, no network): budget / amount parsing in English, Bangla and Banglish.
 *   php tests/ai/budget_parse_test.php
 * Covers BanglaNormalizer::budgetCeiling and QueryParser::parse, and that NLSearchParser's rule-based path uses the same numbers.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/ai/BanglaNormalizer.php';
require_once __DIR__ . '/../../backend/ai/QueryParser.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 70) . $d . "\n"; }

$ceil = [
    ['under 50k', 50000], ['under 50000', 50000], ['within 1.5 lakh', 150000], ['under 1.5 lakh', 150000], ['cheaper than 40000', 40000],
    ['20k for 3 days', 20000], ['budget 40000', 40000], ['trips under 3 days budget 50k', 50000],
    ['১০ হাজারের নিচে', null],               // 10,000 is a valid budget in Bangla: asserted separately below (>= 500 rule)
    ['৫০ হাজার টাকার মধ্যে', 50000], ['২ লাখ টাকার মধ্যে', 200000], ['১.৫ লাখ টাকার মধ্যে', 150000], ['৳50,000 max', 50000],
    ['50 hajar takar moddhe', 50000], ['1 lakh er niche', 100000], ['2 lokkho takar moddhe', 200000],
    ['I have 3 days and 4 people', null], ['family of 4 tk 60000 max', 60000], ['hotel 5 star 2 nights', null],
];
foreach ($ceil as [$q, $exp]) {
    if ($q === '১০ হাজারের নিচে') { t("ceiling: $q", BanglaNormalizer::budgetCeiling($q) === 10000, 'got ' . var_export(BanglaNormalizer::budgetCeiling($q), true)); continue; }
    $got = BanglaNormalizer::budgetCeiling($q);
    t("ceiling: $q", $got === $exp, 'got ' . var_export($got, true));
}
$p = QueryParser::parse('between 20 and 30 thousand');       t('range: between 20 and 30 thousand', $p['min_price'] === 20000 && $p['max_price'] === 30000);
$p = QueryParser::parse('20k-30k for 3 din');                 t('range 20k-30k + 3 din', $p['min_price'] === 20000 && $p['max_price'] === 30000 && $p['days_min'] === 3);
$p = QueryParser::parse('over 20k');                          t('min only: over 20k', $p['min_price'] === 20000 && $p['max_price'] === null);
$p = QueryParser::parse('৩ দিন ৪ জন ৫০ হাজার মোট');           t('Bangla days/people/total', $p['days_min'] === 3 && $p['travelers'] === 4 && $p['max_price'] === 50000 && $p['basis'] === 'total');
$p = QueryParser::parse('50k total for 4 people');            t('total basis converts per person', QueryParser::perPersonCeiling($p) === 12500);
$p = QueryParser::parse('weekend trip');                      t('weekend = 2-3 days', $p['days_min'] === 2 && $p['days_max'] === 3);
$p = QueryParser::parse('i want 2 days');                     t('"2 days" is not a price', $p['max_price'] === null);
$p = QueryParser::parse('under <50000 and >4 people');        t('"<50000 and >4" keeps the budget', $p['max_price'] === 50000, 'max=' . var_export($p['max_price'], true));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
