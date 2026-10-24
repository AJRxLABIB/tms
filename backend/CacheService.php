<?php
/**
 * includes/CacheService.php — Unified caching layer (Phase 1d)
 *
 * Formalizes the ad hoc per-feature caching that already existed in this
 * project (AIClient's tblai_cache, ExploreService's tbl_explore_cache) into
 * one shared service, instead of every feature reinventing get/set/expire
 * logic against its own table.
 *
 * "Redis-compatible interface" means: the public method names (get, set,
 * delete, remember) match the calling convention of predis/phpredis, so if
 * this app is ever deployed somewhere with a real Redis extension installed,
 * swapping the backend is a one-line change in the constructor — nothing
 * that calls $cache->get()/set() anywhere else in the codebase has to change.
 *
 * Backend priority (fastest/most "Redis-like" first, always with a working
 * fallback so nothing breaks on a bare XAMPP install with no extensions):
 *   1. A real Redis connection, if one is explicitly passed in (future-ready
 *      extension point — not required, not assumed).
 *   2. APCu (in-memory, per-process cache) — used when the apcu extension is
 *      loaded, since it's the closest thing to Redis-speed already available
 *      on most PHP installs without any extra setup.
 *   3. A single shared MySQL table (`tblcache`) — the universal fallback,
 *      works identically to how tblai_cache/tbl_explore_cache already worked,
 *      just consolidated into one table/one implementation instead of two.
 *
 * Values are JSON-encoded so any PHP scalar/array can be stored — the same
 * data shapes AIClient and ExploreService were already caching.
 */

class CacheService {
    private ?PDO $db;
    private bool $apcuAvailable;
    private bool $ensureTable;

    public function __construct(?PDO $db = null, bool $ensureTable = true) {
        $this->db = $db;
        $this->apcuAvailable = function_exists('apcu_fetch') && function_exists('apcu_store') && @apcu_enabled();
        $this->ensureTable = $ensureTable;
        if ($this->db && $this->ensureTable) {
            $this->migrate();
        }
    }

    /** Creates tblcache if missing. Called from the schema gate / migration, not on every request. */
    public function createTable(): void { $this->migrate(); }

    private function migrate(): void {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS tblcache (
                    cache_key VARCHAR(191) NOT NULL PRIMARY KEY,
                    cache_value LONGTEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            error_log('[TMS CacheService] Table migration failed (non-fatal, cache will fail-soft): ' . $e->getMessage());
        }
    }

    /** Fetch a cached value, or null if missing/expired. */
    public function get(string $key): mixed {
        if ($this->apcuAvailable) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            if ($ok) return $val;
            // fall through to DB in case APCu was cleared (e.g. process restart)
            // but a longer-lived DB entry still exists
        }
        if (!$this->db) return null;
        try {
            $stmt = $this->db->prepare("SELECT cache_value FROM tblcache WHERE cache_key = ? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetchColumn();
            if ($row === false) return null;
            $decoded = json_decode($row, true);
            return $decoded === null && $row !== 'null' ? null : $decoded;
        } catch (Exception $e) {
            error_log('[TMS CacheService] get() failed: ' . $e->getMessage());
            return null; // fail-soft: caller treats this as a cache miss, not an error
        }
    }

    /** Store a value with a TTL in seconds. */
    public function set(string $key, mixed $value, int $ttlSeconds): void {
        if ($this->apcuAvailable) {
            apcu_store($key, $value, $ttlSeconds);
        }
        if (!$this->db) return;
        try {
            // Expiry is computed by the DATABASE clock (NOW()), the same clock get()
            // compares against. It used to be built from PHP's date()/time(), so
            // whenever PHP's timezone differed from MySQL's (common on XAMPP:
            // PHP "Europe/Berlin" or UTC vs MySQL local time) entries were born
            // already expired -- or lived hours too long -- and the cache silently
            // never worked.
            $stmt = $this->db->prepare("
                INSERT INTO tblcache (cache_key, cache_value, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), max(1, $ttlSeconds)]);
        } catch (Exception $e) {
            error_log('[TMS CacheService] set() failed: ' . $e->getMessage());
            // fail-soft: a cache write failure should never break the calling feature
        }
    }

    public function delete(string $key): void {
        if ($this->apcuAvailable) apcu_delete($key);
        if (!$this->db) return;
        try {
            $this->db->prepare("DELETE FROM tblcache WHERE cache_key = ?")->execute([$key]);
        } catch (Exception $e) {
            error_log('[TMS CacheService] delete() failed: ' . $e->getMessage());
        }
    }

    /**
     * Get-or-compute helper: returns the cached value if present, otherwise
     * calls $producer(), caches its result, and returns it. Mirrors the
     * "remember" convention from Laravel's Cache facade / common Redis
     * wrapper libraries, since that's the shape almost every call site here
     * actually wants (check cache, else compute, else store).
     */
    public function remember(string $key, int $ttlSeconds, callable $producer): mixed {
        $cached = $this->get($key);
        if ($cached !== null) return $cached;
        $value = $producer();
        if ($value !== null) $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    /** Periodic housekeeping — safe to call occasionally (e.g. from a cron), not required for correctness since get() already filters expired rows. */
    public function purgeExpired(): void {
        if (!$this->db) return;
        try {
            $this->db->exec("DELETE FROM tblcache WHERE expires_at <= NOW()");
        } catch (Exception $e) {
            error_log('[TMS CacheService] purgeExpired() failed: ' . $e->getMessage());
        }
    }
}
