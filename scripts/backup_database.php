<?php
/**
 * scripts/backup_database.php — automated, encrypted database backups
 * (master prompt item 60).
 *
 * What this does, honestly:
 *   1. Dumps the whole database with mysqldump (structure + data).
 *   2. Encrypts the dump with AES-256-GCM using PHP's own openssl_encrypt()
 *      — no external openssl.exe dependency, no key ever touches a shell
 *      command line (mysqldump's own password is passed via a temporary
 *      "--defaults-extra-file", not argv, for the same reason: argv is
 *      visible to other processes on the same machine via the process list).
 *   3. Writes the encrypted file into backups/ (blocked from the web by
 *      backups/.htaccess, same pattern as database/).
 *   4. Deletes local backups beyond BACKUP_RETENTION_COUNT (retention policy).
 *   5. If BACKUP_OFFSITE_PATH is set, also copies the encrypted file there.
 *      This is a real, working "offsite" mechanism for a project with no
 *      cloud storage account configured — point it at a synced folder
 *      (Dropbox/Drive/OneDrive desktop client, a mapped network share, an
 *      external drive). It does NOT talk to any cloud API on its own; wiring
 *      up an actual S3/GCS uploader would mean fabricating a working
 *      integration with credentials this project doesn't have (Rule 3).
 *   6. Logs every run (success or failure) to tblbackuplog, so
 *      admin/operations-dashboard.php can show "last successful backup".
 *
 * CLI-only by design — same reasoning as check_alerts.php: this does real
 * filesystem/DB work and must never be reachable over HTTP.
 *
 * Usage:
 *   php scripts/backup_database.php
 *
 * Restore / restore-testing lives in scripts/restore_database.php — see that
 * file and BACKUPS.md for the full restore procedure and how to
 * dry-run-verify a backup without touching the live database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../backend/config.php';

function tms_backup_log(PDO $dbh, string $status, string $filename, int $bytes, string $note): void {
    try {
        $dbh->exec("CREATE TABLE IF NOT EXISTS tblbackuplog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            Filename VARCHAR(255) NOT NULL,
            SizeBytes BIGINT NOT NULL DEFAULT 0,
            Status VARCHAR(20) NOT NULL,
            OffsiteCopied TINYINT(1) NOT NULL DEFAULT 0,
            Note TEXT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $dbh->prepare(
            "INSERT INTO tblbackuplog (Filename, SizeBytes, Status, OffsiteCopied, Note)
             VALUES (:f, :b, :s, :o, :n)"
        );
        $stmt->execute([
            ':f' => $filename, ':b' => $bytes, ':s' => $status,
            ':o' => (int)($GLOBALS['tms_backup_offsite_ok'] ?? 0), ':n' => $note,
        ]);
    } catch (Throwable $e) {
        // Logging failure must never fail the backup itself.
        error_log('[TMS Backup] Could not write tblbackuplog: ' . $e->getMessage());
    }
}

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0750, true);
}
// Defense in depth, same pattern as database/.htaccess -- belt-and-suspenders
// even though backups/ also isn't under any web-served alias by default.
if (!file_exists($backupDir . '/.htaccess')) {
    file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
}

$encKey = tms_env('BACKUP_ENCRYPTION_KEY', '');
if (empty($encKey)) {
    $msg = 'BACKUP_ENCRYPTION_KEY is not set in .env -- refusing to write an unencrypted backup. '
         . 'Generate one with: php -r "echo bin2hex(random_bytes(32));" and add it to .env.';
    fwrite(STDERR, $msg . "\n");
    tms_backup_log($dbh, 'failed', '', 0, $msg);
    exit(1);
}
$keyBin = @hex2bin($encKey);
if ($keyBin === false || strlen($keyBin) !== 32) {
    $msg = 'BACKUP_ENCRYPTION_KEY must be a 64-character hex string (32 bytes). Refusing to run.';
    fwrite(STDERR, $msg . "\n");
    tms_backup_log($dbh, 'failed', '', 0, $msg);
    exit(1);
}

$timestamp = date('Ymd_His');
$dumpPath  = $backupDir . "/tms_{$timestamp}.sql";
$encPath   = $dumpPath . '.enc';

// mysqldump path: prefer an explicit override (common on Windows/XAMPP where
// it's not on PATH), otherwise assume it's on PATH.
$mysqldump = tms_env('MYSQLDUMP_PATH', '') ?: 'mysqldump';

// Pass credentials via a temporary --defaults-extra-file rather than
// --password=... on argv, which would otherwise be visible to any other
// process on the machine (e.g. `ps`/Task Manager) for as long as mysqldump
// runs. File is written with owner-only-readable perms and removed in a
// finally block even if the dump fails.
// BUG FOUND BY ACTUALLY RUNNING THIS SCRIPT (Round 51, master prompt item
// 60 -- "a backup is not tested until restoration is tested", and neither
// half of this had ever actually been executed end-to-end before now):
// MySQL/MariaDB option files treat an unquoted '#' as starting a comment
// for the rest of the line. A DB_USER/DB_PASS/DB_HOST containing '#' (a
// very ordinary character in a strong password) was silently truncated at
// that point, so mysqldump authenticated with the wrong (truncated)
// password and every backup quietly failed -- exactly the kind of bug
// that stays invisible until the day a real backup is needed. Every value
// is now wrapped in double quotes (still escaping backslash/embedded
// double-quote first), which makes '#', ';', and whitespace all safe too.
$escUser = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_USER);
$escPass = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_PASS);
$escHost = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_HOST);
$credFile = tempnam(sys_get_temp_dir(), 'tmsbkcred_');
$credContent = "[client]\n"
             . "user=\"" . $escUser . "\"\n"
             . "password=\"" . $escPass . "\"\n"
             . "host=\"" . $escHost . "\"\n";
file_put_contents($credFile, $credContent);
@chmod($credFile, 0600);

$success = false;
$errorOutput = '';
try {
    $cmd = escapeshellarg($mysqldump)
         . ' --defaults-extra-file=' . escapeshellarg($credFile)
         . ' --single-transaction --routines --triggers '
         . escapeshellarg(DB_NAME)
         . ' > ' . escapeshellarg($dumpPath) . ' 2>' . escapeshellarg($dumpPath . '.err');
    exec($cmd, $outLines, $exitCode);

    $errText = file_exists($dumpPath . '.err') ? trim((string)file_get_contents($dumpPath . '.err')) : '';
    @unlink($dumpPath . '.err');

    if ($exitCode !== 0 || !file_exists($dumpPath) || filesize($dumpPath) === 0) {
        $errorOutput = "mysqldump exited with code {$exitCode}. " . $errText;
        // A failed/partial dump is still a plaintext file that may contain
        // real data -- clean it up rather than leaving it sitting
        // unencrypted in backups/ indefinitely (found during Round 51's
        // first-ever real test run: two empty .sql files were left behind
        // by earlier failed attempts and would otherwise never get removed).
        @unlink($dumpPath);
    } else {
        // Encrypt: AES-256-GCM gives both confidentiality and integrity
        // (the auth tag catches any corruption/tampering on restore).
        $plaintext = file_get_contents($dumpPath);
        $iv  = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            $errorOutput = 'openssl_encrypt failed: ' . (openssl_error_string() ?: 'unknown error');
        } else {
            // Format: [12-byte IV][16-byte GCM tag][ciphertext] -- self-contained,
            // restore_database.php reads this same layout back apart.
            file_put_contents($encPath, $iv . $tag . $ciphertext);
            @unlink($dumpPath); // never leave the plaintext dump on disk
            $success = true;
        }
    }
} finally {
    @unlink($credFile);
}

if (!$success) {
    fwrite(STDERR, "[TMS Backup] FAILED: {$errorOutput}\n");
    tms_backup_log($dbh, 'failed', basename($dumpPath), 0, $errorOutput);
    exit(1);
}

$bytes = filesize($encPath);

// Optional offsite copy.
$GLOBALS['tms_backup_offsite_ok'] = 0;
$offsitePath = tms_env('BACKUP_OFFSITE_PATH', '');
if (!empty($offsitePath)) {
    if (is_dir($offsitePath) && is_writable($offsitePath)) {
        $dest = rtrim($offsitePath, '/\\') . DIRECTORY_SEPARATOR . basename($encPath);
        if (@copy($encPath, $dest)) {
            $GLOBALS['tms_backup_offsite_ok'] = 1;
        } else {
            error_log("[TMS Backup] Offsite copy to {$dest} failed (local backup still succeeded).");
        }
    } else {
        error_log("[TMS Backup] BACKUP_OFFSITE_PATH '{$offsitePath}' is not a writable directory -- skipping offsite copy (local backup still succeeded).");
    }
}

// Retention: keep only the most recent N encrypted backups.
$retention = max(1, (int) tms_env('BACKUP_RETENTION_COUNT', 14));
$existing = glob($backupDir . '/tms_*.sql.enc') ?: [];
usort($existing, fn($a, $b) => filemtime($b) <=> filemtime($a));
$deleted = 0;
foreach (array_slice($existing, $retention) as $old) {
    if (@unlink($old)) $deleted++;
}

$note = "OK. {$bytes} bytes." . ($deleted > 0 ? " Pruned {$deleted} old backup(s) beyond retention of {$retention}." : '');
tms_backup_log($dbh, 'success', basename($encPath), $bytes, $note);

echo date('c') . " backup_database: " . basename($encPath) . " ({$bytes} bytes)"
   . ($GLOBALS['tms_backup_offsite_ok'] ? ', offsite copy OK' : ', no offsite copy')
   . ($deleted > 0 ? ", pruned {$deleted} old backup(s)" : '') . "\n";
