-- Migration 012: API refresh tokens, scopes, and per-session revocation
-- (master prompt item 42 -- API. Tracked in AUDIT_REPORT_ROUND15.md
-- Section E.2 / roadmap under the audit's own label "Phase 30").
--
-- Purely additive. tblapi_tokens keeps every existing column exactly as
-- it was (id, UserEmail, TokenHash, DeviceLabel, CreatedAt, LastUsedAt,
-- ExpiresAt) -- this only appends one new nullable column, so a token
-- issued before this migration runs still authenticates through the
-- unchanged api_auth_user() bearer-lookup path with full legacy access.
-- NULL/empty Scopes means "no restriction", which is also what every
-- pre-existing row already has by definition.
ALTER TABLE `tblapi_tokens`
    ADD COLUMN IF NOT EXISTS `Scopes` VARCHAR(255) DEFAULT NULL COMMENT 'comma-separated scope list -- NULL means full (legacy) access' AFTER `DeviceLabel`;

-- New table for refresh tokens. One row per issued refresh token, chained
-- by FamilyId across rotations of the same original login so a reused
-- (already-rotated-away) refresh token can be detected as theft and the
-- whole family revoked at once -- not just the one token. TokenHash
-- follows the exact same "never store the raw secret" pattern as
-- tblapi_tokens.TokenHash. ApiTokenId links a refresh token to the access
-- token it was issued alongside, so revoking one session's access token
-- (api/?action=revoke_session) can cascade to its refresh token too.
CREATE TABLE IF NOT EXISTS `tblapi_refresh_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `ApiTokenId` INT(11) DEFAULT NULL,
    `UserEmail` VARCHAR(100) DEFAULT NULL,
    `TokenHash` VARCHAR(64) DEFAULT NULL,
    `FamilyId` VARCHAR(32) DEFAULT NULL,
    `Scopes` VARCHAR(255) DEFAULT NULL,
    `CreatedAt` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `ExpiresAt` DATETIME DEFAULT NULL,
    `RevokedAt` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_refresh_token_hash` (`TokenHash`),
    INDEX `idx_api_refresh_email` (`UserEmail`),
    INDEX `idx_api_refresh_family` (`FamilyId`),
    INDEX `idx_api_refresh_token_id` (`ApiTokenId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
