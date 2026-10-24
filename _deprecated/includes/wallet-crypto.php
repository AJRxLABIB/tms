<?php
/**
 * wallet-crypto.php — encryption at rest for Digital Travel Wallet documents
 * (master prompt item 28).
 *
 * Round 86 (master prompt item 28, "Document Wallet"): before this file
 * existed, frontend/wallet.php wrote uploaded passport/visa/ID scans to
 * uploads/wallet/ as the exact raw bytes the browser sent -- confirmed by
 * reading the upload handler directly (a plain move_uploaded_file() call,
 * no openssl_encrypt/libsodium call anywhere). Access control on retrieval
 * was (and remains) solid -- wallet-document.php re-checks DocId+UserEmail
 * ownership on every request -- but that only protects the *application*
 * path; anyone with raw filesystem access (a misconfigured web server
 * serving uploads/ directly, a backup, a compromised host) could read every
 * document in plaintext. This file adds real AES-256-GCM encryption at
 * rest, mirroring the exact pattern this project already uses for database
 * backups (scripts/backup_database.php / scripts/restore_database.php) --
 * same cipher, same [12-byte IV][16-byte GCM tag][ciphertext] layout, same
 * .env-based key (a new WALLET_ENCRYPTION_KEY, separate from
 * BACKUP_ENCRYPTION_KEY so the two secrets can be rotated independently) --
 * rather than inventing a second, different way to do encryption in the
 * same codebase.
 *
 * Backward compatibility (Rule 2 -- never break a working feature): a
 * document uploaded before this round is still plaintext on disk, and its
 * `tbltraveldocuments.Encrypted` column defaults to 0. wallet-document.php
 * only attempts decryption for rows where Encrypted=1; a legacy row is
 * still streamed exactly as before. Every new upload from this round
 * forward is encrypted (uploads refuse outright, with a clear error, if
 * WALLET_ENCRYPTION_KEY isn't configured -- the same fail-closed choice
 * backup_database.php already makes, rather than silently storing a new
 * document unencrypted).
 */

/**
 * Reads and validates WALLET_ENCRYPTION_KEY from .env. Returns the raw
 * 32-byte binary key, or null if it's unset or malformed (logged once per
 * call site, not spammed).
 */
function tms_wallet_encryption_key(): ?string {
    $encKey = tms_env('WALLET_ENCRYPTION_KEY', '');
    if (empty($encKey)) {
        error_log('[TMS Wallet] WALLET_ENCRYPTION_KEY is not set in .env -- cannot encrypt/decrypt wallet documents.');
        return null;
    }
    $keyBin = @hex2bin($encKey);
    if ($keyBin === false || strlen($keyBin) !== 32) {
        error_log('[TMS Wallet] WALLET_ENCRYPTION_KEY must be a 64-character hex string (32 bytes).');
        return null;
    }
    return $keyBin;
}

/**
 * Encrypts $plaintext with AES-256-GCM. Returns the self-contained
 * [12-byte IV][16-byte tag][ciphertext] blob ready to write to disk, or
 * false on failure (logged).
 */
function tms_wallet_encrypt_bytes(string $plaintext, string $keyBin) {
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        error_log('[TMS Wallet] openssl_encrypt failed: ' . (openssl_error_string() ?: 'unknown error'));
        return false;
    }
    return $iv . $tag . $ciphertext;
}

/**
 * Reverses tms_wallet_encrypt_bytes(). Returns the original plaintext, or
 * null if the blob is too short, the key is wrong, or the GCM auth tag
 * doesn't verify (tampered/corrupted file) -- openssl_decrypt() itself
 * returns false in all of those cases, which this normalizes to null.
 */
function tms_wallet_decrypt_bytes(string $raw, string $keyBin): ?string {
    if (strlen($raw) < 28) { // 12-byte IV + 16-byte tag minimum
        error_log('[TMS Wallet] Encrypted document file is too short to contain a valid IV+tag.');
        return null;
    }
    $iv         = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        error_log('[TMS Wallet] openssl_decrypt failed (wrong key or corrupted/tampered file).');
        return null;
    }
    return $plain;
}
