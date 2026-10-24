<?php
/**
 * GoogleAuth — "Sign in with Google" using the OAuth 2.0 authorization-code flow with PKCE and OpenID Connect.
 *
 * Security properties (each one is covered by tests/auth/google_test.php):
 *  - `state` (CSRF) and `nonce` (replay) are random, bound to the visitor's session, expire after 10 minutes and are single-use.
 *  - PKCE (S256): the code is useless without the verifier that only this session holds.
 *  - The ID token is fetched server-to-server over TLS AND its RS256 signature is verified against Google's published keys
 *    (JWKS, cached); `alg` is pinned to RS256 (no "none", no HS256 key confusion), `iss`, `aud`, `exp`, `iat`, `nonce`,
 *    `sub` and `email_verified` are all checked.
 *  - Accounts are matched by Google's immutable `sub` (tbl_user_identities), never by e-mail alone. An existing site account
 *    with the same e-mail is NOT linked silently: the visitor must prove ownership with the site password first (someone could
 *    have pre-registered the address here), see frontend/google-link.php.
 *  - Accounts with the site's own 2FA still need their authenticator code after Google sign-in.
 * Not stored: Google access/refresh tokens (they are never needed after the identity check).
 */
final class GoogleAuth
{
    public const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const JWKS_URL  = 'https://www.googleapis.com/oauth2/v3/certs';
    public const STATE_TTL = 600;
    private const ISSUERS  = ['https://accounts.google.com', 'accounts.google.com'];

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_user_identities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider` varchar(20) NOT NULL,
  `subject` varchar(64) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_identity` (`provider`,`subject`),
  KEY `idx_identity_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private static function env(string $k): string { return trim((string)(function_exists('tms_env') ? tms_env($k, '') : getenv($k))); }
    public static function clientId(): string { return self::env('GOOGLE_CLIENT_ID'); }
    private static function clientSecret(): string { return self::env('GOOGLE_CLIENT_SECRET'); }
    public static function enabled(): bool { return self::clientId() !== '' && self::clientSecret() !== ''; }

    /** The exact URI that must be registered as an "Authorized redirect URI" in Google Cloud Console. */
    public static function redirectUri(): string
    {
        $o = self::env('GOOGLE_REDIRECT_URI');
        if ($o !== '' && preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?/[^\s]*$#', $o)) return $o;
        return rtrim(tms_public_base_url(), '/') . '/google-callback.php';
    }

    private static function b64u(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
    private static function b64uDecode(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

    /** Start: remember state/nonce/verifier in the session and return the Google URL to send the visitor to. */
    public static function begin(string $safeRedirect, ?int $now = null): string
    {
        $now = $now ?? time();
        $state = self::b64u(random_bytes(24)); $nonce = self::b64u(random_bytes(24)); $verifier = self::b64u(random_bytes(48));
        $_SESSION['g_oauth'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'redirect' => $safeRedirect, 't' => $now];
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => self::clientId(), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => self::b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
            'prompt' => 'select_account', 'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Callback: validate state, exchange the code, verify the ID token.
     * @param array $query  $_GET of the callback
     * @param ?callable $transport fn(string $method, string $url, ?array $form): array{0:int,1:string}  (tests inject a stub; default is curl with TLS verification)
     * @return array{ok:bool,error?:string,claims?:array,redirect?:string}
     */
    public static function complete(PDO $dbh, array $query, ?callable $transport = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $s = $_SESSION['g_oauth'] ?? null; unset($_SESSION['g_oauth']);        // single use, whatever happens next
        if (!is_array($s)) return ['ok' => false, 'error' => 'no_session'];
        if (!empty($query['error'])) return ['ok' => false, 'error' => 'denied'];
        $code = (string)($query['code'] ?? ''); $state = (string)($query['state'] ?? '');
        if ($code === '' || $state === '' || !hash_equals((string)$s['state'], $state)) return ['ok' => false, 'error' => 'bad_state'];
        if ($now - (int)$s['t'] > self::STATE_TTL || $now < (int)$s['t'] - 5) return ['ok' => false, 'error' => 'expired'];
        if (strlen($code) > 2048) return ['ok' => false, 'error' => 'bad_state'];
        $transport = $transport ?? [self::class, 'curlTransport'];
        [$http, $body] = $transport('POST', self::TOKEN_URL, [
            'code' => $code, 'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'code_verifier' => (string)$s['verifier'],
        ]);
        $tok = json_decode((string)$body, true);
        if ($http !== 200 || !is_array($tok) || empty($tok['id_token']) || !is_string($tok['id_token'])) return ['ok' => false, 'error' => 'token_exchange'];
        $jwks = self::jwks($dbh, $transport, $now);
        $v = self::verifyIdToken($tok['id_token'], $jwks, self::clientId(), (string)$s['nonce'], $now);
        if (!$v['ok'] && ($v['error'] ?? '') === 'unknown_key') {                // keys rotated: refresh once
            $jwks = self::jwks($dbh, $transport, $now, true);
            $v = self::verifyIdToken($tok['id_token'], $jwks, self::clientId(), (string)$s['nonce'], $now);
        }
        if (!$v['ok']) return ['ok' => false, 'error' => $v['error']];
        return ['ok' => true, 'claims' => $v['claims'], 'redirect' => (string)($s['redirect'] ?? 'index.php')];
    }

    /** @return array{ok:bool,error?:string,claims?:array} */
    public static function verifyIdToken(string $jwt, array $jwks, string $clientId, string $nonce, int $now): array
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3 || strlen($jwt) > 8192) return ['ok' => false, 'error' => 'malformed'];
        $h = json_decode((string)self::b64uDecode($p[0]), true); $c = json_decode((string)self::b64uDecode($p[1]), true); $sig = self::b64uDecode($p[2]);
        if (!is_array($h) || !is_array($c) || $sig === false || $sig === '') return ['ok' => false, 'error' => 'malformed'];
        if (($h['alg'] ?? '') !== 'RS256') return ['ok' => false, 'error' => 'bad_alg'];       // pins RS256: rejects "none" and HS256 confusion
        $kid = (string)($h['kid'] ?? ''); $jwk = null;
        foreach (($jwks['keys'] ?? []) as $k) if (is_array($k) && ($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA') { $jwk = $k; break; }
        if (!$jwk || $kid === '') return ['ok' => false, 'error' => 'unknown_key'];
        $pem = self::jwkToPem($jwk);
        if ($pem === '' || openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return ['ok' => false, 'error' => 'bad_signature'];
        if (!in_array($c['iss'] ?? '', self::ISSUERS, true)) return ['ok' => false, 'error' => 'bad_issuer'];
        $aud = $c['aud'] ?? null;
        if (!(is_string($aud) ? $aud === $clientId : (is_array($aud) && in_array($clientId, $aud, true) && ($c['azp'] ?? '') === $clientId))) return ['ok' => false, 'error' => 'bad_audience'];
        if (!isset($c['exp']) || !is_numeric($c['exp']) || (int)$c['exp'] < $now - 60) return ['ok' => false, 'error' => 'expired_token'];
        if (!isset($c['iat']) || !is_numeric($c['iat']) || (int)$c['iat'] > $now + 300) return ['ok' => false, 'error' => 'bad_iat'];
        if (!isset($c['nonce']) || !is_string($c['nonce']) || !hash_equals($nonce, $c['nonce'])) return ['ok' => false, 'error' => 'bad_nonce'];
        $sub = (string)($c['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 64) return ['ok' => false, 'error' => 'bad_subject'];
        $email = strtolower(trim((string)($c['email'] ?? '')));
        $verified = $c['email_verified'] ?? false;
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) return ['ok' => false, 'error' => 'no_email'];
        if ($verified !== true && $verified !== 'true') return ['ok' => false, 'error' => 'email_unverified'];
        return ['ok' => true, 'claims' => ['sub' => $sub, 'email' => $email, 'name' => trim(mb_substr((string)($c['name'] ?? ''), 0, 100))]];
    }

    /** Google's signing keys, cached for up to an hour (Cache-Control is not parsed; a rotation triggers one forced refresh). */
    private static function jwks(PDO $dbh, callable $transport, int $now, bool $force = false): array
    {
        $key = 'google_jwks_v1';
        if (!$force) {
            try { $st = $dbh->prepare('SELECT cache_value FROM tblcache WHERE cache_key = ? AND expires_at > FROM_UNIXTIME(?)'); $st->execute([$key, $now]); $v = $st->fetchColumn(); if ($v) { $j = json_decode($v, true); if (is_array($j) && !empty($j['keys'])) return $j; } } catch (Throwable $e) {}
        }
        [$http, $body] = $transport('GET', self::JWKS_URL, null);
        $j = json_decode((string)$body, true);
        if ($http !== 200 || !is_array($j) || empty($j['keys'])) return ['keys' => []];
        try { $dbh->prepare('REPLACE INTO tblcache (cache_key, cache_value, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))')->execute([$key, json_encode($j), $now + 3600]); } catch (Throwable $e) {}
        return $j;
    }

    public static function curlTransport(string $method, string $url, ?array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 262144]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array)$form)); }
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    /** JWK (RSA) -> PEM SubjectPublicKeyInfo. Returns '' for anything malformed. */
    public static function jwkToPem(array $jwk): string
    {
        $n = self::b64uDecode((string)($jwk['n'] ?? '')); $e = self::b64uDecode((string)($jwk['e'] ?? ''));
        if ($n === false || $e === false || $n === '' || $e === '' || strlen($n) < 128) return '';
        $len = function (int $l): string { return $l < 128 ? chr($l) : (function () use ($l) { $b = ltrim(pack('N', $l), "\0"); return chr(0x80 | strlen($b)) . $b; })(); };
        $int = fn(string $x) => "\x02" . $len(strlen("\0" . ltrim($x, "\0"))) . "\0" . ltrim($x, "\0");
        $seq = fn(string $x) => "\x30" . $len(strlen($x)) . $x;
        $rsa = $seq($int($n) . $int($e));
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = $seq($alg . "\x03" . $len(strlen($rsa) + 1) . "\0" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** "Continue with Google" button HTML ('' when Google login is not configured). */
    public static function button(string $redirect = '', string $label = 'Continue with Google'): string
    {
        if (!self::enabled()) return '';
        $href = 'google-login.php' . ($redirect !== '' ? '?redirect=' . rawurlencode($redirect) : '');
        $g = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" style="flex:none"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="tms-google-btn" style="display:flex;align-items:center;justify-content:center;gap:10px;height:44px;border:1px solid #dadce0;border-radius:8px;background:#fff;color:#3c4043;font-weight:600;font-size:14px;text-decoration:none;margin:14px 0 4px;">'
             . $g . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></a>';
    }

    // ── Account resolution ───────────────────────────────────────────────────────

    /**
     * @return array{status:string,user?:array}  status: login | created | link_required | blocked
     */
    public static function resolveUser(PDO $dbh, array $claims): array
    {
        $st = $dbh->prepare("SELECT u.id, u.EmailId, u.FullName, u.DeletedAt, u.TwoFAEnabled, u.TwoFASecret FROM tbl_user_identities i JOIN tblusers u ON u.id = i.user_id WHERE i.provider = 'google' AND i.subject = ? LIMIT 1");
        $st->execute([$claims['sub']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            if (!empty($u['DeletedAt'])) return ['status' => 'blocked'];
            $dbh->prepare("UPDATE tbl_user_identities SET last_login_at = NOW() WHERE provider = 'google' AND subject = ?")->execute([$claims['sub']]);
            return ['status' => 'login', 'user' => $u];
        }
        $st = $dbh->prepare('SELECT id, EmailId, FullName, DeletedAt, TwoFAEnabled, TwoFASecret FROM tblusers WHERE EmailId = ? LIMIT 1');
        $st->execute([$claims['email']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) return !empty($u['DeletedAt']) ? ['status' => 'blocked'] : ['status' => 'link_required', 'user' => $u];

        // Brand-new account: random, unusable password (the owner can set one through "Forgot password" if they ever want one).
        $name = $claims['name'] !== '' ? $claims['name'] : explode('@', $claims['email'])[0];
        $dbh->beginTransaction();
        try {
            $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password, RegDate, ReferralCode) VALUES (?, NULL, ?, ?, NOW(), ?)')
                ->execute([$name, $claims['email'], tms_hash_password(bin2hex(random_bytes(32))), tms_generate_referral_code($dbh)]);
            $uid = (int)$dbh->lastInsertId();
            self::link($dbh, $uid, $claims);
            try { $dbh->prepare('INSERT IGNORE INTO tblloyalty (UserEmail, Points, TotalEarned) VALUES (?,0,0)')->execute([$claims['email']]); } catch (Throwable $e) {}
            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TMS Google] account creation failed: ' . $e->getMessage());
            return ['status' => 'blocked'];
        }
        return ['status' => 'created', 'user' => ['id' => $uid, 'EmailId' => $claims['email'], 'FullName' => $name, 'DeletedAt' => null, 'TwoFAEnabled' => 0, 'TwoFASecret' => null]];
    }

    public static function link(PDO $dbh, int $userId, array $claims): void
    {
        $dbh->prepare("INSERT INTO tbl_user_identities (user_id, provider, subject, email, last_login_at) VALUES (?, 'google', ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_login_at = NOW()")
            ->execute([$userId, $claims['sub'], $claims['email']]);
    }

    /** Sign the visitor in (or park them at the 2FA prompt) exactly as login.php does. Returns the URL to redirect to. */
    public static function startSession(array $user, string $safeRedirect): string
    {
        session_regenerate_id(true);
        if (!empty($user['TwoFAEnabled']) && !empty($user['TwoFASecret'])) {
            $_SESSION['u2fa_pending'] = $user['EmailId']; $_SESSION['u2fa_pending_redirect'] = $safeRedirect;
            return 'login.php';
        }
        $_SESSION['login'] = $user['EmailId']; $_SESSION['userid'] = $user['id']; $_SESSION['username'] = $user['FullName'];
        return $safeRedirect;
    }
}
