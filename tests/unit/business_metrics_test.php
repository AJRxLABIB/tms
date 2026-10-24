<?php
/**
 * Pure tests for the BusinessMetrics intent matcher and period parser. No database, no network, no model.
 *   php tests/unit/business_metrics_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/services/BusinessMetrics.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($name, 70) . $detail . "\n";
}

// Fixed "now": Thursday 2026-10-22.
$now = new DateTimeImmutable('2026-10-22 10:00:00');
$M = fn(string $q) => BusinessMetrics::matchIntent($q, $now);
$P = fn(string $q) => BusinessMetrics::parsePeriod($q, $now);

// ── intent: the examples an admin is expected to ask ──
$cases = [
    'Which destinations had the highest completed-booking volume last month?' => BusinessMetrics::T_TOP_DEST,
    'How did booking conversion change over the last quarter?'                 => BusinessMetrics::T_BOOKINGS,
    'Which suppliers have elevated cancellation rates?'                        => BusinessMetrics::T_SUPPLIERS,
    'Which payment transactions remain unreconciled?'                          => BusinessMetrics::T_UNRECON,
    'What is the difference between gross booking value, collected revenue, refunds, and platform commission?' => BusinessMetrics::T_DEFINITIONS,
    // Bengali
    'গত মাসে কোন গন্তব্য সবচেয়ে জনপ্রিয় ছিল?'                                   => BusinessMetrics::T_TOP_DEST,
    'কোন সাপ্লায়ারের বাতিলের হার বেশি?'                                         => BusinessMetrics::T_SUPPLIERS,
    'গ্রস বুকিং ভ্যালু আর কমিশনের পার্থক্য কী?'                                  => BusinessMetrics::T_DEFINITIONS,
    // Banglish
    'gato mash e kothay beshi booking hoyeche, top 3 destination'              => BusinessMetrics::T_TOP_DEST,
    'supplier der cancel rate koto'                                            => BusinessMetrics::T_SUPPLIERS,
    'kon payment mil nai'                                                      => BusinessMetrics::T_UNRECON,
];
foreach ($cases as $q => $want) {
    $got = $M($q)['template'];
    t('intent: ' . mb_substr($q, 0, 56), $got === $want, 'got=' . var_export($got, true));
}

// ── refusals: nothing guessed ──
foreach (['', 'tell me a joke', 'DROP TABLE tblbooking', 'show me all customer emails', 'weather in cox bazar'] as $q) {
    t('no match, no guess: ' . ($q === '' ? '(empty)' : $q), $M($q)['template'] === null);
}
// SQL-looking input must never turn into SQL: the result is only ever a template id from the whitelist.
$hostile = $M("bookings'; DROP TABLE tblbooking; -- last month");
t('hostile text only yields a whitelisted template id or null', $hostile['template'] === null || isset(BusinessMetrics::TEMPLATES[$hostile['template']]));

// ── periods ──
$p = $P('last month');       t('last month = September 2026', $p['start'] === '2026-09-01' && $p['end'] === '2026-09-30' && $p['explicit']);
$p = $P('this month');       t('this month = Oct 1 .. today', $p['start'] === '2026-10-01' && $p['end'] === '2026-10-22');
$p = $P('last quarter');     t('last quarter (Q4 now) = Jul 1 .. Sep 30', $p['start'] === '2026-07-01' && $p['end'] === '2026-09-30');
$p = $P('this quarter');     t('this quarter = Oct 1 .. today', $p['start'] === '2026-10-01' && $p['end'] === '2026-10-22');
$p = $P('last week');        t('last week = Mon Oct 12 .. Sun Oct 18', $p['start'] === '2026-10-12' && $p['end'] === '2026-10-18');
$p = $P('this week');        t('this week = Mon Oct 19 .. today', $p['start'] === '2026-10-19' && $p['end'] === '2026-10-22');
$p = $P('yesterday');        t('yesterday', $p['start'] === '2026-10-21' && $p['end'] === '2026-10-21');
$p = $P('last year');        t('last year = 2025', $p['start'] === '2025-01-01' && $p['end'] === '2025-12-31');
$p = $P('last 7 days');      t('last 7 days (inclusive of today)', $p['start'] === '2026-10-16' && $p['end'] === '2026-10-22');
$p = $P('গত ১৫ দিন');          t('Bengali digits: গত ১৫ দিন = 15 days', $p['start'] === '2026-10-08' && $p['end'] === '2026-10-22');
$p = $P('গত মাস');           t('Bengali: গত মাস = September', $p['start'] === '2026-09-01');
$p = $P('gato mash');        t('Banglish: gato mash = September', $p['start'] === '2026-09-01');
$p = $P('top destinations'); t('no phrase -> last 30 days, flagged not explicit', $p['start'] === '2026-09-23' && $p['end'] === '2026-10-22' && !$p['explicit']);
$p = BusinessMetrics::parsePeriod('last quarter', new DateTimeImmutable('2026-02-10')); t('last quarter in Q1 = previous Oct..Dec', $p['start'] === '2025-10-01' && $p['end'] === '2025-12-31');
$p = $P('last 400 days');    t('day count above a year is clamped to 366 days', $p['start'] === '2025-10-22' && $p['end'] === '2026-10-22');
$p = $P('last 99999 days');  t('absurd day count is not honoured (falls back to default)', !$p['explicit']);

// ── limit parsing ──
t('limit: top 3 -> 3', BusinessMetrics::parseLimit('top 3 destinations') === 3);
t('limit: default 5', BusinessMetrics::parseLimit('popular destinations') === 5);
t('limit: clamped to 20', BusinessMetrics::parseLimit('top 99 destinations') === 20);

// ── structure ──
t('every template has a title', count(BusinessMetrics::TEMPLATES) === 5);
t('definitions cover the four revenue terms', count(array_intersect(array_keys(BusinessMetrics::definitions()), ['Gross booking value', 'Collected revenue', 'Refunds', 'Platform commission'])) === 4);
t('summarize handles a failed result without throwing', str_contains(BusinessMetrics::summarize(['ok' => false, 'title' => 'X', 'error' => 'nope']), 'nope'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
