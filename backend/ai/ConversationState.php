<?php
/**
 * ConversationState — the explicit, small state that makes follow-ups ("make that cheaper", "what if I bring my family") work
 * without re-reading the whole chat: the last search arguments and the package in focus. Held server-side in the visitor's own
 * session (never posted by the browser), expires after two hours, contains no personal data (no email, no booking details).
 */
final class ConversationState
{
    private const KEY = 'tms_ai_state';
    private const TTL = 7200;
    /** @var array<string,mixed> used when no PHP session is active (CLI, tests) */
    private static array $mem = [];

    /** @return array{search:array,focus:?int,updated:int} */
    public static function load(): array
    {
        $raw = self::read();
        if (!is_array($raw) || (int)($raw['updated'] ?? 0) < time() - self::TTL) return ['search' => [], 'focus' => null, 'updated' => 0];
        $s = is_array($raw['search'] ?? null) ? $raw['search'] : [];
        return ['search' => self::cleanSearch($s), 'focus' => isset($raw['focus']) ? (int)$raw['focus'] : null, 'updated' => (int)$raw['updated']];
    }

    /** @param array<string,mixed> $search  the last search_packages arguments */
    public static function save(array $search, ?int $focus): void
    {
        self::write(['search' => self::cleanSearch($search), 'focus' => $focus && $focus > 0 ? $focus : null, 'updated' => time()]);
    }

    public static function clear(): void { self::write(null); }

    /** Only known scalar keys are kept, bounded; anything else (including injected content) is dropped. */
    public static function cleanSearch(array $s): array
    {
        $o = [];
        if (isset($s['query']) && is_string($s['query'])) $o['query'] = mb_substr($s['query'], 0, 200);
        foreach (['max_price' => [1, 10000000], 'min_price' => [1, 10000000], 'days_min' => [1, 60], 'days_max' => [1, 60], 'travelers' => [1, 50]] as $k => [$lo, $hi]) {
            if (isset($s[$k]) && is_numeric($s[$k]) && (int)$s[$k] >= $lo && (int)$s[$k] <= $hi) $o[$k] = (int)$s[$k];
        }
        if (isset($s['mode']) && in_array($s['mode'], ['family', 'group'], true)) $o['mode'] = $s['mode'];
        return $o;
    }

    private static function read()
    {
        if (session_status() === PHP_SESSION_ACTIVE) return $_SESSION[self::KEY] ?? null;
        return self::$mem[self::KEY] ?? null;
    }

    private static function write($v): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { if ($v === null) unset($_SESSION[self::KEY]); else $_SESSION[self::KEY] = $v; return; }
        if ($v === null) unset(self::$mem[self::KEY]); else self::$mem[self::KEY] = $v;
    }
}
