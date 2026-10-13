<?php
/**
 * ProviderHealth — failure classification and a small circuit breaker for AI providers.
 *
 * Classification (from the `kind` every provider now returns; falls back to `retriable`):
 *   unavailable  network error, timeout, 5xx, short rate limit   -> worth trying another provider
 *   config       bad/missing key, model not installed             -> provider unusable until someone fixes it
 *   quota        daily/billing limit spent                        -> provider unusable for a long while
 *   content      the model refused/blocked/returned nothing        -> NOT a provider fault; never shop for another model
 *
 * Breaker: unavailable x3 in a row opens it for 60 s; config opens it for 5 min; quota for 15 min. While open the
 * provider is skipped without a network call. After the window one trial request is allowed ("half-open"); success resets.
 * State lives in tbl_app_meta (shared by all PHP workers). It is best-effort: two simultaneous requests can both
 * count a failure, which at worst opens the breaker one failure early. If the table is unreachable the breaker is
 * simply never open (fail open), so health tracking can never take the assistant down.
 */
final class ProviderHealth
{
    public const OPEN_SECONDS = ['unavailable' => 60, 'config' => 300, 'quota' => 900];
    public const UNAVAILABLE_THRESHOLD = 3;

    public static function classify(array $res): string
    {
        if (!empty($res['success'])) return 'ok';
        if (isset($res['kind']) && in_array($res['kind'], ['unavailable', 'config', 'quota', 'content'], true)) return $res['kind'];
        return !empty($res['retriable']) ? 'unavailable' : 'content';
    }

    /** Is a failover to another provider appropriate for this failure? */
    public static function shouldFailover(string $kind): bool { return in_array($kind, ['unavailable', 'config', 'quota'], true); }

    /** @return array{fails:int,open_until:int,last:string} */
    public static function state(?PDO $db, string $provider): array
    {
        $blank = ['fails' => 0, 'open_until' => 0, 'last' => ''];
        if (!$db) return $blank;
        try {
            $st = $db->prepare('SELECT v FROM tbl_app_meta WHERE k = ?');
            $st->execute(['ai_cb_' . $provider]);
            $v = $st->fetchColumn();
            if (!is_string($v) || $v === '') return $blank;
            [$f, $o, $l] = array_pad(explode('|', $v, 3), 3, '');
            return ['fails' => (int)$f, 'open_until' => (int)$o, 'last' => (string)$l];
        } catch (Throwable $e) { return $blank; }
    }

    public static function isOpen(?PDO $db, string $provider, ?int $now = null): bool
    {
        $s = self::state($db, $provider);
        return $s['open_until'] > ($now ?? time());
    }

    private static function save(?PDO $db, string $provider, array $s): void
    {
        if (!$db) return;
        try {
            $db->prepare('INSERT INTO tbl_app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
               ->execute(['ai_cb_' . $provider, $s['fails'] . '|' . $s['open_until'] . '|' . mb_substr($s['last'], 0, 20)]);
        } catch (Throwable $e) { /* best effort */ }
    }

    public static function recordSuccess(?PDO $db, string $provider): void
    {
        $s = self::state($db, $provider);
        if ($s['fails'] === 0 && $s['open_until'] === 0) return; // nothing to write on the happy path
        self::save($db, $provider, ['fails' => 0, 'open_until' => 0, 'last' => '']);
    }

    public static function recordFailure(?PDO $db, string $provider, string $kind, ?int $now = null): void
    {
        if ($kind === 'content' || $kind === 'ok') return; // not the provider's fault
        $now = $now ?? time();
        $s = self::state($db, $provider);
        $s['fails']++; $s['last'] = $kind;
        if ($kind === 'unavailable') {
            if ($s['fails'] >= self::UNAVAILABLE_THRESHOLD) $s['open_until'] = $now + self::OPEN_SECONDS['unavailable'];
        } else {
            $s['open_until'] = $now + self::OPEN_SECONDS[$kind];
        }
        self::save($db, $provider, $s);
    }

    /** Admin-friendly snapshot. */
    public static function snapshot(?PDO $db, array $providers): array
    {
        $out = [];
        foreach ($providers as $p) {
            $s = self::state($db, $p);
            $out[$p] = ['status' => $s['open_until'] > time() ? 'open' : ($s['fails'] > 0 ? 'degraded' : 'healthy'),
                        'consecutive_failures' => $s['fails'], 'last_failure_kind' => $s['last'] ?: null,
                        'retry_after_seconds' => max(0, $s['open_until'] - time())];
        }
        return $out;
    }
}
