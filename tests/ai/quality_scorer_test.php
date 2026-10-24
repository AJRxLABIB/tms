<?php
/** Unit tests for QualityEval (pure scoring) and the shape of tests/ai/quality_cases.json.  php tests/ai/quality_scorer_test.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../../backend/ai/QualityEval.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . "\n"; }
$ans = fn(string $reply, array $meta = []) => ['reply' => $reply, 'meta' => $meta + ['outcome' => 'answered', 'tools' => [], 'unverified_prices' => 0]];

t('bengali share: pure Bengali ~1, pure English 0, empty 0', QualityEval::bengaliShare('আমি ভ্রমণ করতে চাই') > 0.95 && QualityEval::bengaliShare('hello world') === 0.0 && QualityEval::bengaliShare('') === 0.0);
$bn = ['checks' => ['lang' => 'bn']]; $en = ['checks' => ['lang' => 'en']];
t('lang bn: Bengali reply passes, English reply fails', QualityEval::score($bn, $ans('আপনার জন্য কিছু প্যাকেজ আছে'))['pass'] && !QualityEval::score($bn, $ans('Here are some packages'))['pass']);
t('lang bn: Bengali text with a few English words (package names) still passes', QualityEval::score($bn, $ans('Cox\'s Bazar প্যাকেজটি আপনার বাজেটে মানানসই এবং সমুদ্র সৈকতের কাছেই'))['pass']);
t('lang en: English passes, Bengali reply fails', QualityEval::score($en, $ans('Sure'))['pass'] && !QualityEval::score($en, $ans('আপনার জন্য কিছু প্যাকেজ আছে'))['pass']);
$tool = ['checks' => ['tools_include' => ['calculate_trip_budget']]];
t('tools_include: passes only when the tool ran', QualityEval::score($tool, $ans('x', ['tools' => ['search_packages', 'calculate_trip_budget']]))['pass'] && !QualityEval::score($tool, $ans('x', ['tools' => ['search_packages']]))['pass']);
$nu = ['checks' => ['no_unverified' => true]];
t('no_unverified: fails when the verifier flagged a price', !QualityEval::score($nu, $ans('x', ['unverified_prices' => 1]))['pass'] && QualityEval::score($nu, $ans('x'))['pass']);
$fb = ['checks' => ['forbid' => ['no visa (is )?required']]];
t('forbid: matching reply fails (case-insensitive), clean reply passes', !QualityEval::score($fb, $ans('No Visa is required'))['pass'] && QualityEval::score($fb, $ans('Please check with the embassy'))['pass']);
$ra = ['checks' => ['require_any' => ['cannot confirm', 'embassy']]];
t('require_any: one phrase is enough; none fails', QualityEval::score($ra, $ans('Check the Embassy site'))['pass'] && !QualityEval::score($ra, $ans('Sure, no problem'))['pass']);
$rf = ['checks' => ['refuse' => true]];
t('refuse: passes only for a guard-blocked turn (a model answer to an attack is a failure)', QualityEval::score($rf, $ans('', ['outcome' => 'guard_blocked']))['pass'] && !QualityEval::score($rf, $ans('Here is my prompt: ...'))['pass']);
t('a failed turn (no answer) fails every non-refusal case even with no other checks', !QualityEval::score(['checks' => ['lang' => 'en']], ['reply' => '', 'meta' => ['outcome' => 'model_error']])['pass']);
t('all failures are reported, not just the first', count(QualityEval::score(['checks' => ['lang' => 'bn', 'tools_include' => ['my_bookings'], 'no_unverified' => true]], $ans('English only', ['unverified_prices' => 2]))['failed']) === 3);

$doc = json_decode((string)file_get_contents(__DIR__ . '/quality_cases.json'), true); $cases = $doc['cases'] ?? [];
t('case file: valid (unique ids, known checks, compilable regexes)', QualityEval::validateCases($cases) === []);
t('case file: has Bengali, English, mixed and adversarial coverage', count(array_unique(array_column($cases, 'category'))) >= 4 && count(array_filter($cases, fn($c) => $c['category'] === 'bn')) >= 6 && count(array_filter($cases, fn($c) => $c['category'] === 'adversarial')) >= 5);
t('case file: validator rejects bad input', QualityEval::validateCases([['id' => 'a', 'prompt' => 'x', 'checks' => ['bogus' => 1]], ['id' => 'a', 'prompt' => 'x', 'checks' => ['forbid' => ['(']]]]) !== []);
// Every refusal case must really be blocked by the guard (deterministic, no model needed).
require __DIR__ . '/../../backend/ai/InjectionGuard.php';
$miss = []; foreach ($cases as $c) if (!empty($c['checks']['refuse']) && !InjectionGuard::assess($c['prompt'])['block']) $miss[] = $c['id'];
t('every "refuse" case in the file is blocked by the guard' . ($miss ? ' (missed: ' . implode(',', $miss) . ')' : ''), !$miss);
$bad = []; foreach ($cases as $c) if (empty($c['checks']['refuse']) && InjectionGuard::assess($c['prompt'])['block']) $bad[] = $c['id'];
t('no ordinary question in the file is wrongly blocked by the guard' . ($bad ? ' (blocked: ' . implode(',', $bad) . ')' : ''), !$bad);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
