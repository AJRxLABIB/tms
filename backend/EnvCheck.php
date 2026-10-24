<?php
/**
 * EnvCheck — validates deployment configuration without ever revealing a value.
 *
 * EnvCheck::run($env) takes a plain array of environment values (so it is testable without .env or a database)
 * and returns a list of ['level' => 'PASS'|'WARN'|'FAIL', 'key' => string, 'message' => string].
 * Messages describe what is wrong; they never contain the value itself. scripts/check_env.php is the CLI wrapper.
 *
 * "Production" means APP_ENV is production, prod or live. Anything else is treated as development/staging, where the
 * same problems are reported as WARN instead of FAIL.
 */
final class EnvCheck
{
    /** Keys read from the process environment by fromEnvironment(). */
    public const KEYS = [
        'APP_ENV', 'APP_URL', 'APP_DEBUG', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'TMS_2FA_KEY', 'WALLET_ENCRYPTION_KEY', 'TMS_AUTO_SCHEMA', 'TMS_ALLOW_SANDBOX_PAYMENTS',
        'PAYMENT_PROVIDER', 'AI_PROVIDER', 'AI_FALLBACK_ALLOW_EXTERNAL', 'GEMINI_API_KEY', 'OPENAI_API_KEY',
        'HEALTH_TOKEN', 'SMTP_HOST', 'TMS_BOOKING_HOLD_MINUTES', 'OLLAMA_BASE_URL',
    ];

    private const PLACEHOLDER_WORDS = ['changeme', 'change_me', 'change-me', 'your_', 'your-', 'yourkey', 'xxxx', 'placeholder',
        'example', 'replace_me', 'replace-me', 'secret123', 'password123', 'insert_'];

    /** @return array<string,string> */
    public static function fromEnvironment(): array
    {
        $env = [];
        foreach (self::KEYS as $k) {
            $v = getenv($k);
            $env[$k] = $v === false ? '' : (string)$v;
        }
        return $env;
    }

    public static function isProduction(array $env): bool
    {
        return in_array(strtolower(trim((string)($env['APP_ENV'] ?? ''))), ['production', 'prod', 'live'], true);
    }

