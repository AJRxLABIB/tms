<?php
/**
 * Stage 10 regression: cross-user AI leaks, open redirect / reset-link host, unpublished packages, API 2FA, API booking guards,
 * integer-exact coupon math, cart add-on ownership, login-attempt clearing. Temporary rows (…@s10-test.example.test, packages
 * 9201-9203) are created and removed.     php tests/security/stage10_test.php [base-url]   (needs the site served, default :8000)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/TripCopilot.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 88) . $d . "\n"; }
function http(string $url, array $post = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $c, 'body' => $b, 'json' => json_decode($b, true)];
}
$A = 'a@s10-test.example.test'; $B = 'b@s10-test.example.test'; $T = 'tfa@s10-test.example.test'; $PW = 'S10-test-pass-1!';
$clean = function () use ($dbh) {
    $dbh->exec("DELETE FROM tbl_copilot_sessions WHERE user_email LIKE '%@s10-test.example.test'");
    $dbh->exec("DELETE FROM tblbooking WHERE UserEmail LIKE '%@s10-test.example.test'");
    $dbh->exec("DELETE FROM tblcart WHERE UserEmail LIKE '%@s10-test.example.test'");
    $dbh->exec("DELETE FROM tblcoupons WHERE UserEmail LIKE '%@s10-test.example.test' OR Code LIKE 'S10T%'");
    $dbh->exec("DELETE FROM tbl_login_attempts WHERE Email LIKE '%s10-test.example.test%'");
    try { $dbh->exec("DELETE FROM tblapi_tokens WHERE UserEmail LIKE '%@s10-test.example.test'"); } catch (Throwable $e) {}
    $dbh->exec("DELETE FROM tbl_supplier_blackouts WHERE PackageId BETWEEN 9201 AND 9203");
    try { $dbh->exec("DELETE FROM tblcartaddons WHERE AddOnId IN (SELECT AddOnId FROM tblpackageaddons WHERE PackageId=9201)"); $dbh->exec("DELETE FROM tblpackageaddons WHERE PackageId=9201"); } catch (Throwable $e) {}
    $dbh->exec("DELETE FROM tbltourpackages WHERE PackageId BETWEEN 9201 AND 9203");
    foreach (['tblloyalty_log', 'tblloyalty'] as $lt) { try { $dbh->exec("DELETE FROM $lt WHERE UserEmail LIKE '%@s10-test.example.test'"); } catch (Throwable $e) {} }
    $dbh->exec("DELETE FROM tblusers WHERE EmailId LIKE '%@s10-test.example.test'");
};
$clean();
$h = password_hash($PW, PASSWORD_BCRYPT);
$insU = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password, TwoFAEnabled, TwoFASecret) VALUES (?,?,?,?,?,?)');
$secret = tms_totp_generate_secret();
$insU->execute(['A S10', '01711111111', $A, $h, 0, null]); $insU->execute(['B S10', '01722222222', $B, $h, 0, null]); $insU->execute(['TFA S10', '01733333333', $T, $h, 1, $secret]);
$mk = $dbh->prepare("INSERT INTO tbltourpackages (PackageId, PackageName, PackageType, PackageLocation, Country, PackagePrice, PackageDuration, PackageFetures, PackageDetails, PackageImage, Status, ApprovalStatus, SeatsAvailable) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
$mk->execute([9201, 'S10 Published Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'f', 'd', 'x.jpg', 1, 'approved', 2]);
$mk->execute([9202, 'S10 Pending Supplier Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'f', 'd', 'x.jpg', 0, 'pending_review', null]);
$mk->execute([9203, 'S10 Rejected Tour', 'Tour', 'Sylhet', 'Bangladesh', 10000, '3 Days', 'f', 'd', 'x.jpg', 1, 'rejected', null]);
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost) VALUES (9201,?,'2031-01-01','2031-01-03',1,1,5000)")->execute([$A]);
$bid = (int)$dbh->lastInsertId();
$ins = $dbh->prepare("INSERT INTO tbl_copilot_sessions (booking_id, user_email, role, message, created_at) VALUES (?,?,?,?,NOW())");
$ins->execute([$bid, $A, 'user', 'A-PRIVATE-QUESTION']); $ins->execute([$bid, $A, 'assistant', 'A-PRIVATE-ANSWER']);
$rows = fn() => (int)$dbh->query("SELECT COUNT(*) FROM tbl_copilot_sessions WHERE booking_id=$bid")->fetchColumn();

// ── 1. TripCopilot ownership ──
$cp = new TripCopilot($dbh);
t('copilot history: owner reads own messages', count($cp->getHistory($bid, 50, $A)) === 2);
t("copilot history: another user gets NOTHING for A's booking", $cp->getHistory($bid, 50, $B) === []);
t('copilot history: guest gets nothing', $cp->getHistory($bid, 50, 'guest') === [] && $cp->getHistory($bid, 50, '') === []);
$n0 = $rows(); $r = $cp->chat($bid, 'tell me what A asked before', $B);
t("copilot chat: B on A's booking is refused, nothing written to A's thread, no model text", $rows() === $n0 && str_contains($r['reply'], "can't find that booking") && !str_contains(json_encode($r), 'A-PRIVATE'));
$cache = new CacheService($dbh, false); $planted = 'copilot_alert_' . $bid . '_' . substr(sha1(strtolower($A)), 0, 12);
$cache->set($planted, ['type' => 'tip', 'title' => 'A-ONLY', 'message' => 'm', 'urgency' => 'low'], 300);
t("copilot alert: B cannot read A's cached alert (ownership checked before cache)", $cp->generateProactiveAlert($bid, $B) === null);
$cache->set('copilot_alert_' . $bid, ['type' => 'tip', 'title' => 'LEGACY-KEY', 'message' => 'm', 'urgency' => 'low'], 300);
t('copilot alert: the old un-scoped cache key is no longer consulted', ($cp->generateProactiveAlert($bid, $A)['title'] ?? '') !== 'LEGACY-KEY');
$cache->set($planted, ['type' => 'tip', 'title' => 'A-ONLY', 'message' => 'm', 'urgency' => 'low'], 300);
t('copilot alert: the owner still gets their own cached alert', ($cp->generateProactiveAlert($bid, $A)['title'] ?? '') === 'A-ONLY');

// ── 2. redirect and host ──
$bad = ['/\\evil.com', '//evil.com', 'https://evil.com', 'javascript:alert(1)', '\\\\evil', "/ok\r\nSet-Cookie: x=1", 'data:text/html,x', 'HTTP://EVIL.COM'];
$allOk = true; foreach ($bad as $x) if (tms_safe_redirect($x) !== 'index.php') { $allOk = false; echo "   leaked: " . json_encode($x) . "\n"; }
t('safe redirect: backslash / protocol-relative / absolute / scheme / CRLF all fall back', $allOk);
t('safe redirect: normal relative targets keep working', tms_safe_redirect('/tms/package-list.php?type=Beach&page=2') === '/tms/package-list.php?type=Beach&page=2' && tms_safe_redirect('dashboard.php#top') === 'dashboard.php#top');
$_SERVER['HTTP_HOST'] = 'evil.com/x'; $_SERVER['SCRIPT_NAME'] = '/tms/frontend/forgot-password.php';
t('public base URL: a malformed Host header is replaced, not echoed', !str_contains(tms_public_base_url(), 'evil.com'));
$_SERVER['HTTP_HOST'] = 'shop.example.test';
t('public base URL: a valid Host is accepted when APP_URL is not configured', str_contains(tms_public_base_url(), 'shop.example.test') || (string)tms_env('APP_URL', '') !== '');
$r = http("$base/language.php?lang=en&redirect=" . urlencode('/\\evil.com'));
$ch = curl_init("$base/language.php?lang=en&redirect=" . urlencode('/\\evil.com')); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]); $raw = (string)curl_exec($ch); curl_close($ch);
preg_match('/^Location:\s*(\S+)/mi', $raw, $m);
t('HTTP: language.php with a backslash redirect lands on index.php', ($m[1] ?? '') === 'index.php');

// ── 3. unpublished / unapproved packages ──
$list = http("$base/package-list.php?search=S10")['body'];
t('package list shows the published test package', str_contains($list, 'S10 Published Tour'));
t('package list hides pending-review and rejected supplier packages', !str_contains($list, 'S10 Pending Supplier Tour') && !str_contains($list, 'S10 Rejected Tour'));
t('package page: published opens', str_contains(http("$base/package-details.php?pkgid=9201")['body'], 'S10 Published Tour'));
t('package page: pending and rejected do not render', !str_contains(http("$base/package-details.php?pkgid=9202")['body'], 'S10 Pending Supplier Tour') && !str_contains(http("$base/package-details.php?pkgid=9203")['body'], 'S10 Rejected Tour'));
t('sitemap omits unpublished packages', !str_contains(http("$base/sitemap.php")['body'], 'pkgid=9202') && str_contains(http("$base/sitemap.php")['body'], 'pkgid=9201'));
t('tms_package_bookable matches (published yes, pending no, rejected no, missing no)', tms_package_bookable($dbh, 9201) && !tms_package_bookable($dbh, 9202) && !tms_package_bookable($dbh, 9203) && !tms_package_bookable($dbh, 987654));
$dest = http("$base/destinations.php?search=S10")['body'];
t('destinations hides unpublished packages', !str_contains($dest, 'S10 Pending Supplier Tour') && !str_contains($dest, 'S10 Rejected Tour'));

// ── 4. REST API ──
$apiBase = preg_replace('#/(frontend)?$#', '', $base) . '/api/index.php';
$lg = fn($e, $extra = []) => http("$apiBase?action=login", ['email' => $e, 'password' => $PW] + $extra);
$r = $lg($A); $tokA = $r['json']['data']['token'] ?? '';
t('API login: normal account still gets a token', $r['code'] === 200 && $tokA !== '', "code={$r['code']}");
$r = $lg($T);
t('API login: 2FA account with password only gets NO token', $r['code'] === 401 && empty($r['json']['data']['token']) && str_contains(json_encode($r['json']), 'Two-factor'));
$r = $lg($T, ['totp_code' => '000000']);
t('API login: wrong TOTP code is rejected', $r['code'] === 401 && empty($r['json']['data']['token']));
$r = $lg($T, ['totp_code' => tms_totp_code_at($secret, time())]);
t('API login: correct TOTP code gets a token', $r['code'] === 200 && !empty($r['json']['data']['token']));
$dbh->exec("DELETE FROM tbl_login_attempts WHERE Email LIKE '%s10-test.example.test%'");
for ($i = 0; $i < 6; $i++) $lg($T, ['totp_code' => '111111']);
$r = $lg($T, ['totp_code' => tms_totp_code_at($secret, time())]);
t('API login: repeated bad TOTP codes lock the 2FA bucket (even a right code is refused)', $r['code'] === 429);
$dbh->exec("DELETE FROM tbl_login_attempts WHERE Email LIKE '%s10-test.example.test%'");

$d1 = date('Y-m-d', strtotime('+30 days')); $d2 = date('Y-m-d', strtotime('+33 days'));
$book = fn($pid, $extra = []) => http("$apiBase?action=book", array_merge(['package_id' => $pid, 'from_date' => $d1, 'to_date' => $d2, 'persons' => 1], $extra), ["Authorization: Bearer $tokA"]);
$seatsUsed = fn() => (int)$dbh->query("SELECT COALESCE(SUM(NoOfPersons),0) FROM tblbooking WHERE PackageId=9201 AND status IN (0,1)")->fetchColumn();
$s0 = $seatsUsed();  // 1 seat (the fixture booking) of capacity 2
t('API book: pending-review package cannot be booked', $book(9202)['code'] === 404);
t('API book: rejected package cannot be booked', $book(9203)['code'] === 404);
$r = $book(9201, ['persons' => 2]);
t('API book: more persons than seats left (1 of 2 left) is refused with 409 and books nothing', $r['code'] === 409 && $seatsUsed() === $s0, "code={$r['code']}");
$dbh->prepare("INSERT INTO tbl_supplier_blackouts (SupplierId, PackageId, DateFrom, DateTo, Reason) VALUES (0,9201,?,?, 't')")->execute([$d1, $d2]);
t('API book: supplier blackout dates are refused', $book(9201)['code'] === 409 && $seatsUsed() === $s0);
$dbh->exec("DELETE FROM tbl_supplier_blackouts WHERE PackageId=9201");
$unit = (int)tms_dynamic_price($dbh, 9201, 10000.0, $d1)['final']; // price is demand/scarcity based: read it right before each booking
$r = $book(9201);
t('API book: last seat books at the date-specific dynamic price (same as the website)', $r['code'] === 201 && ($r['json']['data']['total_cost'] ?? -1) === $unit, "code={$r['code']} total=" . ($r['json']['data']['total_cost'] ?? '?') . " expected=$unit");
t('API book: now sold out, the next request is refused', $book(9201)['code'] === 409 && $seatsUsed() === $s0 + 1);
// coupons
$dbh->prepare("INSERT INTO tblcoupons (Code, DiscountPercent, UserEmail, IsUsed, ExpiryDate) VALUES ('S10TGLOBAL', 10, NULL, 0, NULL)")->execute();
$dbh->prepare("INSERT INTO tblcoupons (Code, DiscountPercent, UserEmail, IsUsed, ExpiryDate) VALUES ('S10TBIG', 100, ?, 0, NULL)")->execute([$A]);
$gid = (int)$dbh->query("SELECT id FROM tblcoupons WHERE Code='S10TGLOBAL'")->fetchColumn();
$r = http("$apiBase?action=book", ['package_id' => 9201, 'from_date' => $d1, 'to_date' => $d2, 'persons' => 1, 'coupon_code' => 'S10TGLOBAL'], ["Authorization: Bearer $tokA"]);
t('API book: a global coupon is NOT consumed by one booking (and NULL expiry is accepted)', (int)$dbh->query("SELECT IsUsed FROM tblcoupons WHERE id=$gid")->fetchColumn() === 0);
$dbh->exec("UPDATE tbltourpackages SET SeatsAvailable=NULL WHERE PackageId=9201");
$unit = (int)tms_dynamic_price($dbh, 9201, 10000.0, $d1)['final'];
$r = http("$apiBase?action=book", ['package_id' => 9201, 'from_date' => $d1, 'to_date' => $d2, 'persons' => 1, 'coupon_code' => 'S10TGLOBAL'], ["Authorization: Bearer $tokA"]);
$exp = $unit - tms_coupon_discount($unit, 10.0);
t('API book: 10% global coupon applied with integer-exact math', $r['code'] === 201 && ($r['json']['data']['total_cost'] ?? -1) === $exp, "total=" . ($r['json']['data']['total_cost'] ?? '?') . " expected=$exp");
$bigId = (int)$dbh->query("SELECT id FROM tblcoupons WHERE Code='S10TBIG'")->fetchColumn();
$unit = (int)tms_dynamic_price($dbh, 9201, 10000.0, $d1)['final'];
$r = http("$apiBase?action=book", ['package_id' => 9201, 'from_date' => $d1, 'to_date' => $d2, 'persons' => 1, 'coupon_code' => 'S10TBIG'], ["Authorization: Bearer $tokA"]);
$capPct = (int)TMS_MAX_LOYALTY_DISCOUNT;
t("API book: a 100% coupon is clamped to the {$capPct}% cap (never a free booking)", $r['code'] === 201 && ($r['json']['data']['total_cost'] ?? 0) === $unit - intdiv($unit * $capPct + 50, 100) && ($r['json']['data']['total_cost'] ?? 0) > 0, "total=" . ($r['json']['data']['total_cost'] ?? '?'));
$pk = $http = http("$apiBase?action=packages&search=S10");
$names = json_encode($pk['json']);
t('API packages: lists the published package only', str_contains($names, 'S10 Published Tour') && !str_contains($names, 'S10 Pending') && !str_contains($names, 'S10 Rejected'));
t('API package detail: pending package is 404', http("$apiBase?action=package&id=9202")['code'] === 404);

// ── 5. coupon math ──
t('coupon math: 13,665 at 10% = 1,367 (half-up), total 12,298 - subtotal minus discount is exact', tms_coupon_discount(13665, 10.0) === 1367 && 13665 - tms_coupon_discount(13665, 10.0) === 12298);
t('coupon math: percent clamp, zero and negative, never above subtotal', tms_coupon_discount(1000, 500.0) <= (int)(1000 * TMS_MAX_LOYALTY_DISCOUNT / 100) + 1 && tms_coupon_discount(1000, -5.0) === 0 && tms_coupon_discount(0, 10.0) === 0);
t('coupon math: fractional percent 12.5% of 8,001 = 1,000 (1000.125 rounds down)', tms_coupon_discount(8001, 12.5) === 1000);

// ── 6. login-attempt clearing ──
$ip = '203.0.113.9'; tms_record_failed_login($dbh, '2fa-user:' . $B, $ip); tms_record_failed_login($dbh, $B, $ip);
tms_clear_login_attempts($dbh, $B, $ip);
t('clearing a password lockout keeps the 2FA failure rows (no TOTP guess-counter reset)', (int)$dbh->query("SELECT COUNT(*) FROM tbl_login_attempts WHERE Email='2fa-user:$B' AND IPAddress='$ip'")->fetchColumn() === 1 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_login_attempts WHERE Email='$B'")->fetchColumn() === 0);

// ── 7. cart add-on ownership ──
$dbh->prepare("INSERT INTO tblcart (UserEmail, PackageId, FromDate, ToDate, NoOfPersons) VALUES (?,9201,?,?,1)")->execute([$A, $d1, $d2]); $cid = (int)$dbh->lastInsertId();
$dbh->exec("INSERT INTO tblpackageaddons (PackageId, Name, Price, IsActive) VALUES (9201, 'S10 test add-on', 100, 1)"); $ao = (int)$dbh->lastInsertId();
if ($ao) {
    $dbh->prepare("INSERT INTO tblcartaddons (CartId, AddOnId, Quantity) VALUES (?,?,1)")->execute([$cid, $ao]);
    // B signs in over HTTP and tries to remove A's cart row
    $jar = tempnam(sys_get_temp_dir(), 's10'); $cu = function ($u, $p = null) use ($jar) { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]); if ($p) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); } $b = (string)curl_exec($ch); curl_close($ch); return $b; };
    $pg = $cu("$base/login.php"); preg_match('/name="csrf_token" value="([^"]+)"/', $pg, $m);
    $cu("$base/login.php", ['EmailId' => $B, 'Password' => $PW, 'csrf_token' => $m[1] ?? '']);
    $cu("$base/cart.php?remove=$cid");
    t("cart: B cannot delete A's cart row or its add-ons by id", (int)$dbh->query("SELECT COUNT(*) FROM tblcart WHERE id=$cid")->fetchColumn() === 1 && (int)$dbh->query("SELECT COUNT(*) FROM tblcartaddons WHERE CartId=$cid")->fetchColumn() === 1);
    @unlink($jar);
} else { echo "SKIP cart add-on ownership: no add-on row in this database\n"; }

// ── 8. loyalty redeem: cap, exact deduction, parallel race ──
$jars = []; $sess = function () use ($base, $B, $PW, &$jars) {
    $jar = tempnam(sys_get_temp_dir(), 's10l'); $jars[] = $jar;
    $c = fn($u, $p = null) => (function () use ($u, $p, $jar) { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true]); if ($p) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); } $b = (string)curl_exec($ch); curl_close($ch); return $b; })();
    preg_match('/name="csrf_token" value="([^"]+)"/', $c("$base/login.php"), $m);
    $c("$base/login.php", ['EmailId' => $B, 'Password' => $PW, 'csrf_token' => $m[1] ?? '']);
    preg_match('/name="csrf_token" value="([^"]+)"/', $c("$base/loyalty.php"), $m2);
    return [$jar, $m2[1] ?? ''];
};
[$jar1, $tok1] = $sess();
$setPts = fn($n) => $dbh->prepare("INSERT INTO tblloyalty(UserEmail,Points,TotalEarned) VALUES(?,?,?) ON DUPLICATE KEY UPDATE Points=VALUES(Points)")->execute([$B, $n, $n]);
$pts = fn() => (int)$dbh->query("SELECT Points FROM tblloyalty WHERE UserEmail='$B'")->fetchColumn();
$cps = fn() => (int)$dbh->query("SELECT COUNT(*) FROM tblcoupons WHERE UserEmail='$B'")->fetchColumn();
$post = function ($jar, $tok) use ($base) { $ch = curl_init("$base/loyalty.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['redeem' => '1', 'csrf_token' => $tok])]); return $ch; };
$setPts(5000);
$ch = $post($jar1, $tok1); curl_exec($ch); curl_close($ch);
$capUsed = (int)(ceil(min(TMS_MAX_LOYALTY_DISCOUNT, intdiv(5000, 500) * 10) / 10) * 500);
$dc = (int)$dbh->query("SELECT DiscountPercent FROM tblcoupons WHERE UserEmail='$B' ORDER BY id DESC LIMIT 1")->fetchColumn();
t("loyalty: 5,000 points buys the capped coupon and costs only $capUsed points (rest is kept)", $dc === (int)TMS_MAX_LOYALTY_DISCOUNT && $pts() === 5000 - $capUsed, "coupon={$dc}% points={$pts()}");
t('loyalty: the redemption is written to the history as a negative entry', (int)$dbh->query("SELECT COUNT(*) FROM tblloyalty_log WHERE UserEmail='$B' AND Points=-$capUsed")->fetchColumn() === 1);
$ch = $post($jar1, ''); curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
t('loyalty: redeem without a CSRF token changes nothing', $pts() === 5000 - $capUsed && $cps() === 1, "code=$code");
$dbh->exec("DELETE FROM tblcoupons WHERE UserEmail='$B'"); $setPts(500);
[$jar2, $tok2] = $sess();
$mh = curl_multi_init(); $hs = []; for ($i = 0; $i < 6; $i++) { $h2 = $post($i % 2 ? $jar1 : $jar2, $i % 2 ? $tok1 : $tok2); curl_multi_add_handle($mh, $h2); $hs[] = $h2; }
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
foreach ($hs as $h2) curl_multi_remove_handle($mh, $h2); curl_multi_close($mh);
t('loyalty RACE: 6 parallel redeems with 500 points -> exactly ONE coupon, balance 0 (never negative)', $cps() === 1 && $pts() === 0, "coupons={$cps()} points={$pts()}");
foreach ($jars as $j) @unlink($j);

$clean();
t('temporary rows cleaned up', (int)$dbh->query("SELECT COUNT(*) FROM tblusers WHERE EmailId LIKE '%@s10-test.example.test'")->fetchColumn() === 0 && (int)$dbh->query("SELECT COUNT(*) FROM tbltourpackages WHERE PackageId BETWEEN 9201 AND 9203")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
