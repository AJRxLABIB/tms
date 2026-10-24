<?php
/** QueryParser: labelled phrase table (English / Bangla / Banglish / mixed).   php tests/ai/query_parser_test.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require_once 'backend/ai/BanglaNormalizer.php'; require_once 'backend/ai/QueryParser.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 78) . $d . "\n"; }
// phrase => expected subset of fields (only listed keys are checked; null means "must be null")
$cases = [
  // ── the cases the audit PROVED failed before ──
  'trips under 3 days budget 50k'            => ['max_price' => 50000, 'days_min' => 3, 'days_max' => 3],
  '20k for 3 days'                           => ['max_price' => 20000, 'days_min' => 3],
  'cheaper than 40000'                       => ['max_price' => 40000],
  'family of 4 tk 60000 max'                 => ['max_price' => 60000, 'travelers' => 4],
  '১০ হাজারের নিচে'                           => ['max_price' => 10000],
  'under 20k for 3 days in winter'           => ['max_price' => 20000, 'days_min' => 3, 'season' => 'winter'],
  'under 1.5 lakh'                           => ['max_price' => 150000],
  '50 hajar takar moddhe cox bazar e 3 din'  => ['max_price' => 50000, 'days_min' => 3],
  // ── English ceilings ──
  'under 50k'                                => ['max_price' => 50000, 'min_price' => null],
  'below ৳20,000'                            => ['max_price' => 20000],
  'less than 30000 taka'                     => ['max_price' => 30000],
  'within 1.5 lakh'                          => ['max_price' => 150000],
  'budget is 40000'                          => ['max_price' => 40000],
  'up to 2 lakh please'                      => ['max_price' => 200000],
  'not more than 25k'                        => ['max_price' => 25000],
  '30000 tk or less'                         => ['max_price' => 30000],
  '15000 max'                                => ['max_price' => 15000],
  'max 12000 bdt'                            => ['max_price' => 12000],
  'at most 80k'                              => ['max_price' => 80000],
  // ── minimum and ranges ──
  'over 20k'                                 => ['min_price' => 20000, 'max_price' => null],
  'at least 30000'                           => ['min_price' => 30000],
  'above 50 thousand'                        => ['min_price' => 50000],
  '20k-30k'                                  => ['min_price' => 20000, 'max_price' => 30000],
  'between 20 and 30 thousand'               => ['min_price' => 20000, 'max_price' => 30000],
  '20000 to 30000'                           => ['min_price' => 20000, 'max_price' => 30000],
  'from 10k to 25k'                          => ['min_price' => 10000, 'max_price' => 25000],
  // ── Bangla ──
  '৫০ হাজার টাকার মধ্যে'                      => ['max_price' => 50000],
  '৩০০০০ এর নিচে'                             => ['max_price' => 30000],
  '২ লাখ টাকার মধ্যে ঘুরতে চাই'                 => ['max_price' => 200000],
  'বাজেট ৪০ হাজার'                            => ['max_price' => 40000],
  '৪ জন, ৩ দিন, ৬০ হাজার টাকার মধ্যে'          => ['max_price' => 60000, 'travelers' => 4, 'days_min' => 3],
  '২০ হাজার থেকে ৩০ হাজার'                     => ['min_price' => 20000, 'max_price' => 30000],
  '৫০ হাজারের উপরে'                           => ['min_price' => 50000],
  'শীতকালে সিলেট'                             => ['season' => 'winter'],
  'বর্ষায় বান্দরবান'                          => ['season' => 'monsoon'],
  // ── Banglish ──
  '50 hajar takar moddhe'                    => ['max_price' => 50000],
  '30 hazar er niche'                        => ['max_price' => 30000],
  'ek lakh er moddhe'                        => ['max_price' => 100000],
  'dui lakh takay'                           => ['max_price' => 200000],
  '40 hajar er upore'                        => ['min_price' => 40000],
  '5 din 4 jon 80 hajar'                     => ['max_price' => 80000, 'days_min' => 5, 'travelers' => 4],
  // ── days / travellers / season only ──
  '3-4 days trip'                            => ['days_min' => 3, 'days_max' => 4, 'max_price' => null],
  '3 nights 4 days'                          => ['days_min' => 3],
  'weekend getaway'                          => ['days_min' => 2, 'days_max' => 3],
  'a week in sylhet'                         => ['days_min' => 7, 'days_max' => 7],
  '2 weeks'                                  => ['days_min' => 14],
  'for 4 people'                             => ['travelers' => 4, 'max_price' => null],
  '2 adults and 2 children'                  => ['travelers' => 4],
  'honeymoon trip'                           => ['travelers' => 2],
  'solo traveller'                           => ['travelers' => 1],
  'family of 5 under 90k'                    => ['travelers' => 5, 'max_price' => 90000],
  'monsoon in sylhet'                        => ['season' => 'monsoon'],
  'summer holiday'                           => ['season' => 'summer'],
  // ── basis ──
  '60000 total for 4 people'                 => ['max_price' => 60000, 'travelers' => 4, 'basis' => 'total'],
  '15000 per person'                         => ['max_price' => 15000, 'basis' => 'per_person'],
  'মোট ৮০ হাজার টাকা'                        => ['max_price' => 80000, 'basis' => 'total'],
  // ── things that must NOT be read as money ──
  '৩০০০০ এর নিচে কক্সবাজার প্যাকেজ'            => ['max_price' => 30000],   // regression: byte-cut Bangla context broke the regex
  '৫০ হাজার টাকার মধ্যে কক্সবাজারের সুন্দর সমুদ্র সৈকত প্যাকেজ আছে কি' => ['max_price' => 50000],
  'cox bazar'                                => ['max_price' => null, 'min_price' => null, 'days_min' => null, 'travelers' => null, 'season' => null],
  'package 1234'                             => ['max_price' => null],
  'booking BK 50000123'                      => ['max_price' => null],
  '3 days'                                   => ['max_price' => null, 'days_min' => 3],
  '4 people'                                 => ['max_price' => null, 'travelers' => 4],
  '5 star hotel'                             => ['max_price' => null],
  '20% discount'                             => ['max_price' => null],
  'under 300 taka'                           => ['max_price' => null],   // below 500 is not a trip budget
  'hello'                                    => ['max_price' => null, 'days_min' => null],
  ''                                         => ['max_price' => null],
  'under <50000 and >4 people'               => ['max_price' => 50000, 'travelers' => 4],
  'ｕｎｄｅｒ 50k'                            => [],   // fullwidth letters: no crash (not claimed to parse)
];
foreach ($cases as $phrase => $exp) {
    $r = QueryParser::parse($phrase); $bad = [];
    foreach ($exp as $k => $v) if ($r[$k] !== $v) $bad[] = "$k=" . json_encode($r[$k]) . " (want " . json_encode($v) . ")";
    t(mb_substr($phrase, 0, 70) ?: '(empty)', !$bad, $bad ? implode('; ', $bad) : '');
}
// derived behaviour
t('perPersonCeiling: explicit total 60000 for 4 -> 15000 per person', QueryParser::perPersonCeiling(QueryParser::parse('60000 total for 4 people')) === 15000);
t('perPersonCeiling: unspecified basis is NOT divided', QueryParser::perPersonCeiling(QueryParser::parse('under 60000 for 4 people')) === 60000);
t('perPersonCeiling: no budget -> null', QueryParser::perPersonCeiling(QueryParser::parse('3 days in sylhet')) === null);
// legacy API still behaves (BanglaNormalizer::budgetCeiling delegates)
t('BanglaNormalizer::budgetCeiling now uses the parser (audit case)', BanglaNormalizer::budgetCeiling('trips under 3 days budget 50k') === 50000 && BanglaNormalizer::budgetCeiling('১০ হাজারের নিচে') === 10000);
t('BanglaNormalizer::budgetCeiling: no budget / tiny amounts -> null', BanglaNormalizer::budgetCeiling('best beach') === null && BanglaNormalizer::budgetCeiling('under 300 tk') === null);
// robustness: random / hostile input never throws and stays bounded
$ok = true; mt_srand(7);
for ($i = 0; $i < 300; $i++) { $s = ''; $n = mt_rand(0, 60); for ($j = 0; $j < $n; $j++) $s .= ['under ', '50k ', '৳', '১২৩ ', 'lakh ', "\xE0\xA6", '3 days ', ' - ', 'to ', '999999999999999999 ', '.', ',', 'জন ', 'ek ', 'hajar '][mt_rand(0, 14)];
    try { $r = QueryParser::parse($s); if (($r['max_price'] ?? 0) > 1000000000 || ($r['travelers'] ?? 1) > 50) $ok = false; } catch (Throwable $e) { $ok = false; echo "   threw on " . json_encode($s) . ': ' . $e->getMessage() . "\n"; } }
t('300 random/hostile strings: never throws, values stay within bounds', $ok);
$t0 = microtime(true); QueryParser::parse(str_repeat('under 50k for 3 days ', 400)); t('long input (8,400 chars) parses fast (< 250 ms)', microtime(true) - $t0 < 0.25);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
