<?php
/**
 * TourBuddy — Minimal .env loader (no Composer / phpdotenv dependency required)
 *
 * Reads KEY=VALUE pairs from a .env file into getenv()/$_ENV so the rest of
 * the app can read them via tms_env(). Safe to call multiple times.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="value with spaces"
 *   KEY='value'
 *   # full-line comments
 *   blank lines are ignored
 */

if (!function_exists('tms_load_dotenv')) {
    function tms_load_dotenv(string $path): void {
        if (!is_readable($path)) {
            return; // no .env present — caller falls back to defaults
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip inline comments only when value isn't quoted
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $hashPos = strpos($value, ' #');
                if ($hashPos !== false) {
                    $value = trim(substr($value, 0, $hashPos));
                }
            }

            // Strip surrounding quotes
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key === '') {
                continue;
            }

            // Don't overwrite real OS-level environment variables if already set
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
            $_ENV[$key]    = $_ENV[$key]    ?? $value;
            $_SERVER[$key] = $_SERVER[$key] ?? $value;
        }
    }
}

if (!function_exists('tms_env')) {
    /**
     * Read an environment value with a fallback default.
     * Empty string in .env is treated as "not set" so defaults still apply.
     */
    function tms_env(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null || $value === '') {
            return $default;
        }

        // Normalize common boolean-ish strings
        $lower = strtolower((string)$value);
        if ($lower === 'true')  return true;
        if ($lower === 'false') return false;

        return $value;
    }
}