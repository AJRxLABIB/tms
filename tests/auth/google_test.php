<?php
/**
 * Google sign-in (backend/auth/GoogleAuth.php). Google itself is NOT contacted: a locally generated RSA key pair plays Google's
 * signing key and a scripted transport plays the token/JWKS endpoints. This proves OUR verification and account logic; it does not
 * prove that a real Google Cloud project is configured correctly (see GOOGLE_LOGIN.md).
 *   php tests/auth/google_test.php [enabled-base-url [disabled-base-url]]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
ob_start(); // keep headers un-sent so session_regenerate_id() works under the CLI
putenv('GOOGLE_CLIENT_ID=test-client.apps.googleusercontent.com'); putenv('GOOGLE_CLIENT_SECRET=test-secret'); putenv('GOOGLE_REDIRECT_URI=https://tms.example.test/google-callback.php');
require 'backend/config.php';
require_once 'backend/auth/GoogleAuth.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 92) . $d . "\n"; }
function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function mkKey(): array { $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); $d = openssl_pkey_get_details($k)['rsa']; return [$k, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => b64u($d['n']), 'e' => b64u($d['e'])]]; }
function jwt(array $claims, $key, string $kid = 'k1', string $alg = 'RS256'): string {
    $h = b64u(json_encode(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT'])); $p = b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256); return "$h.$p." . b64u($sig);
}
[$key, $pub] = mkKey(); $pub['kid'] = 'k1'; $jwks = ['keys' => [$pub]];
[$key2, $pub2] = mkKey(); // an attacker's key
$CID = GoogleAuth::clientId(); $NOW = time(); $NONCE = 'nonce-123';
$ok = ['iss' => 'https://accounts.google.com', 'aud' => $CID, 'azp' => $CID, 'sub' => '1100001', 'email' => 'Tester.G@Example.test', 'email_verified' => true, 'name' => 'Test Er', 'iat' => $NOW, 'exp' => $NOW + 3600, 'nonce' => $NONCE];
$v = fn(array $over, $k = null, string $kid = 'k1', string $alg = 'RS256', array $drop = []) => GoogleAuth::verifyIdToken(jwt(array_diff_key($over + $ok, array_flip($drop)), $k ?? $key, $kid, $alg), $jwks, $CID, $NONCE, $NOW);

// ── token verification ────────────────────────────────────────────────────────
$r = $v([]);
t('valid token accepted; e-mail lower-cased; sub and name returned', $r['ok'] && $r['claims'] === ['sub' => '1100001', 'email' => 'tester.g@example.test', 'name' => 'Test Er']);
t('"accounts.google.com" (no scheme) issuer also accepted', $v(['iss' => 'accounts.google.com'])['ok']);
t('email_verified given as string "true" accepted', $v(['email_verified' => 'true'])['ok']);
t('signature from a different key is rejected', ($x = $v([], $key2))['ok'] === false && $x['error'] === 'bad_signature');
$tok = jwt($ok, $key); $parts = explode('.', $tok); $parts[1] = b64u(json_encode(['email' => 'victim@example.test'] + $ok));
t('payload edited after signing is rejected', GoogleAuth::verifyIdToken(implode('.', $parts), $jwks, $CID, $NONCE, $NOW)['error'] === 'bad_signature');
$none = b64u(json_encode(['alg' => 'none', 'kid' => 'k1'])) . '.' . b64u(json_encode($ok)) . '.';
t('alg "none" is rejected', GoogleAuth::verifyIdToken($none, $jwks, $CID, $NONCE, $NOW)['error'] === 'bad_alg' || GoogleAuth::verifyIdToken($none, $jwks, $CID, $NONCE, $NOW)['error'] === 'malformed');
$pem = GoogleAuth::jwkToPem($pub); $h = b64u(json_encode(['alg' => 'HS256', 'kid' => 'k1'])); $pl = b64u(json_encode($ok));
$hs = "$h.$pl." . b64u(hash_hmac('sha256', "$h.$pl", $pem, true));
t('HS256 token signed with the PUBLIC key as the secret (key-confusion attack) is rejected', GoogleAuth::verifyIdToken($hs, $jwks, $CID, $NONCE, $NOW)['error'] === 'bad_alg');
t('unknown key id is reported as unknown_key (triggers one key refresh)', $v([], $key, 'zzz')['error'] === 'unknown_key');
t('wrong issuer rejected', $v(['iss' => 'https://evil.example'])['error'] === 'bad_issuer');
t('token issued for another client (audience) rejected', $v(['aud' => 'other-client', 'azp' => 'other-client'])['error'] === 'bad_audience');
t('audience array without matching azp rejected', $v(['aud' => [$CID, 'x'], 'azp' => 'x'])['error'] === 'bad_audience');
t('expired token rejected', $v(['exp' => $NOW - 3600])['error'] === 'expired_token');
t('token from the future (iat) rejected', $v(['iat' => $NOW + 3600])['error'] === 'bad_iat');
t('nonce mismatch (replayed token) rejected', $v(['nonce' => 'other'])['error'] === 'bad_nonce');
t('missing nonce rejected', $v([], null, 'k1', 'RS256', ['nonce'])['error'] === 'bad_nonce');
t('unverified Google e-mail rejected', $v(['email_verified' => false])['error'] === 'email_unverified');
t('missing e-mail rejected', $v([], null, 'k1', 'RS256', ['email'])['error'] === 'no_email');
t('missing subject rejected', $v([], null, 'k1', 'RS256', ['sub'])['error'] === 'bad_subject');
t('garbage / truncated / oversized tokens rejected without errors', GoogleAuth::verifyIdToken('a.b', $jwks, $CID, $NONCE, $NOW)['ok'] === false && GoogleAuth::verifyIdToken('!!.??.**', $jwks, $CID, $NONCE, $NOW)['ok'] === false && GoogleAuth::verifyIdToken(str_repeat('a', 9000), $jwks, $CID, $NONCE, $NOW)['ok'] === false);
t('malformed JWK (short modulus) yields no key rather than an exception', GoogleAuth::jwkToPem(['n' => 'AQAB', 'e' => 'AQAB']) === '' && GoogleAuth::jwkToPem([]) === '');

// ── begin / complete flow ─────────────────────────────────────────────────────
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$url = GoogleAuth::begin('package-list.php', $NOW); $q = []; parse_str(parse_url($url, PHP_URL_QUERY), $q); $s = $_SESSION['g_oauth'];
t('auth URL: Google endpoint, client id, registered redirect URI, scopes, code flow', str_starts_with($url, GoogleAuth::AUTH_URL) && $q['client_id'] === $CID && $q['redirect_uri'] === 'https://tms.example.test/google-callback.php' && $q['response_type'] === 'code' && $q['scope'] === 'openid email profile');
t('auth URL: PKCE S256 challenge equals the hash of the verifier held only in the session', $q['code_challenge_method'] === 'S256' && $q['code_challenge'] === b64u(hash('sha256', $s['verifier'], true)) && strlen($s['verifier']) >= 43);
t('state and nonce are in the URL, long and random (two calls differ)', $q['state'] === $s['state'] && $q['nonce'] === $s['nonce'] && strlen($s['state']) >= 32 && GoogleAuth::begin('x', $NOW) && $_SESSION['g_oauth']['state'] !== $s['state']);

$jwksCalls = 0; $tokenCalls = []; $respond = null;
$transport = function (string $m, string $u, ?array $f) use (&$jwksCalls, &$tokenCalls, &$respond, &$jwks) {
    if ($u === GoogleAuth::JWKS_URL) { $jwksCalls++; return [200, json_encode($jwks)]; }
    $tokenCalls[] = $f; return $respond($f);
};
$dbh->exec("DELETE FROM tblcache WHERE cache_key = 'google_jwks_v1'");
$begin = function (array $claims, ?array $mut = null) use ($key, $NOW, &$respond) {
    GoogleAuth::begin('hotel.php', $NOW); $st = $_SESSION['g_oauth'];
    $respond = fn($f) => [200, json_encode(['id_token' => jwt(['nonce' => $st['nonce']] + $claims, $key), 'access_token' => 'x'])];
    return $st;
};
$st = $begin($ok);
$r = GoogleAuth::complete($dbh, ['code' => 'AUTHCODE', 'state' => $st['state']], $transport, $NOW);
t('full callback: accepted, redirect target preserved, claims returned', $r['ok'] && $r['redirect'] === 'hotel.php' && $r['claims']['sub'] === '1100001');
$f = $tokenCalls[0];
t('token request: code, secret, redirect URI, authorization_code grant and the PKCE verifier are sent', $f['code'] === 'AUTHCODE' && $f['code_verifier'] === $st['verifier'] && $f['grant_type'] === 'authorization_code' && $f['client_secret'] === 'test-secret' && $f['redirect_uri'] === GoogleAuth::redirectUri());
t('the one-time session data is gone: replaying the same callback fails', GoogleAuth::complete($dbh, ['code' => 'AUTHCODE', 'state' => $st['state']], $transport, $NOW)['error'] === 'no_session');
$st = $begin($ok); $before = count($tokenCalls);
t('wrong state (CSRF) rejected BEFORE any request to Google', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => 'forged'], $transport, $NOW)['error'] === 'bad_state' && count($tokenCalls) === $before);
$st = $begin($ok);
t('missing code rejected', GoogleAuth::complete($dbh, ['state' => $st['state']], $transport, $NOW)['error'] === 'bad_state');
$st = $begin($ok);
t('user pressed "Cancel" at Google (error=access_denied)', GoogleAuth::complete($dbh, ['error' => 'access_denied', 'state' => $st['state']], $transport, $NOW)['error'] === 'denied');
$st = $begin($ok);
t('state older than 10 minutes rejected', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW + 700)['error'] === 'expired');
t('callback with no session at all rejected', (function () use ($dbh, $transport, $NOW) { unset($_SESSION['g_oauth']); return GoogleAuth::complete($dbh, ['code' => 'c', 'state' => 'x'], $transport, $NOW)['error']; })() === 'no_session');
$st = $begin($ok); $respond = fn($f) => [400, json_encode(['error' => 'invalid_grant'])];
t('Google refuses the code (400): sign-in fails cleanly', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW)['error'] === 'token_exchange');
$st = $begin($ok); $respond = fn($f) => [200, '<html>proxy page</html>'];
t('non-JSON answer from the token endpoint fails cleanly', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW)['error'] === 'token_exchange');
$st = $begin($ok); $respond = fn($f) => [200, json_encode(['id_token' => jwt(['nonce' => 'someone-elses'] + $ok, $key)])];
t('ID token carrying another login attempt\'s nonce is refused', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW)['error'] === 'bad_nonce');
$st = $begin($ok); $respond = fn($f) => [200, json_encode(['id_token' => jwt(['nonce' => $st['nonce']] + $ok, $key2)])];
t('ID token signed by a key Google never published is refused (even though the transport is "TLS")', in_array(GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW)['error'], ['bad_signature'], true));
$jwksCalls = 0; $st = $begin($ok);
GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW);
t('signing keys are cached: a further sign-in does not download them again', $jwksCalls === 0);
// key rotation: Google publishes a new key, the cache still has the old one
[$key3, $pub3] = mkKey(); $pub3['kid'] = 'k2'; $jwks = ['keys' => [$pub, $pub3]]; $jwksCalls = 0;
GoogleAuth::begin('x', $NOW); $st = $_SESSION['g_oauth']; $respond = fn($f) => [200, json_encode(['id_token' => jwt(['nonce' => $st['nonce']] + $ok, $key3, 'k2')])];
t('key rotation: unknown key id triggers exactly one refresh and then succeeds', GoogleAuth::complete($dbh, ['code' => 'c', 'state' => $st['state']], $transport, $NOW)['ok'] === true && $jwksCalls === 1);

// ── accounts ──────────────────────────────────────────────────────────────────
GoogleAuth::migrate($dbh);
$mail = 'g-new@google-test.example.test'; $mail2 = 'g-existing@google-test.example.test'; $mail3 = 'g-deleted@google-test.example.test'; $mail4 = 'g-2fa@google-test.example.test';
$clean = function () use ($dbh) { $dbh->exec("DELETE FROM tbl_user_identities WHERE email LIKE '%@google-test.example.test'"); $dbh->exec("DELETE FROM tblloyalty WHERE UserEmail LIKE '%@google-test.example.test'"); $dbh->exec("DELETE FROM tblusers WHERE EmailId LIKE '%@google-test.example.test'"); };
$clean();
$c = fn(string $sub, string $email, string $name = 'G User') => ['sub' => $sub, 'email' => $email, 'name' => $name];
$r = GoogleAuth::resolveUser($dbh, $c('s-new', $mail, 'Brand New'));
$row = $dbh->query("SELECT * FROM tblusers WHERE EmailId='$mail'")->fetch(PDO::FETCH_ASSOC);
t('new Google visitor: account created with their name and e-mail, identity linked', $r['status'] === 'created' && $row && $row['FullName'] === 'Brand New' && (int)$dbh->query("SELECT COUNT(*) FROM tbl_user_identities WHERE subject='s-new' AND user_id=" . (int)$row['id'])->fetchColumn() === 1);
t('...with a loyalty row and a referral code like every other account', (int)$dbh->query("SELECT COUNT(*) FROM tblloyalty WHERE UserEmail='$mail'")->fetchColumn() === 1 && !empty($row['ReferralCode']));
t('...and an UNUSABLE password: neither empty nor a guessable value signs in', !tms_verify_password('', $row['Password'], (int)$row['id'], $dbh) && !tms_verify_password('password', $row['Password'], (int)$row['id'], $dbh) && !tms_verify_password($row['Password'], $row['Password'], (int)$row['id'], $dbh));
$r = GoogleAuth::resolveUser($dbh, $c('s-new', $mail));
t('same Google account again: logs in, no second account', $r['status'] === 'login' && (int)$dbh->query("SELECT COUNT(*) FROM tblusers WHERE EmailId='$mail'")->fetchColumn() === 1);
$r = GoogleAuth::resolveUser($dbh, $c('s-new', 'changed-address@google-test.example.test'));
t('the match is by Google\'s immutable id: a changed Google e-mail still reaches the same account', $r['status'] === 'login' && $r['user']['EmailId'] === $mail);
$dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)')->execute(['Pre Existing', '01700000000', $mail2, tms_hash_password('CorrectHorse1')]);
$r = GoogleAuth::resolveUser($dbh, $c('s-existing', strtoupper($mail2)));
t('e-mail of an existing site account: NOT linked silently, password confirmation required', $r['status'] === 'link_required' && (int)$dbh->query("SELECT COUNT(*) FROM tbl_user_identities WHERE subject='s-existing'")->fetchColumn() === 0);
$uid = (int)$r['user']['id']; GoogleAuth::link($dbh, $uid, $c('s-existing', $mail2));
t('after the owner confirmed (link()), Google signs straight in', GoogleAuth::resolveUser($dbh, $c('s-existing', $mail2))['status'] === 'login');
t('linking twice is harmless (unique identity)', (function () use ($dbh, $uid, $c, $mail2) { GoogleAuth::link($dbh, $uid, $c('s-existing', $mail2)); return (int)$dbh->query("SELECT COUNT(*) FROM tbl_user_identities WHERE subject='s-existing'")->fetchColumn(); })() === 1);
$dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password, DeletedAt) VALUES (?,?,?,?,NOW())')->execute(['Gone', '01700000001', $mail3, tms_hash_password('x1y2z3')]);
t('deleted account: refused (no sign-in, no resurrection)', GoogleAuth::resolveUser($dbh, $c('s-del', $mail3))['status'] === 'blocked');
$did = (int)$dbh->query("SELECT id FROM tblusers WHERE EmailId='$mail3'")->fetchColumn(); GoogleAuth::link($dbh, $did, $c('s-del', $mail3));
t('deleted account that was linked before: also refused', GoogleAuth::resolveUser($dbh, $c('s-del', $mail3))['status'] === 'blocked');

// session start and 2FA
$_SESSION = [];
$to = GoogleAuth::startSession(['id' => 5, 'EmailId' => $mail, 'FullName' => 'N', 'TwoFAEnabled' => 0, 'TwoFASecret' => null], 'hotel.php');
t('normal account: signed in and sent to the requested page', $to === 'hotel.php' && $_SESSION['login'] === $mail && $_SESSION['userid'] === 5);
$_SESSION = [];
$to = GoogleAuth::startSession(['id' => 6, 'EmailId' => $mail4, 'FullName' => 'T', 'TwoFAEnabled' => 1, 'TwoFASecret' => 'ABCDEF'], 'hotel.php');
t('account with 2FA: NOT signed in; parked at the authenticator prompt', $to === 'login.php' && empty($_SESSION['login']) && $_SESSION['u2fa_pending'] === $mail4 && $_SESSION['u2fa_pending_redirect'] === 'hotel.php');
$clean();

// ── HTTP: a server started WITH Google configured, one WITHOUT ─────────────────
$on = rtrim($argv[1] ?? '', '/'); $off = rtrim($argv[2] ?? '', '/');
$get = function (string $url, string $jar = '') { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => $jar ?: '/dev/null', CURLOPT_COOKIEFILE => $jar ?: '']); $raw = (string)curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m); return [$code, $m[1] ?? '', substr($raw, $hs)]; };
if ($on !== '') {
    $jar = tempnam(sys_get_temp_dir(), 'gj');
    [$code, $loc] = $get($on . '/google-login.php?redirect=' . rawurlencode('//evil.example/x'), $jar);
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q2);
    t('HTTP /google-login.php redirects to Google with PKCE, state and nonce', $code === 302 && str_starts_with($loc, GoogleAuth::AUTH_URL) && !empty($q2['state']) && !empty($q2['nonce']) && ($q2['code_challenge_method'] ?? '') === 'S256');
    [$code, $loc] = $get($on . '/google-callback.php?code=abc&state=' . urlencode($q2['state']), $jar . 'x');
    t('HTTP callback from a browser WITHOUT the login session (state not bound to it) goes back to the login page', $code === 302 && str_contains($loc, 'login.php'));
    [$code, $loc] = $get($on . '/google-callback.php?code=abc&state=forged', $jar);
    t('HTTP callback with a forged state is refused (back to login, nothing signed in)', $code === 302 && str_contains($loc, 'login.php'));
    [$code, , $body] = $get($on . '/login.php', $jar);
    t('HTTP login page shows the refusal message and the Google button when configured', str_contains($body, 'could not be completed') && str_contains($body, 'google-login.php'));
    [$code, $loc] = $get($on . '/google-link.php', $jar);
    t('HTTP /google-link.php without a pending link goes to the login page', $code === 302 && str_contains($loc, 'login.php'));
    [$code, , $body] = $get($on . '/register.php');
    t('HTTP register page offers "Sign up with Google"', str_contains($body, 'Sign up with Google'));
    @unlink($jar); @unlink($jar . 'x');
}
if ($off !== '') {
    [$code, $loc] = $get($off . '/google-login.php');
    [, , $body] = $get($off . '/login.php');
    t('HTTP with Google NOT configured: start page refuses, and no button is shown anywhere', $code === 302 && str_contains($loc, 'login.php') && !str_contains($body, 'google-login.php'));
}
ob_end_flush();
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
