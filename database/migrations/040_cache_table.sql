-- Shared cache table used by CacheService (previously created on every web request).
CREATE TABLE IF NOT EXISTS tblcache (
  cache_key VARCHAR(191) NOT NULL PRIMARY KEY,
  cache_value LONGTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
