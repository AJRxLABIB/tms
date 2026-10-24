<?php
/**
 * Profiler.php — optional per-request timing, off unless TMS_PROFILE=1 in .env.
 *
 * When on, every HTML page request:
 *   - is buffered so a `Server-Timing` header can be attached (visible in Chrome DevTools > Network > Timing),
 *   - writes one line to the PHP error log: "[TMS Profile] /path total=123ms boot=40ms db=3ms peak=8.0MB".
 * Marks are added with TmsProfiler::mark('label'). Nothing secret (no query strings, params or cookies) is recorded.
 * JSON/API/CLI requests are never buffered. With TMS_PROFILE unset this file costs one function call.
 */
final class TmsProfiler
{
    /** @var array<string,float> label => milliseconds since request start */
    private static array $marks = [];
    private static bool $on = false;
    private static float $t0 = 0.0;

    public static function init(): void
    {
        $flag = strtolower(trim((string)getenv('TMS_PROFILE')));
        if ($flag === '' && function_exists('tms_env')) { $flag = strtolower(trim((string)tms_env('TMS_PROFILE', ''))); }
        self::$on = in_array($flag, ['1', 'true', 'yes', 'on'], true) && PHP_SAPI !== 'cli';
        if (!self::$on) { return; }
        self::$t0 = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('#/api/|\.json$#', $uri)) { self::$on = false; return; }   // never buffer API responses
        ob_start([self::class, 'finish']);
    }

    public static function enabled(): bool { return self::$on; }

    /** Record "time since request start" under $label (first call per label wins). */
    public static function mark(string $label): void
    {
        if (!self::$on || isset(self::$marks[$label])) { return; }
        self::$marks[$label] = (microtime(true) - self::$t0) * 1000.0;
    }

    /** Time a callable and record its duration under $label (accumulates). */
    public static function time(string $label, callable $fn)
    {
        if (!self::$on) { return $fn(); }
        $s = microtime(true);
        try { return $fn(); } finally { self::$marks['t:' . $label] = (self::$marks['t:' . $label] ?? 0.0) + (microtime(true) - $s) * 1000.0; }
    }

    /** ob_start callback: attaches the header (if still possible), logs the summary, returns the page untouched. */
    public static function finish(string $buffer): string
    {
        $total = (microtime(true) - self::$t0) * 1000.0;
        $parts = [];
        foreach (self::$marks as $k => $v) {
            $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $k);
            $parts[] = $name . ';dur=' . round($v, 1);
        }
        $parts[] = 'total;dur=' . round($total, 1);
        if (!headers_sent()) { header('Server-Timing: ' . implode(', ', $parts)); }
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $line = '[TMS Profile] ' . $path . ' total=' . round($total) . 'ms';
        foreach (self::$marks as $k => $v) { $line .= ' ' . $k . '=' . round($v) . 'ms'; }
        $line .= ' bytes=' . strlen($buffer) . ' peak=' . round(memory_get_peak_usage(true) / 1048576, 1) . 'MB';
        error_log($line);
        return $buffer;
    }
}
