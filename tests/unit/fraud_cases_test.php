<?php
/**
 * Pure tests (no database) for FraudCases scoring and transitions.  php tests/unit/fraud_cases_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/services/FraudCases.php';

$pass = 0; $fail = 0;
function ft(string $name, bool $ok, string $detail = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : '  ' . $detail) . "\n"; }

// Minimum sample: below threshold means no case at all.
ft('3 payment attempts: no case', FraudCases::score('repeated_payment_attempts', ['attempts' => 3, 'failed' => 3, 'hours' => 24])['score'] === 0);
$r = FraudCases::score('repeated_payment_attempts', ['attempts' => 4, 'failed' => 2, 'hours' => 24]);
ft('4 attempts, 2 failed: score 90, high', $r['score'] === 90 && $r['severity'] === 'high', json_encode($r));
ft('score capped at 100', FraudCases::score('repeated_payment_attempts', ['attempts' => 30, 'failed' => 30])['score'] === 100);
ft('reason mentions the figures', str_contains($r['reason'], '4 payment attempts') && str_contains($r['reason'], '2 failed'));

ft('1 booking: no duplicate case', FraudCases::score('duplicate_booking', ['copies' => 1])['score'] === 0);
$r = FraudCases::score('duplicate_booking', ['copies' => 2]);
ft('2 copies: 35, low', $r['score'] === 35 && $r['severity'] === 'low', json_encode($r));
ft('4 copies: 75, high', FraudCases::score('duplicate_booking', ['copies' => 4])['score'] === 75);

ft('2 coupon bookings: no case', FraudCases::score('coupon_referral_abuse', ['coupon_bookings' => 2, 'days' => 7])['score'] === 0);
ft('3 coupon bookings: 60', FraudCases::score('coupon_referral_abuse', ['coupon_bookings' => 3, 'days' => 7])['score'] === 60);
ft('referral cluster: 6 accounts, 1 phone', FraudCases::score('coupon_referral_abuse', ['referred' => 6, 'distinct_phones' => 1])['score'] === 60);
ft('referral: 6 accounts, 6 phones is fine', FraudCases::score('coupon_referral_abuse', ['referred' => 6, 'distinct_phones' => 6])['score'] === 0);
ft('referral: under 5 accounts is too small', FraudCases::score('coupon_referral_abuse', ['referred' => 4, 'distinct_phones' => 1])['score'] === 0);

ft('2999 points: no case', FraudCases::score('loyalty_anomaly', ['points' => 2999, 'hours' => 24])['score'] === 0);
ft('3000 points: 60', FraudCases::score('loyalty_anomaly', ['points' => 3000, 'hours' => 24])['score'] === 60);

ft('3 bookings all cancelled: too few to judge', FraudCases::score('cancellation_pattern', ['bookings' => 3, 'cancelled' => 3])['score'] === 0);
ft('5 bookings, 2 cancelled (40%): no case', FraudCases::score('cancellation_pattern', ['bookings' => 5, 'cancelled' => 2])['score'] === 0);
$r = FraudCases::score('cancellation_pattern', ['bookings' => 5, 'cancelled' => 4, 'days' => 90]);
ft('5 bookings, 4 cancelled: case with reason', $r['score'] > 0 && str_contains($r['reason'], '4 of 5'), json_encode($r));

ft('unknown signal scores 0', FraudCases::score('nope', ['x' => 1])['score'] === 0);
ft('zero score has empty reason', FraudCases::score('duplicate_booking', ['copies' => 1])['reason'] === '');

ft('severity bands', FraudCases::severity(39) === 'low' && FraudCases::severity(40) === 'medium' && FraudCases::severity(69) === 'medium' && FraudCases::severity(70) === 'high');

// Review workflow: a case can't skip straight back from a final decision except by reopening.
ft('open -> cleared allowed', FraudCases::canTransition('open', 'cleared'));
ft('open -> confirmed allowed', FraudCases::canTransition('open', 'confirmed'));
ft('cleared -> confirmed refused', !FraudCases::canTransition('cleared', 'confirmed'));
ft('cleared -> reviewing (reopen) allowed', FraudCases::canTransition('cleared', 'reviewing'));
ft('confirmed -> cleared refused', !FraudCases::canTransition('confirmed', 'cleared'));
ft('reviewing -> reviewing allowed', FraudCases::canTransition('reviewing', 'reviewing'));
ft('open -> open refused', !FraudCases::canTransition('open', 'open'));
ft('unknown state refused', !FraudCases::canTransition('weird', 'cleared'));

// No signal can ever yield an automatic penalty: the class has no such API.
$m = array_map(function ($x) { return $x->getName(); }, (new ReflectionClass('FraudCases'))->getMethods(ReflectionMethod::IS_PUBLIC));
$bad = array_filter($m, function ($n) { return (bool)preg_match('/ban|block|suspend|cancel|refund|penal/i', $n); });
ft('no public method bans/blocks/suspends/cancels/refunds', !$bad, implode(',', $bad));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
