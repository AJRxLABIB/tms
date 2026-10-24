<?php
/**
 * includes/totp.php — Minimal RFC 6238 TOTP (Time-based One-Time Password)
 *
 * Pure PHP, no Composer package required (matches this project's existing
 * "no external dependency for core logic" style, same as includes/env-loader.php).
 * Compatible with Google Authenticator, Authy, 1Password, etc. — any
 * standard TOTP app that reads an otpauth:// URI or a Base32 secret.
 */

if (!function_exists('tms_totp_generate_secret')) {
    /** Generates a random 32-character Base32 secret (160 bits of entropy). */
    function tms_totp_generate_secret(int $length = 32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC 4648 Base32 alphabet
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }
}

if (!function_exists('tms_totp_base32_decode')) {
    function tms_totp_base32_decode(string $b32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) continue; // drop incomplete trailing bits
            $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }
}

if (!function_exists('tms_totp_code_at')) {
    /** Computes the 6-digit TOTP code for a given Unix timestamp (RFC 6238, 30s step, SHA-1, 6 digits — the universal default every authenticator app assumes). */
    function tms_totp_code_at(string $secret, int $timestamp, int $step = 30, int $digits = 6): string {
        $key = tms_totp_base32_decode($secret);
        $counter = (int) floor($timestamp / $step);
        $binCounter = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        );
        return str_pad((string)($truncated % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('tms_2fa_key')) {
    /** Key for encrypting stored 2FA secrets: TMS_2FA_KEY (>=16 chars) from the environment. Empty = encryption not configured (plaintext, as before). */
    function tms_2fa_key(): string {
        $k = function_exists('tms_env') ? (string)tms_env('TMS_2FA_KEY', '') : (string)getenv('TMS_2FA_KEY');
        return strlen($k) >= 16 ? hash('sha256', 'tms-2fa|' . $k, true) : '';
    }
    /** Encrypt a secret for storage (AES-256-GCM, "enc1:" prefix). Returns it unchanged when no key is configured. */
    function tms_2fa_seal(string $secret): string {
        $key = tms_2fa_key();
        if ($key === '' || $secret === '' || str_starts_with($secret, 'enc1:') || !function_exists('openssl_encrypt')) return $secret;
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? $secret : 'enc1:' . rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
    }
    /** Read a stored secret back: decrypts "enc1:" values, passes legacy plaintext through. Returns '' if it cannot be decrypted (wrong/missing key). */
    function tms_2fa_open(string $stored): string {
        if (!str_starts_with($stored, 'enc1:')) return $stored;
        $key = tms_2fa_key(); if ($key === '') return '';
        $raw = base64_decode(strtr(substr($stored, 5), '-_', '+/'), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }
}

if (!function_exists('tms_totp_verify')) {
    /**
     * Verifies a user-entered code against the secret, allowing ±1 time-step
     * (±30s) of clock drift between the user's phone and this server — the
     * same tolerance window virtually every TOTP implementation uses, since
     * without it a slightly-off device clock makes 2FA impossible to pass.
     */
    function tms_totp_verify(string $secret, string $code, int $window = 1): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $secret = tms_2fa_open($secret);          // stored secrets may be encrypted
        if ($secret === '') return false;
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(tms_totp_code_at($secret, $now + ($i * 30)), $code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tms_totp_provisioning_uri')) {
    /** Builds the otpauth:// URI that authenticator apps read via QR code. */
    function tms_totp_provisioning_uri(string $secret, string $accountLabel, string $issuer = 'TMS Admin'): string {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}

if (!function_exists('tms_totp_qr_url')) {
    /**
     * Free, keyless QR-code rendering via api.qrserver.com (same "free API,
     * no signup" philosophy as the rest of this project's integrations —
     * Nominatim, Open-Meteo, OSRM). The manual entry key is ALWAYS shown
     * alongside this image too, so setup still works if this image fails to
     * load (e.g. offline dev environment, or the free service being down).
     */
    function tms_totp_qr_url(string $otpauthUri): string {
        // Deprecated: the TOTP secret must never be sent to a third-party QR service. The setup pages now draw
        // the QR code in the browser with js/qrcode.js. Kept (returning '') only so old callers do not fatal.
        return '';
    }
}
