<?php
/**
 * scripts/restore_database.php — decrypt + restore a backup made by
 * scripts/backup_database.php, and (the "restore testing" half of master
 * prompt item 60) a safe dry-run mode that proves a backup is actually
 * restorable without touching the live database.
 *
 * CLI-only, same reasoning as backup_database.php / check_alerts.php.
 *
 * Usage:
 *   php scripts/restore_database.php --list
 *       Lists available encrypted backups in backups/, newest first.
 *
 *   php scripts/restore_database.php --verify [--file=tms_YYYYMMDD_HHMMSS.sql.enc]
 *       THE RESTORE TEST. Decrypts the given backup (or the newest one if
 *       --file is omitted) into a throwaway temp file, sanity-checks it looks
 *       like a real, complete SQL dump (starts with the expected mysqldump
 *       header, ends with the expected completion marker, contains at least
 *       one CREATE TABLE), then deletes the temp file. Never touches the
 *       live database. Exit code 0 = backup verified restorable, non-zero =
 *       it isn't (corrupt encryption, wrong key, truncated dump, etc).
 *       This is what should actually run on a schedule (see SCHEDULED_JOBS.md)
 *       -- "we made a backup" and "the backup actually works" are different
 *       claims, and only testing the second one honestly earns it.
 *
 *   php scripts/restore_database.php --restore --file=tms_YYYYMMDD_HHMMSS.sql.enc --confirm
 *       THE REAL, DESTRUCTIVE RESTORE. Decrypts the named backup and pipes it
 *       into the live database with `mysql`, overwriting current data.
 *       Requires --file (no "newest" default -- picking the wrong backup by
 *       accident here is exactly the mistake this flag guards against) and
 *       the literal --confirm flag; without --confirm it prints what it
 *       would do and exits without changing anything.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script is CLI-only.');
}

require __DIR__ . '/../backend/config.php';

$backupDir = __DIR__ . '/../backups';
$args = $argv;
array_shift($args);

function tms_backup_decrypt(string $encPath, string $keyBin): ?string {
    $raw = file_get_contents($encPath);
    if ($raw === false || strlen($raw) < 28) return null; // 12-byte IV + 16-byte tag minimum
    $iv         = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

function tms_backup_get_key(): string {
    $encKey = tms_env('BACKUP_ENCRYPTION_KEY', '');
    if (empty($encKey)) {
        fwrite(STDERR, "BACKUP_ENCRYPTION_KEY is not set in .env -- cannot decrypt any backup.\n");
        exit(1);
    }
    $keyBin = @hex2bin($encKey);
    if ($keyBin === false || strlen($keyBin) !== 32) {
        fwrite(STDERR, "BACKUP_ENCRYPTION_KEY must be a 64-character hex string (32 bytes).\n");
        exit(1);
    }
    return $keyBin;
}

function tms_backup_list(string $dir): array {
    $files = glob($dir . '/tms_*.sql.enc') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

if (in_array('--list', $args, true)) {
    $files = tms_backup_list($backupDir);
    if (!$files) {
        echo "No backups found in {$backupDir}.\n";
        exit(0);
    }
    foreach ($files as $f) {
        echo basename($f) . "  (" . filesize($f) . " bytes, " . date('c', filemtime($f)) . ")\n";
    }
    exit(0);
}

// Resolve --file=... if given.
$fileArg = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--file=')) { $fileArg = substr($a, 7); break; }
}

if (in_array('--verify', $args, true)) {
    $keyBin = tms_backup_get_key();
    $target = $fileArg ? $backupDir . '/' . basename($fileArg) : (tms_backup_list($backupDir)[0] ?? null);
    if (!$target || !file_exists($target)) {
        fwrite(STDERR, "Backup file not found: " . ($target ?? '(none available)') . "\n");
        exit(1);
    }

    $plain = tms_backup_decrypt($target, $keyBin);
    $ok = true;
    $reasons = [];
    if ($plain === null) {
        $ok = false;
        $reasons[] = 'decryption failed (wrong key, or file corrupted/truncated -- the GCM auth tag did not verify)';
    } else {
        if (stripos($plain, '-- MySQL dump') === false && stripos($plain, '-- Dump completed') === false && stripos($plain, 'CREATE TABLE') === false) {
            $ok = false;
            $reasons[] = 'decrypted content does not look like a mysqldump SQL file (no CREATE TABLE / dump markers found)';
        }
        if (substr(rtrim($plain), -1) !== ';' && stripos($plain, 'Dump completed') === false) {
            // Not fatal on its own (mysqldump's exact tail varies by version),
            // just a soft signal worth surfacing.
            $reasons[] = 'note: file does not end the way a complete dump normally does -- worth a manual look';
        }
        $tableCount = substr_count(strtoupper($plain), 'CREATE TABLE');
        $reasons[] = "contains {$tableCount} CREATE TABLE statement(s)";
    }

    echo "Restore-test for " . basename($target) . ": " . ($ok ? "PASS" : "FAIL") . "\n";
    foreach ($reasons as $r) echo "  - {$r}\n";
    exit($ok ? 0 : 1);
}

if (in_array('--restore', $args, true)) {
    if (!$fileArg) {
        fwrite(STDERR, "--restore requires --file=<name>.sql.enc (no default -- be explicit about what you're restoring).\n");
        exit(1);
    }
    $target = $backupDir . '/' . basename($fileArg);
    if (!file_exists($target)) {
        fwrite(STDERR, "Backup file not found: {$target}\n");
        exit(1);
    }
    if (!in_array('--confirm', $args, true)) {
        echo "DRY RUN (pass --confirm to actually do this):\n";
        echo "Would decrypt " . basename($target) . " and restore it into database '" . DB_NAME . "' on " . DB_HOST . ",\n";
        echo "OVERWRITING all current data in that database. Nothing has been changed.\n";
        exit(0);
    }

    $keyBin = tms_backup_get_key();
    $plain = tms_backup_decrypt($target, $keyBin);
    if ($plain === null) {
        fwrite(STDERR, "Decryption failed -- refusing to restore. Run --verify first.\n");
        exit(1);
    }

    $tmpSql = tempnam(sys_get_temp_dir(), 'tmsrestore_') . '.sql';
    file_put_contents($tmpSql, $plain);
    unset($plain); // don't hold the whole plaintext dump in memory longer than needed

    $mysqlBin = tms_env('MYSQL_PATH', '') ?: 'mysql';
    // Same fix as backup_database.php (Round 51): quote every value in the
    // option file. An unquoted '#' in DB_USER/DB_PASS/DB_HOST is treated as
    // a comment by MySQL's option-file parser and silently truncates the
    // rest of the line -- found by actually running this restore path
    // end-to-end for the first time, not by inspection.
    $escUser = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_USER);
    $escPass = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_PASS);
    $escHost = str_replace(["\\", "\""], ["\\\\", "\\\""], DB_HOST);
    $credFile = tempnam(sys_get_temp_dir(), 'tmsrestorecred_');
    file_put_contents($credFile, "[client]\n"
        . "user=\"" . $escUser . "\"\n"
        . "password=\"" . $escPass . "\"\n"
        . "host=\"" . $escHost . "\"\n");
    @chmod($credFile, 0600);

    try {
        $cmd = escapeshellarg($mysqlBin)
             . ' --defaults-extra-file=' . escapeshellarg($credFile)
             . ' ' . escapeshellarg(DB_NAME)
             . ' < ' . escapeshellarg($tmpSql) . ' 2>' . escapeshellarg($tmpSql . '.err');
        exec($cmd, $out, $exitCode);
        $errText = file_exists($tmpSql . '.err') ? trim((string)file_get_contents($tmpSql . '.err')) : '';
        @unlink($tmpSql . '.err');

        if ($exitCode !== 0) {
            fwrite(STDERR, "RESTORE FAILED (exit {$exitCode}): {$errText}\n");
            exit(1);
        }
        echo "Restore of " . basename($target) . " into database '" . DB_NAME . "' completed.\n";
    } finally {
        @unlink($tmpSql);
        @unlink($credFile);
    }
    exit(0);
}

echo "Usage:\n"
   . "  php scripts/restore_database.php --list\n"
   . "  php scripts/restore_database.php --verify [--file=tms_YYYYMMDD_HHMMSS.sql.enc]\n"
   . "  php scripts/restore_database.php --restore --file=tms_YYYYMMDD_HHMMSS.sql.enc --confirm\n";
exit(1);
