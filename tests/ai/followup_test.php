<?php
/**
 * Stage 12: follow-up resolution ("the second one", "the cheaper one", "compare them") and the compare_packages tool,
 * against the REAL sandbox DB (temporary packages 9301-9304 created and removed).   php tests/ai/followup_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/ToolRegistry.php'; require_once 'backend/ai/ToolRouter.php'; require_once 'backend/ai/FollowUp.php'; require_once 'backend/ai/InjectionGuard.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 90) . $d . "\n"; }
$clean = fn() => $dbh->exec('DELETE FROM tbltourpackages WHERE PackageId BETWEEN 9301 AND 9304');
$clean();
$mk = $dbh->prepare("INSERT INTO tbltourpackages (PackageId, PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageImage, Status, ApprovalStatus, CancellationPolicy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
$mk->execute([9301, 'Fu Alpha', 'Tour', 'Fuville', 'Fuland', 20000, '3 Days', 'beach', 'd', 'x.jpg', 1, 'approved', 'Free cancellation 7 days before.']);
$mk->execute([9302, 'Fu Beta', 'Tour', 'Fuville', 'Fuland', 12000, '2 Days', 'beach', 'd', 'x.jpg', 1, 'approved', '']);
$mk->execute([9303, 'Fu Gamma', 'Tour', 'Fuville', 'Fuland', 12000, '4 Days', 'beach', 'd', 'x.jpg', 1, 'approved', '']);
$mk->execute([9304, 'Fu Hidden', 'Tour', 'Fuville', 'Fuland', 5000, '4 Days', 'beach', 'd', 'x.jpg', 0, 'approved', '']);
$rows = $dbh->query('SELECT * FROM tbltourpackages WHERE PackageId BETWEEN 9301 AND 9304 AND Status=1')->fetchAll(PDO::FETCH_ASSOC);
$reg = new ToolRegistry(KnowledgeIndex::fromRows($rows), $dbh);
$link = fn(int $id) => '<a href="package-details.php?pkgid=' . $id . '">x</a>';
$hist = [['role' => 'user', 'content' => 'show beach trips'], ['role' => 'assistant', 'content' => 'Try ' . $link(9301) . ', ' . $link(9302) . ' and ' . $link(9303) . ' ' . $link(9301)]];
$ids = FollowUp::recentIds($hist);
t('recentIds: ordered, de-duplicated, from the last assistant turn that linked packages', $ids === [9301, 9302, 9303]);
t('recentIds: user turns never count (a visitor cannot plant ids)', FollowUp::recentIds([['role' => 'user', 'content' => $link(7)]]) === []);
t('recentIds: older assistant list used when the latest reply has no links', FollowUp::recentIds([$hist[1], ['role' => 'user', 'content' => 'thanks'], ['role' => 'assistant', 'content' => 'welcome']]) === [9301, 9302, 9303]);
$prices = [9301 => 20000, 9302 => 12000, 9303 => 12000];
$r = FollowUp::resolve('how much is the second one for 4 people', $ids, $prices);
t('"the second one" -> 9302', $r['kind'] === 'single' && $r['target'] === 9302);
$r = FollowUp::resolve('tell me about the 3rd', $ids, $prices);
t('"3rd" -> 9303', $r['target'] === 9303);
$r = FollowUp::resolve('দ্বিতীয়টির বাতিলের নিয়ম কী', $ids, $prices);
t('Bangla "দ্বিতীয়টির"? (suffix form is not an exact word) -> not guessed', $r['kind'] === 'none');
$r = FollowUp::resolve('দ্বিতীয় প্যাকেজটা কত', $ids, $prices);
t('Bangla "দ্বিতীয়" -> 9302', $r['target'] === 9302);
$r = FollowUp::resolve('the last one please', $ids, $prices);
t('"the last one" -> 9303', $r['target'] === 9303);
$r = FollowUp::resolve('the fifth one', $ids, $prices);
t('ordinal beyond the list: unresolved, nothing picked', $r['kind'] === 'none' && $r['unresolved'] === true);
$r = FollowUp::resolve('which is the cheaper one', $ids, $prices);
t('"cheaper" with a price TIE (9302 = 9303) is unresolved, never a coin flip', $r['kind'] === 'none' && $r['unresolved'] === true);
$r = FollowUp::resolve('the cheaper one of the first and second', $ids, $prices);
t('"cheaper of first and second" -> 9302', $r['kind'] === 'single' && $r['target'] === 9302);
$r = FollowUp::resolve('compare them', $ids, $prices);
t('"compare them" -> all three', $r['kind'] === 'compare' && $r['ids'] === [9301, 9302, 9303]);
$r = FollowUp::resolve('compare the first and third', $ids, $prices);
t('"compare the first and third"', $r['kind'] === 'compare' && $r['ids'] === [9301, 9303]);
$r = FollowUp::resolve('compare them', [], []);
t('compare with nothing to refer to: unresolved flag set, no ids', $r['kind'] === 'none' && $r['unresolved'] === true);
$r = FollowUp::resolve('what is the best time to visit', $ids, $prices);
t('unrelated question is untouched', $r['kind'] === 'none' && $r['unresolved'] === false);
$r = FollowUp::resolve('that one', $ids, $prices);
t('bare "that one" is too vague: nothing acted on', $r['kind'] === 'none');

// router
$tools = fn(string $m, array $ctx = []) => array_column(ToolRouter::plan($m, $ctx, $ids, $prices), 'tool');
t('router: "compare them" -> compare_packages and no catalogue search', $tools('compare these trips') === ['compare_packages']);
$p = ToolRouter::plan('compare the first and third trips', [], $ids, $prices);
t('router: compare args are the resolved ids', ($p[0]['args']['package_ids'] ?? '') === '9301,9303');
$p = ToolRouter::plan('what is the cancellation policy for the first one', [], $ids, $prices);
t('router: cancellation of "the first one" -> policy tool with 9301', in_array(['tool' => 'get_cancellation_policy', 'args' => ['package_id' => 9301]], $p, true));
$p = ToolRouter::plan('tell me about the second one', [], $ids, $prices);
t('router: plain "tell me about the second one" -> get_package 9302 (no unrelated search)', $p === [['tool' => 'get_package', 'args' => ['package_id' => 9302]]]);
$p = ToolRouter::plan('how much for 4 people for the second one', [], $ids, $prices);
t('router: cost question about "the second one" -> trip budget on 9302', (bool)array_filter($p, fn($c) => $c['tool'] === 'calculate_trip_budget' && $c['args']['package_id'] === 9302 && $c['args']['travelers'] === 4));
$p = ToolRouter::plan('the first one', ['package_id' => 9303], $ids, $prices);
t('router: an explicit ordinal beats the page the visitor is on', ($p[0]['args']['package_id'] ?? 0) === 9301);
t('router: with no history nothing changes (still a catalogue search)', array_column(ToolRouter::plan('beach trips under 20k'), 'tool') === ['search_packages']);

// compare tool
$c = $reg->execute('compare_packages', ['package_ids' => '9301,9302'], []);
t('compare_packages: both packages, prices, cheapest computed by code', $c['ok'] && count($c['data']['packages']) === 2 && $c['data']['cheapest_id'] === 9302 && $c['data']['cheapest_saves_bdt_vs_next'] === 8000);
t('compare_packages: missing cancellation policy reported as "none recorded", not invented', $c['data']['packages'][1]['cancellation'] === 'none recorded' && str_contains($c['data']['packages'][0]['cancellation'], 'Free cancellation'));
$c = $reg->execute('compare_packages', ['package_ids' => '9302,9303'], []);
t('compare_packages: equal prices -> cheapest_id null (no false winner)', $c['data']['cheapest_id'] === null);
$c = $reg->execute('compare_packages', ['package_ids' => '9301,9304'], []);
t('compare_packages: unpublished package is listed as not_published and never shown', $c['ok'] && $c['data']['not_published'] === [9304] && count($c['data']['packages']) === 1 && !isset($c['data']['cheapest_id']));
t('compare_packages: one id / five ids / junk rejected', !$reg->execute('compare_packages', ['package_ids' => '9301'], [])['ok'] && !$reg->execute('compare_packages', ['package_ids' => '1,2,3,4,5'], [])['ok'] && !$reg->execute('compare_packages', ['package_ids' => '9301,abc'], [])['ok'] && !$reg->execute('compare_packages', ['package_ids' => "9301,9302'; DROP"], [])['ok']);
$run = ToolRouter::run($reg, [['tool' => 'compare_packages', 'args' => ['package_ids' => '9301,9302']]], [], [], 'compare them');
t('run(): compare result is in the facts with citations for both packages', str_contains($run['facts'], 'compare_packages') && count($run['citations']) === 2);

// Stage 19: state-based refinements (pure logic; full coverage in tests/ai/banglish_test.php)
$r = FollowUp::refine('make that trip cheaper', ['ids' => $ids, 'prices' => $prices, 'focus' => 9301, 'search' => ['query' => 'beach']]);
t('refine: "make that trip cheaper" -> new search strictly below the focused package price', $r['intent'] === 'refine_search' && $r['search']['max_price'] === 19999);
t('resolve: "make that trip cheaper" is no longer a pick from the list', FollowUp::resolve('make that trip cheaper', $ids, $prices)['kind'] === 'none');
t('resolve: "the cheaper one" still picks from the list (tie -> unresolved, never a silent pick)', FollowUp::resolve('the cheaper one', $ids, $prices)['unresolved'] === true);

$clean();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
