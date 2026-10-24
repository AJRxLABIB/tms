<?php
/**
 * AIRateLimit — atomic fixed-window counters in the database, keyed by a SHA-1 of the client address or account (no raw IP or
 * e-mail is stored). A session-only guest limit is bypassed by dropping the cookie; this one is not. The increment is a single
 * INSERT ... ON DUPLICATE KEY UPDATE, so concurrent requests cannot both read "29" and both pass.
 * If the database fails the limiter fails OPEN (logged): a broken counter must not take the chat down.
 */
final class AIRateLimit
{
    public const WINDOW_SEC = 3600;

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_ai_rate` (
  `k` char(40) NOT NULL,
  `w` int(11) NOT NULL,
  `n` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`k`,`w`),
  KEY `idx_ai_rate_w` (`w`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Stable key for a subject; the namespace keeps guest-IP and account counters apart. */
    public static function key(string $namespace, string $subject): string { return sha1($namespace . '|' . $subject); }

    /**
     * Count one call and say whether it is allowed.
     * @return array{allowed:bool,count:int,limit:int,retry_after:int}
     */
    public static function hit(PDO $dbh, string $key, int $limit, ?int $now = null): array
    {
        $now = $now ?? time(); $w = intdiv($now, self::WINDOW_SEC);
        $retry = self::WINDOW_SEC - ($now % self::WINDOW_SEC);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                // LAST_INSERT_ID(expr) hands back THIS statement's own new value, so a concurrent request cannot inflate my count
                // (a separate SELECT afterwards could see someone else's increment and refuse a call that was within the limit).
                $dbh->prepare('INSERT INTO tbl_ai_rate (k, w, n) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(n + 1)')->execute([$key, $w]);
                $n = (int)$dbh->query('SELECT LAST_INSERT_ID()')->fetchColumn();
                if (random_int(1, 200) === 1) { $dbh->prepare('DELETE FROM tbl_ai_rate WHERE w < ?')->execute([$w - 2]); }
                return ['allowed' => $n <= $limit, 'count' => $n, 'limit' => $limit, 'retry_after' => $retry];
            } catch (Throwable $e) {
                if ($attempt === 0 && str_contains($e->getMessage(), "doesn't exist")) { try { self::migrate($dbh); continue; } catch (Throwable $e2) { $e = $e2; } }
                error_log('[TourBuddy AI rate] failing open: ' . $e->getMessage());
                break;
            }
        }
        return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'retry_after' => $retry];
    }

    /** Hourly limits from the environment (defaults: 60 per guest address, 300 per account). */
    public static function limit(string $kind): int
    {
        $env = $kind === 'user' ? 'AI_USER_HOURLY' : 'AI_GUEST_IP_HOURLY';
        $v = function_exists('tms_env') ? tms_env($env, '') : (string)getenv($env);
        return ctype_digit((string)$v) && (int)$v > 0 ? min((int)$v, 100000) : ($kind === 'user' ? 300 : 60);
    }
}
