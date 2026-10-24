<?php
/**
 * Stage 14: code-built suggestion chips, answer cache keyed by the full model input, and the database-backed rate limiter
 * (including a real concurrency test with parallel PHP processes).   php tests/ai/conversation_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/SiteAssistant.php'; require_once 'backend/ai/AIRateLimit.php'; require_once 'backend/ai/Suggestions.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 90) . $d . "\n"; }

// ── Suggestions ───────────────────────────────────────────────────────────────
$pk = fn(int $n) => array_map(fn($i) => ['id' => 'pkg:' . $i], range(1, $n));
$s = Suggestions::build([['tool' => 'search_packages']], $pk(3));
t('several packages found -> compare / cheaper / policy / about-first chips', $s === ['Compare them', 'Which is the cheaper one?', 'Cancellation policy for the first one', 'Tell me about the first one']);
t('one package found -> policy / seats / cost chips', Suggestions::build([['tool' => 'search_packages']], $pk(1)) === ['Cancellation policy for the first one', 'Any seats left for the first one?', 'How much for 4 people for the first one?']);
t('nothing found -> a way forward, never a package claim', Suggestions::build([['tool' => 'search_packages']], []) === ['Show best-rated packages', 'Packages under 20k']);
t('after a comparison -> follow-ups about one of them', Suggestions::build([['tool' => 'compare_packages']], $pk(2))[0] === 'Cancellation policy for the first one');
t('no catalogue tool (e.g. a general question) -> no code chips', Suggestions::build([], []) === []);
t('Bangla turn -> Bangla chips', Suggestions::build([['tool' => 'search_packages']], $pk(2), 'bn')[0] === 'তুলনা করো');
// every English code chip must actually route to the tool its label promises
require_once 'backend/ai/ToolRouter.php';
$ids = [9001, 9002, 9003]; $prices = [9001 => 3, 9002 => 2, 9003 => 5];
$route = fn(string $m) => array_column(ToolRouter::plan($m, [], $ids, $prices), 'tool');
t('chip "Compare them" routes to compare_packages', $route('Compare them') === ['compare_packages']);
t('chip "Which is the cheaper one?" resolves to get_package of the cheapest (9002)', ToolRouter::plan('Which is the cheaper one?', [], $ids, $prices) === [['tool' => 'get_package', 'args' => ['package_id' => 9002]]]);
t('chip "Cancellation policy for the first one" routes to get_cancellation_policy 9001', in_array(['tool' => 'get_cancellation_policy', 'args' => ['package_id' => 9001]], ToolRouter::plan('Cancellation policy for the first one', [], $ids, $prices), true));
t('chip "Any seats left for the first one?" routes to check_booking_readiness', in_array('check_booking_readiness', $route('Any seats left for the first one?'), true));
t('chip "How much for 4 people for the first one?" routes to calculate_trip_budget with 4 travellers', (bool)array_filter(ToolRouter::plan('How much for 4 people for the first one?', [], $ids, $prices), fn($c) => $c['tool'] === 'calculate_trip_budget' && $c['args']['travelers'] === 4 && $c['args']['package_id'] === 9001));
t('chip "Tell me about the first one" routes to get_package 9001', $route('Tell me about the first one') === ['get_package']);
t('chip "Show best-rated packages" still searches', in_array('search_packages', $route('Show best-rated packages'), true));
t('Bangla chip "তুলনা করো" routes to compare_packages', $route('তুলনা করো') === ['compare_packages']);
$bnExpect = ['compare' => 'compare_packages', 'cancel_first' => 'get_cancellation_policy', 'seats_first' => 'check_booking_readiness', 'cost_first' => 'calculate_trip_budget', 'about_first' => 'get_package', 'cheaper' => 'get_package'];
$bn = (new ReflectionClassConstant('Suggestions', 'TEXT'))->getValue()['bn']; $en = (new ReflectionClassConstant('Suggestions', 'TEXT'))->getValue()['en'];
foreach ($bnExpect as $key => $tool) {
    t("every Bangla chip routes to what it promises: $key -> $tool", in_array($tool, $route($bn[$key]), true), $bn[$key]);
    t("every English chip routes to what it promises: $key -> $tool", in_array($tool, $route($en[$key]), true), $en[$key]);
}
$m = Suggestions::merge(['A', 'B'], ['B', 'Open https://evil.example now', '<b>x</b>', str_repeat('z', 80), 'Weather?', 'More', 'Extra']);
t('merge: code chips first, duplicates/urls/markup/overlong dropped, max 4', $m === ['A', 'B', 'x', 'Weather?'], json_encode($m));
t('merge: markup stripped, never a URL', !array_filter($m, fn($c) => preg_match('~https?://|<~', $c)));

// ── Rate limiter ──────────────────────────────────────────────────────────────
$dbh->exec("DELETE FROM tbl_ai_rate WHERE k LIKE 'tst%'");
$k = 'tst' . str_repeat('a', 37); $k2 = 'tst' . str_repeat('b', 37);
$res = []; for ($i = 1; $i <= 5; $i++) $res[] = AIRateLimit::hit($dbh, $k, 3, 1000000)['allowed'];
t('limit 3: calls 1-3 allowed, 4-5 refused', $res === [true, true, true, false, false]);
t('another subject has its own counter', AIRateLimit::hit($dbh, $k2, 3, 1000000)['allowed'] === true);
$r = AIRateLimit::hit($dbh, $k, 3, 1000000);
t('refusal reports count and a Retry-After inside the window', $r['count'] === 6 && $r['retry_after'] > 0 && $r['retry_after'] <= 3600);
t('next hour: the counter starts again', AIRateLimit::hit($dbh, $k, 3, 1000000 + 3600)['allowed'] === true);
t('key() is a one-way hash: no raw address or e-mail is stored', AIRateLimit::key('guest-ip', '203.0.113.9') !== '203.0.113.9' && strlen(AIRateLimit::key('user', 'a@b.c')) === 40 && AIRateLimit::key('user', 'x') !== AIRateLimit::key('guest-ip', 'x'));
t('default limits: 60 per guest address, 300 per account', AIRateLimit::limit('guest') === 60 && AIRateLimit::limit('user') === 300);
// real concurrency: 8 processes x 10 calls against limit 30 -> exactly 30 allowed, never more
$dbh->exec("DELETE FROM tbl_ai_rate WHERE k LIKE 'tst%'");
$ck = 'tst' . str_repeat('c', 37);
$script = tempnam(sys_get_temp_dir(), 'rl') . '.php';
file_put_contents($script, '<?php chdir(' . var_export(getcwd(), true) . '); ob_start(); require "backend/config.php"; ob_end_clean(); require "backend/ai/AIRateLimit.php"; $a=0; for($i=0;$i<10;$i++){ if(AIRateLimit::hit($dbh,' . var_export($ck, true) . ',30,2000000)["allowed"]) $a++; } echo $a;');
$procs = []; for ($i = 0; $i < 8; $i++) { $procs[] = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]); }
$allowed = 0; foreach ($procs as $i => $p) { $allowed += (int)trim((string)stream_get_contents($pipes[$i][1])); proc_close($p); }
@unlink($script);
$final = (int)$dbh->query("SELECT n FROM tbl_ai_rate WHERE k='$ck'")->fetchColumn();
t('8 parallel processes x 10 calls, limit 30: exactly 30 allowed (atomic counter)', $allowed === 30 && $final === 80, "allowed=$allowed counted=$final");
$dbh->exec("DELETE FROM tbl_ai_rate WHERE k LIKE 'tst%'");

// ── Answer cache ──────────────────────────────────────────────────────────────
$dbh->exec("DELETE FROM tblcache WHERE cache_key LIKE 'ai_chat_%'");
$sent = 0;
$mk = function () use ($dbh, &$sent) {
    $ai = new AIClient($dbh, null, new GeminiProvider('k', 'm'));
    $ai->setProviderChain([new GeminiProvider('k', 'm')]);
    $ai->setTransport(function ($req, $p, $to) use (&$sent) { $sent++; return [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'answer ' . $sent, 'suggestions' => ['Model chip']])]]]]]]), null]; });
    return new SiteAssistant($dbh, $ai);
};
$a = $mk();
$q = 'what is a calm place to relax for a weekend cachetest';
$r1 = $a->reply($q, [], 'guest', '', 'en'); $r2 = $a->reply($q, [], 'guest', '', 'en');
t('guest, identical input twice: the model is called once and the same reply is served', $sent === 1 && $r1['reply'] === $r2['reply'], "calls=$sent");
$a->reply($q, [['role' => 'user', 'content' => 'hello'], ['role' => 'assistant', 'content' => 'hi']], 'guest', '', 'en');
t('different history -> different input -> NOT served from cache', $sent === 2);
$a->reply($q, [], 'guest', 'on page package-details.php?pkgid=1', 'en');
t('different page context -> NOT served from cache', $sent === 3);
$uem = (string)$dbh->query('SELECT EmailId FROM tblusers LIMIT 1')->fetchColumn();
if ($uem !== '') { $a->reply($q, [], $uem, '', 'en'); $a->reply($q, [], $uem, '', 'en'); t('signed-in visitor: never cached (personal prompt)', $sent === 5, "calls=$sent"); }
$a->reply($q, [], 'guest', '', 'bn');
t('different language -> NOT served from cache', $sent === ($uem !== '' ? 6 : 4));
t('cached turn still returns the code-built + model chips and verified sources fields', array_key_exists('suggestions', $r2) && array_key_exists('sources', $r2));
$dbh->exec("DELETE FROM tblcache WHERE cache_key LIKE 'ai_chat_%'");

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
