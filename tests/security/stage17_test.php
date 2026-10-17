<?php
/**
 * Stage 17 regression: CMS HTML sanitiser, password rules, session revocation after a password change, idle/absolute session timeout,
 * encrypted 2FA secrets, admin-role fail-closed, APP_DEBUG default. Temporary rows (…@s17-test.example.test, page type s17test) are removed.
 *     php tests/security/stage17_test.php [base-url]     (site served, default :8000)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/HtmlSanitizer.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 92) . $d . "\n"; }

// ── 1. HTML sanitiser ──
$bad = [
    '<script>alert(1)</script>ok'                       => ['<script', 'alert(1)'],
    '<img src=x onerror=alert(1)>'                      => ['onerror'],
    '<a href="javascript:alert(1)">x</a>'               => ['javascript:'],
    '<a href="JaVa&#09;script:alert(1)">x</a>'          => ['script:alert'],
    '<a href="&#106;avascript:alert(1)">x</a>'          => ['avascript:alert'],
    '<iframe src="https://evil.test"></iframe>'         => ['<iframe', 'evil.test'],
    '<svg onload=alert(1)><circle/></svg>'              => ['<svg', 'onload'],
    '<p style="background:url(javascript:x)">t</p>'     => ['style=', 'javascript'],
    '<div onclick="x()">t</div>'                        => ['onclick'],
    '<style>body{display:none}</style>t'                => ['<style', 'display:none'],
    '<form action="//evil"><input name=p></form>'       => ['<form', 'evil'],
    '<img src="data:text/html;base64,PHNjcmlwdD4=">'    => ['data:'],
    '<object data="x.swf"></object><embed src="x">'     => ['<object', '<embed'],
    '<!-- <script>alert(1)</script> -->t'               => ['<script', '<!--'],
    '<a href="https://ok.test" target="_top">x</a>'     => ['_top'],
];
foreach ($bad as $in => $mustNot) {
    $out = HtmlSanitizer::clean($in); $hit = null;
    foreach ($mustNot as $m) if (stripos($out, $m) !== false) { $hit = $m; break; }
    t('sanitiser removes: ' . substr($in, 0, 60), $hit === null, $hit ? "left '$hit' in: $out" : '');
}
$good = HtmlSanitizer::clean('<h2>Terms</h2><p>Hello <strong>world</strong> and <em>you</em>.</p><ul><li>One</li></ul><a href="https://example.com/x?a=1&b=2">link</a> <a href="/page.php">rel</a> <a href="mailto:a@b.test">m</a><img src="https://example.com/i.jpg" alt="pic"><table><tr><td colspan="2">c</td></tr></table>');
t('sanitiser keeps headings, paragraphs, lists, tables and formatting', str_contains($good, '<h2>Terms</h2>') && str_contains($good, '<strong>world</strong>') && str_contains($good, '<li>One</li>') && str_contains($good, 'colspan="2"'));
t('sanitiser keeps safe links and images and adds rel=noopener', str_contains($good, 'href="https://example.com/x?a=1&amp;b=2"') && str_contains($good, 'href="/page.php"') && str_contains($good, 'mailto:a@b.test') && str_contains($good, '<img src="https://example.com/i.jpg"') && substr_count($good, 'noopener') === 3);
t('sanitiser: plain text with < and > is not mangled beyond HTML rules', str_contains(HtmlSanitizer::clean('Price &lt; 500 &amp; more'), 'Price &lt; 500 &amp; more'));
t('sanitiser: empty and whitespace input', HtmlSanitizer::clean('') === '' && HtmlSanitizer::clean("  \n") === '');
t('sanitiser: unknown tags are unwrapped, their text kept', HtmlSanitizer::clean('<custom>keep me</custom>') === 'keep me');

// ── 2. password rules ──
t('password: 7 characters refused, 8 accepted, 201 refused', tms_password_error('abcdefg') !== null && tms_password_error('abcdefgh') === null && tms_password_error(str_repeat('a', 201)) !== null);

// ── 3. 2FA secret encryption ──
putenv('TMS_2FA_KEY=0123456789abcdef-test-key'); $_ENV['TMS_2FA_KEY'] = '0123456789abcdef-test-key';
$sec = tms_totp_generate_secret(); $sealed = tms_2fa_seal($sec);
t('2FA: with a key set the stored value is encrypted (enc1:) and hides the secret', str_starts_with($sealed, 'enc1:') && !str_contains($sealed, $sec));
t('2FA: sealing twice gives different ciphertext (random IV) and open() returns the secret', tms_2fa_seal($sec) !== $sealed && tms_2fa_open($sealed) === $sec);
$code = tms_totp_code_at($sec, time());
t('2FA: a valid code verifies against the ENCRYPTED stored value', tms_totp_verify($sealed, $code) === true);
t('2FA: a wrong code does not verify', tms_totp_verify($sealed, $code === '000000' ? '111111' : '000000') === false);
t('2FA: legacy plaintext secrets still verify (no forced migration)', tms_totp_verify($sec, $code) === true);
$tam = $sealed; $tam[strlen($tam) - 3] = $tam[strlen($tam) - 3] === 'A' ? 'B' : 'A';
t('2FA: a tampered ciphertext is rejected, not decrypted to garbage', tms_2fa_open($tam) === '' && tms_totp_verify($tam, $code) === false);
putenv('TMS_2FA_KEY'); unset($_ENV['TMS_2FA_KEY']);
t('2FA: without the key an encrypted value cannot be opened (verification fails closed)', tms_2fa_open($sealed) === '' && tms_totp_verify($sealed, $code) === false);
t('2FA: without a key, sealing leaves the secret as before (feature is opt-in)', tms_2fa_seal($sec) === $sec);

// ── 4. admin role fails closed for a deleted admin ──
$_SESSION['alogin'] = 'no-such-admin-' . bin2hex(random_bytes(3));
t('admin role: a session whose admin row is gone gets no role and passes no gate', tms_admin_role($dbh) === '' && !tms_admin_can($dbh, ['superadmin']));
unset($_SESSION['alogin']);

// ── 5. idle / absolute timeout (unit, simulated clock) ──
$now = time();
$_SESSION = ['login' => 'x@s17-test.example.test', 'auth_at' => $now - 100, 'last_seen' => $now - 100, 'pw_checked' => $now];
t('timeout: an active customer session is kept and last_seen refreshed', tms_session_guard($now, true) === false && ($_SESSION['login'] ?? '') !== '' && $_SESSION['last_seen'] === $now);
$_SESSION = ['login' => 'x@s17-test.example.test', 'auth_at' => $now - 100, 'last_seen' => $now - 121 * 60, 'pw_checked' => $now];
t('timeout: 121 minutes idle ends a customer session', tms_session_guard($now, true) === true && empty($_SESSION['login']));
$_SESSION = ['alogin' => 'admin', 'auth_at' => $now - 100, 'last_seen' => $now - 31 * 60];
t('timeout: 31 minutes idle ends an admin session (30-minute rule)', tms_session_guard($now, true) === true && empty($_SESSION['alogin']));
$_SESSION = ['login' => 'x@s17-test.example.test', 'auth_at' => $now - 25 * 3600, 'last_seen' => $now - 5, 'pw_checked' => $now];
t('timeout: a session older than 24 hours ends even if active', tms_session_guard($now, true) === true && empty($_SESSION['login']));
$_SESSION = [];
t('timeout: no session, no effect', tms_session_guard($now, true) === false);

// ── HTTP section ──
$PW = 'S17-test-pass-1!'; $E = 'a@s17-test.example.test';
$clean = function () use ($dbh) {
    try { $dbh->exec("DELETE FROM tblapi_refresh_tokens WHERE ApiTokenId IN (SELECT id FROM tblapi_tokens WHERE UserEmail LIKE '%@s17-test.example.test')"); } catch (Throwable $e) {}
    try { $dbh->exec("DELETE FROM tblapi_tokens WHERE UserEmail LIKE '%@s17-test.example.test'"); } catch (Throwable $e) {}
    $dbh->exec("DELETE FROM tbl_password_resets WHERE UserEmail LIKE '%@s17-test.example.test'");
    $dbh->exec("DELETE FROM tbl_login_attempts WHERE Email LIKE '%s17-test.example.test%'");
    $dbh->exec("DELETE FROM tblpages WHERE type='s17test'");
    $dbh->exec("DELETE FROM tblusers WHERE EmailId LIKE '%@s17-test.example.test'");
};
$clean();
$dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)')->execute(['A S17', '01700000000', $E, password_hash($PW, PASSWORD_BCRYPT)]);
$mkjar = function () use ($base, $E, $PW, &$jars) {
    $jar = tempnam(sys_get_temp_dir(), 's17'); $jars[] = $jar;
    $c = function ($u, $p = null) use ($jar) { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40]); if ($p !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($p)); } $b = (string)curl_exec($ch); curl_close($ch); return $b; };
    preg_match('/name="csrf_token" value="([^"]+)"/', $c("$base/login.php"), $m);
    $c("$base/login.php", ['EmailId' => $E, 'Password' => $PW, 'csrf_token' => $m[1] ?? '']);
    return $c;
};
// 6. CMS page over HTTP
$dbh->prepare("INSERT INTO tblpages (type, detail) VALUES ('s17test', ?)")->execute(['<h2>Safe heading</h2><script>window.pwned=1</script><img src=x onerror="alert(1)"><a href="javascript:alert(2)">bad</a><p>Body <b>text</b></p>']);
$ch = curl_init("$base/page.php?type=s17test"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]); $pg = (string)curl_exec($ch); curl_close($ch);
t('CMS page over HTTP: formatting kept, script / onerror / javascript: URL gone', str_contains($pg, '<h2>Safe heading</h2>') && str_contains($pg, '<b>text</b>') && !str_contains($pg, 'window.pwned') && !str_contains($pg, 'onerror="alert(1)"') && !str_contains($pg, 'javascript:alert(2)'));

// 7. password change ends other sessions and API tokens
$c1 = $mkjar(); sleep(1); $c2 = $mkjar();
$probe = fn($c) => str_contains($c("$base/change-password.php"), 'name="newpassword"');
t('sessions: both browsers are signed in before the change', $probe($c1) && $probe($c2));
$dbh->prepare("INSERT INTO tblapi_tokens (UserEmail, TokenHash, DeviceLabel, ExpiresAt, Scopes) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 1 DAY),?)")->execute([$E, hash('sha256', 's17tok'), 'test', 'read']);
sleep(1);
preg_match('/name="csrf_token" value="([^"]+)"/', $c1("$base/change-password.php"), $m);
$c1("$base/change-password.php", ['submit5' => '1', 'csrf_token' => $m[1] ?? '', 'password' => $PW, 'newpassword' => 'short7!', 'confirmpassword' => 'short7!']);
t('change password: a 7-character new password is refused (hash unchanged)', password_verify($PW, (string)$dbh->query("SELECT Password FROM tblusers WHERE EmailId='$E'")->fetchColumn()));
$NEW = 'S17-new-pass-2!';
$c1("$base/change-password.php", ['submit5' => '1', 'csrf_token' => $m[1] ?? '', 'password' => $PW, 'newpassword' => $NEW, 'confirmpassword' => $NEW]);
t('change password: an 8+ character password is accepted', password_verify($NEW, (string)$dbh->query("SELECT Password FROM tblusers WHERE EmailId='$E'")->fetchColumn()));
sleep(6);   // the check runs at most every 5 seconds per session
t('sessions: the browser that changed the password stays signed in', $probe($c1));
t('sessions: the OTHER browser is signed out by the password change', !$probe($c2));
t('sessions: API tokens of that customer were revoked', $dbh->query("SELECT COUNT(*) FROM tblapi_tokens WHERE UserEmail='$E'")->fetchColumn() == 0);

foreach ($jars ?? [] as $j) @unlink($j);
$clean();
t('temporary rows cleaned up', (int)$dbh->query("SELECT COUNT(*) FROM tblusers WHERE EmailId LIKE '%@s17-test.example.test'")->fetchColumn() === 0 && (int)$dbh->query("SELECT COUNT(*) FROM tblpages WHERE type='s17test'")->fetchColumn() === 0);
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
