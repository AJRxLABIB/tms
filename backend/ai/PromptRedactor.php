<?php
/**
 * PromptRedactor — masks personal and secret-looking values in text BEFORE it is logged, stored for evaluation, or sent
 * to a non-local AI provider. Pure functions (no DB, no network).
 *
 * What it masks (Latin AND Bengali digits):
 *   email addresses, API keys / bearer tokens / long hex or base64 secrets, payment-card-like numbers (13-19 digits,
 *   Luhn-valid or not when written in groups), Bangladeshi and international phone numbers, NID-like (10/13/17 digit)
 *   and passport-like (1-2 letters + 6-9 digits) identifiers.
 * This is best-effort pattern masking, not a guarantee: free-text names and addresses are NOT detected.
 *
 * CALL SITES (for the AI gateway / provider owner):
 *   - Any code that writes prompt or reply text to a log, an eval result or a benchmark file MUST pass it through
 *     PromptRedactor::redact() first (AITurnLog::safeText() and EvalRunLog already do).
 *   - Before a request leaves the machine for a NON-local provider (gemini/openai/any URL that is not localhost or a
 *     private address), the gateway should call PromptRedactor::forProvider($provider, $text, $baseUrl). It returns the
 *     text unchanged for local providers (ollama on localhost/private IP), and redacted text otherwise. The assistant
 *     still works because tools fetch real data server-side; only the user's own typed identifiers are masked.
 */
final class PromptRedactor
{
    private const MASK = '[redacted:%s]';

    /** Map Bengali digits to Latin so one set of patterns covers both. */
    public static function foldDigits(string $s): string
    {
        return strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /** @return array{text:string,counts:array<string,int>} */
    public static function redactWithCounts(string $text): array
    {
        $counts = [];
        $bump = function (string $kind) use (&$counts): string { $counts[$kind] = ($counts[$kind] ?? 0) + 1; return sprintf(self::MASK, $kind); };

        // Work on a digit-folded copy only to DECIDE what is a number; we replace in the original by position-safe passes
        // on the folded text itself (the masked output carries no digits, so folding the whole text is acceptable).
        $t = self::foldDigits($text);

        // 1. emails
        $t = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/u', fn($m) => $bump('email'), $t) ?? $t;
        // 2. key-like secrets: well-known prefixes, bearer tokens, long hex/base64 runs
        $t = preg_replace_callback('/\b(?:AIza[0-9A-Za-z_\-]{20,}|AQ\.[0-9A-Za-z_\-]{20,}|sk-[A-Za-z0-9_\-]{16,}|gh[pousr]_[A-Za-z0-9]{20,}|xox[abprs]-[A-Za-z0-9\-]{10,}|AKIA[0-9A-Z]{12,})\b/', fn($m) => $bump('key'), $t) ?? $t;
        $t = preg_replace_callback('/\bBearer\s+[A-Za-z0-9._\-]{16,}/i', fn($m) => $bump('key'), $t) ?? $t;
        $t = preg_replace_callback('/\b(?=[A-Za-z0-9+\/_\-]*[0-9])(?=[A-Za-z0-9+\/_\-]*[A-Za-z])[A-Za-z0-9+\/_\-]{32,}={0,2}(?![A-Za-z0-9])/', fn($m) => $bump('key'), $t) ?? $t;
        $t = preg_replace_callback('/\b[0-9a-f]{32,}\b/i', fn($m) => $bump('key'), $t) ?? $t;
        // 3. phones (before cards: +8801XXXXXXXXX is 13 digits and can pass a Luhn check): BD (+880 / 880 / 01X), then generic international
        $t = preg_replace_callback('/(?<![\d\w])(?:\+?880[ \-]?|0)1[3-9](?:[ \-]?\d){8}(?!\d)/', fn($m) => $bump('phone'), $t) ?? $t;
        $t = preg_replace_callback('/(?<![\d\w])\+\d{1,3}[ \-]?(?:\(?\d{2,4}\)?[ \-]?){2,4}\d{2,4}(?!\d)/', fn($m) => $bump('phone'), $t) ?? $t;
        // 4. payment-card-like: 13-19 digits, optional single spaces or dashes between groups
        $t = preg_replace_callback('/(?<![\d])(?:\d[ \-]?){12,18}\d(?![\d])/', function ($m) use ($bump) {
            $digits = preg_replace('/\D/', '', $m[0]);
            $n = strlen($digits);
            if ($n < 13 || $n > 19) return $m[0];
            // 13 digits written plainly is also an NID length: prefer 'id' when not grouped and not Luhn-valid
            if ($n === 13 && !preg_match('/[ \-]/', $m[0]) && !self::luhn($digits)) return $m[0];
            if ($n === 17 && !preg_match('/[ \-]/', $m[0]) && !self::luhn($digits)) return $m[0];
            return $bump('card');
        }, $t) ?? $t;
        // 5. national-ID-like (10, 13 or 17 plain digits)
        $t = preg_replace_callback('/(?<![\d\w])(?:\d{17}|\d{13}|\d{10})(?![\d\w])/', fn($m) => $bump('id'), $t) ?? $t;
        // 6. passport-like: 1-2 letters then 6-9 digits
        $t = preg_replace_callback('/(?<![A-Za-z0-9])[A-Z]{1,2}\d{6,9}(?![A-Za-z0-9])/', fn($m) => $bump('passport'), $t) ?? $t;

        return ['text' => $t, 'counts' => $counts];
    }

    public static function redact(string $text): string { return self::redactWithCounts($text)['text']; }

    /** Redact, then cap the length (for log fields). */
    public static function forLog(string $text, int $max = 300): string
    {
        $r = self::redact($text);
        return mb_strlen($r) > $max ? mb_substr($r, 0, $max) . '…' : $r;
    }

    /** True when the provider endpoint is on this machine or a private network. */
    public static function isLocalEndpoint(string $baseUrl): bool
    {
        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        if ($host === '') return false;
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) return true;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return false;
    }

    /** Text to send to $provider: unchanged for a local ollama endpoint, redacted for anything else. */
    public static function forProvider(string $provider, string $text, string $baseUrl = ''): string
    {
        if (strtolower($provider) === 'ollama' && self::isLocalEndpoint($baseUrl !== '' ? $baseUrl : 'http://127.0.0.1')) return $text;
        return self::redact($text);
    }

    private static function luhn(string $d): bool
    {
        $sum = 0; $alt = false;
        for ($i = strlen($d) - 1; $i >= 0; $i--) {
            $n = (int)$d[$i];
            if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
            $sum += $n; $alt = !$alt;
        }
        return $sum % 10 === 0;
    }
}