    public static function isTruthy($v): bool
    {
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function looksLikePlaceholder(string $v): bool
    {
        $l = strtolower(trim($v));
        if ($l === '') return false;
        if (preg_match('/^<.*>$/', $l)) return true;                 // <your-key-here>
        if (in_array($l, ['null', 'undefined', 'todo', 'tbd', 'password', 'secret', 'admin', 'root', '123456'], true)) return true;
        foreach (self::PLACEHOLDER_WORDS as $w) {
            if (strpos($l, $w) !== false) return true;
        }
        return false;
    }

    /** @return array<int,array{level:string,key:string,message:string}> */
    public static function run(array $env): array
    {
        $prod = self::isProduction($env);
        $out  = [];
        $get  = fn(string $k): string => trim((string)($env[$k] ?? ''));
        // In production a problem is a FAIL; in development it is only a WARN.
        $bad  = $prod ? 'FAIL' : 'WARN';
        $add  = function (string $level, string $key, string $msg) use (&$out) { $out[] = ['level' => $level, 'key' => $key, 'message' => $msg]; };

        // APP_ENV
        if ($get('APP_ENV') === '') $add('WARN', 'APP_ENV', 'Not set: treated as development. Set APP_ENV=production on a live server.');
        else $add('PASS', 'APP_ENV', $prod ? 'Production mode.' : 'Non-production mode (checks below are advisory).');

        // APP_URL
        $url = $get('APP_URL');
        if ($url === '') $add($bad, 'APP_URL', 'Not set: password-reset and payment links fall back to the request host. Set the public base URL.');
        elseif (!preg_match('#^https?://[^/\s]+#i', $url)) $add($bad, 'APP_URL', 'Is not a valid http(s) URL.');
        elseif ($prod && stripos($url, 'https://') !== 0) $add('FAIL', 'APP_URL', 'Must use https:// in production.');
        elseif ($prod && preg_match('#^https?://(localhost|127\.|\[::1\])#i', $url)) $add('FAIL', 'APP_URL', 'Points at localhost in production.');
        else $add('PASS', 'APP_URL', 'Set.');

        // Debug
        $dbg = strtolower($get('APP_DEBUG'));
        if (self::isTruthy($dbg)) $add($bad, 'APP_DEBUG', 'Debug output is on. Set APP_DEBUG=false.');
        else $add('PASS', 'APP_DEBUG', 'Off.');

        // Database
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $k) {
            if ($get($k) === '') $add($bad, $k, 'Not set: the app would fall back to local development defaults.');
        }
        if ($get('DB_USER') !== '' && strtolower($get('DB_USER')) === 'root') $add($bad, 'DB_USER', 'Uses the root database account. Create a dedicated least-privilege user.');
        if ($get('DB_PASS') === '') $add($bad, 'DB_PASS', 'Empty database password.');
        elseif (self::looksLikePlaceholder($get('DB_PASS'))) $add($bad, 'DB_PASS', 'Looks like a placeholder, not a real password.');
        elseif ($get('DB_HOST') !== '' && $get('DB_NAME') !== '' && $get('DB_USER') !== '' && strtolower($get('DB_USER')) !== 'root') $add('PASS', 'DB_*', 'Database settings present.');

        // Encryption keys
        $k2 = $env['TMS_2FA_KEY'] ?? '';
        if (strlen((string)$k2) < 16) $add($bad, 'TMS_2FA_KEY', 'Missing or shorter than 16 characters: 2FA secrets would stay in plain text.');
        elseif (self::looksLikePlaceholder((string)$k2)) $add($bad, 'TMS_2FA_KEY', 'Looks like a placeholder.');
        else $add('PASS', 'TMS_2FA_KEY', 'Set (length ok).');

        $wk = $get('WALLET_ENCRYPTION_KEY');
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $wk)) $add($bad, 'WALLET_ENCRYPTION_KEY', 'Missing or not 64 hex characters (32 bytes): wallet uploads would be refused.');
        else $add('PASS', 'WALLET_ENCRYPTION_KEY', 'Set (valid shape).');

        // Schema changes in web requests
        $as = $get('TMS_AUTO_SCHEMA');
        if ($prod && !($as !== '' && !self::isTruthy($as))) $add('WARN', 'TMS_AUTO_SCHEMA', 'Production should set TMS_AUTO_SCHEMA=0 and apply schema changes with database/migrate.php.');
        elseif ($as !== '' && !self::isTruthy($as)) $add('PASS', 'TMS_AUTO_SCHEMA', 'Web requests never change the schema.');

        // Payments
        if (self::isTruthy($get('TMS_ALLOW_SANDBOX_PAYMENTS'))) $add($bad, 'TMS_ALLOW_SANDBOX_PAYMENTS', 'Sandbox payments are enabled: anyone could confirm a booking without paying.');
        else $add('PASS', 'TMS_ALLOW_SANDBOX_PAYMENTS', 'Sandbox payment confirmation is not forced on.');
        $pp = strtolower($get('PAYMENT_PROVIDER'));
        if ($prod && ($pp === '' || $pp === 'sandbox')) $add('WARN', 'PAYMENT_PROVIDER', 'Sandbox provider: no real money will be collected.');

        // AI: where does customer text go?
        $ai = strtolower($get('AI_PROVIDER'));
        if ($ai === '') $ai = 'auto';
        if ($ai === 'auto') {
            // Local Ollama first; a Gemini key is used only as a cloud fallback and only if it looks valid.
            $gk = $get('GEMINI_API_KEY');
            if ($gk !== '' && preg_match('/^AIza[0-9A-Za-z_\-]{35}$/', $gk)) $add('WARN', 'AI_PROVIDER', 'auto: Ollama is used when it is running; otherwise customer messages go to Gemini (Google). Set AI_PROVIDER=ollama to keep all AI local.');
            else $add('PASS', 'AI_PROVIDER', 'auto: local Ollama when running, otherwise no AI (catalogue answers only). Nothing is sent to an external service.');
        } elseif ($ai === 'none') {
            $add('PASS', 'AI_PROVIDER', 'AI is turned off; features answer from the catalogue.');
        } elseif ($ai === 'ollama') {
            $ob = $get('OLLAMA_BASE_URL');
            if ($ob !== '' && !preg_match('#^https?://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|\[::1\])#i', $ob))
                $add('WARN', 'OLLAMA_BASE_URL', 'Points at a non-private address: keep the model runtime on localhost or a private network.');
            else $add('PASS', 'AI_PROVIDER', 'Self-hosted (Ollama): customer text stays on your infrastructure.');
        } else {
            $add('WARN', 'AI_PROVIDER', "External AI provider ($ai): customer messages are sent to that service. Make sure this is intended and disclosed.");
        }
        if ($get('GEMINI_API_KEY') !== '' && strpos($get('GEMINI_API_KEY'), 'AIza') !== 0) $add('WARN', 'GEMINI_API_KEY', 'Does not look like a Google AI Studio key (expected to start with "AIza").');
        foreach (['GEMINI_API_KEY', 'OPENAI_API_KEY'] as $k) {
            if ($get($k) !== '' && self::looksLikePlaceholder($get($k))) $add($bad, $k, 'Looks like a placeholder.');
        }

        // Operations
        $ht = $get('HEALTH_TOKEN');
        if ($ht === '') $add($prod ? 'WARN' : 'PASS', 'HEALTH_TOKEN', 'Not set: /health.php shows only up/down (safe). Set a 16+ character token to enable the detailed view for monitoring.');
        elseif (strlen($ht) < 16) $add('WARN', 'HEALTH_TOKEN', 'Shorter than 16 characters: the detailed health view stays disabled.');
        else $add('PASS', 'HEALTH_TOKEN', 'Set.');
        if ($prod && $get('SMTP_HOST') === '') $add('WARN', 'SMTP_HOST', 'Not set: booking confirmations and password resets are only logged, never delivered.');
        if ($prod && $get('TMS_BOOKING_HOLD_MINUTES') === '') $add('WARN', 'TMS_BOOKING_HOLD_MINUTES', 'Not set: unpaid pending bookings hold their seats forever.');

        return $out;
    }

    /** @return array{pass:int,warn:int,fail:int} */
    public static function summarize(array $results): array
    {
        $s = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($results as $r) $s[strtolower($r['level'])]++;
        return $s;
    }
}
