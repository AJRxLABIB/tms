<?php
/**
 * TwoFactorRecovery — single-use recovery codes for customer 2FA (table tbl_2fa_recovery_codes, migration 030).
 *
 * - generate() makes 10-character codes (shown as XXXXX-XXXXX) from an alphabet without look-alike characters,
 *   using random_bytes().
 * - Only a bcrypt hash is stored. consume() verifies against the user's unused codes under a row lock and marks
 *   the matching one used with a conditional UPDATE, so a code can be redeemed exactly once even under races.
 * - Every method is safe if the table does not exist yet (migration not applied): it returns false/0 instead of throwing,
 *   so sign-in with an authenticator code keeps working.
 * Pure helpers (generate/normalize/looksLikeRecovery/format) need no database.
 */
final class TwoFactorRecovery
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars, no 0/O/1/I
    public const CODE_LENGTH = 10;
    public const DEFAULT_COUNT = 8;
    public const TABLE_CUSTOMER = 'tbl_2fa_recovery_codes';
    public const TABLE_ADMIN    = 'tbl_admin_2fa_recovery_codes'; // migration 031; admin ids and customer ids overlap, so separate table

    /** Only the two known tables may ever be interpolated into SQL. */
    public static function table(string $table): string
    {
        return $table === self::TABLE_ADMIN ? self::TABLE_ADMIN : self::TABLE_CUSTOMER;
    }

    /** @return string[] plain codes, normalised (no dash) */
    public static function generate(int $count = self::DEFAULT_COUNT): array
    {
        $count = max(1, min(20, $count));
        $codes = [];
        $n = strlen(self::ALPHABET);
        while (count($codes) < $count) {
            $bytes = random_bytes(self::CODE_LENGTH);
            $c = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) $c .= self::ALPHABET[ord($bytes[$i]) % $n]; // 256 % 32 == 0: no modulo bias
            $codes[$c] = true;
        }
        return array_keys($codes);
    }

    /** Uppercase, strip spaces/dashes; keeps only characters of the alphabet's range. */
    public static function normalize(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    /** A recovery code is NOT a 6-digit TOTP code: 10 alphanumerics after normalising. */
    public static function looksLikeRecovery(string $input): bool
    {
        $n = self::normalize($input);
        return strlen($n) === self::CODE_LENGTH && !ctype_digit($n);
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    public static function hash(string $normalized): string
    {
        return password_hash($normalized, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /** Replace all of a user's codes with a fresh set. Returns the plain codes (display once) or [] on failure. */
    public static function regenerate(PDO $dbh, int $userId, int $count = self::DEFAULT_COUNT, string $table = self::TABLE_CUSTOMER): array
    {
        $table = self::table($table);
        $codes = self::generate($count);
        $own = !$dbh->inTransaction();
        try {
            if ($own) $dbh->beginTransaction();
            $dbh->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$userId]);
            $ins = $dbh->prepare("INSERT INTO $table (user_id, code_hash) VALUES (?, ?)");
            foreach ($codes as $c) $ins->execute([$userId, self::hash($c)]);
            if ($own) $dbh->commit();
            return $codes;
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy 2FA recovery] regenerate failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function remaining(PDO $dbh, int $userId, string $table = self::TABLE_CUSTOMER): int
    {
        $table = self::table($table);
        try {
            $st = $dbh->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ? AND used_at IS NULL");
            $st->execute([$userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    public static function deleteAll(PDO $dbh, int $userId, string $table = self::TABLE_CUSTOMER): void
    {
        $table = self::table($table);
        try { $dbh->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$userId]); } catch (Throwable $e) { /* table missing */ }
    }

    /** Redeem a code. True exactly once per code. Callers must already rate-limit attempts (login.php does). */
    public static function consume(PDO $dbh, int $userId, string $input, string $table = self::TABLE_CUSTOMER): bool
    {
        $table = self::table($table);
        if (!self::looksLikeRecovery($input)) return false;
        $code = self::normalize($input);
        $own = !$dbh->inTransaction();
        try {
            if ($own) $dbh->beginTransaction();
            $st = $dbh->prepare("SELECT id, code_hash FROM $table WHERE user_id = ? AND used_at IS NULL FOR UPDATE");
            $st->execute([$userId]);
            $hitId = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (password_verify($code, (string)$row['code_hash'])) { $hitId = (int)$row['id']; break; }
            }
            $ok = false;
            if ($hitId > 0) {
                $up = $dbh->prepare("UPDATE $table SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
                $up->execute([$hitId]);
                $ok = $up->rowCount() === 1;
            }
            if ($own) $dbh->commit();
            return $ok;
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy 2FA recovery] consume failed: ' . $e->getMessage());
            return false;
        }
    }
}
