<?php
/**
 * database/migrate.php — ordered, tracked SQL migration runner (Rule 39).
 *
 * WHY THIS EXISTS: before this, every schema change in this project (tms.sql,
 * v6_migration.sql, AI_MIGRATION.sql, explore_migration.sql,
 * schema_repair_migration.sql) was a plain .sql file you had to remember to
 * run by hand in phpMyAdmin, in the right order, exactly once. Nothing
 * tracked which ones had already been applied. This script replaces that
 * with: drop a numbered file in database/migrations/, run this script, and
 * it applies only what hasn't been applied yet, in filename order, and
 * records what it did in the new `tblmigrations` table.
 *
 * SAFETY:
 *  - Every statement is executed individually (not as one giant multi-query
 *    blob), so one bad statement doesn't silently skip the rest.
 *  - "Already exists" style errors (duplicate column/key/table, MySQL error
 *    codes 1060/1061/1050/1091) are caught and reported as warnings, not
 *    fatal errors, because every migration in this project is written to be
 *    safe to re-run (CREATE TABLE IF NOT EXISTS, additive ALTER TABLE).
 *  - Nothing is ever DROPped or overwritten by this runner itself — that is
 *    left to the individual migration files, and none of the ones shipped
 *    with this project do that (Rule 8: never casually break the database).
 *  - Can run from the command line (`php database/migrate.php`) or from a
 *    browser, but a browser run requires an authenticated super-admin admin
 *    session — running arbitrary DDL from an unauthenticated web request
 *    would be a serious security hole (Rule 44/46), so this refuses unless
 *    one of those two conditions is met.
 *
 * USAGE:
 *   php database/migrate.php            # apply all pending migrations
 *   php database/migrate.php --status   # show what's applied / pending, do nothing
 */

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Reuses the project's own existing RBAC helper (includes/config.php)
    // instead of inventing a parallel admin-check -- tms_admin_require_role()
    // already handles session start, the "not logged in" redirect, and the
    // 403 page for a logged-in admin who isn't a superadmin.
    require __DIR__ . '/../includes/config.php';
    tms_admin_require_role($dbh, ['superadmin']);
} else {
    require __DIR__ . '/../includes/config.php'; // gives us $dbh (PDO) via the app's own DB config
}

$statusOnly = in_array('--status', $argv ?? [], true) || isset($_GET['status']);

function tms_migration_error_is_benign(PDOException $e): bool
{
    $benignCodes = ['1050', '1060', '1061', '1091'];
    $sqlState = $e->errorInfo[1] ?? null;
    return $sqlState !== null && in_array((string)$sqlState, $benignCodes, true);
}

function tms_split_sql_statements(string $sql): array
{
    $lines = explode("\n", $sql);
    $lines = array_filter($lines, fn($l) => !preg_match('/^\s*--/', $l));
    $sql = implode("\n", $lines);
    $parts = array_filter(array_map('trim', explode(";", $sql)));
    return array_values($parts);
}

echo ($isCli ? "" : "<pre>") . "TMS migration runner\n=====================\n";

try {
    $dbh->exec(
        "CREATE TABLE IF NOT EXISTS `tblmigrations` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(190) NOT NULL,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `checksum` CHAR(64) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1"
    );
} catch (PDOException $e) {
    die("FATAL: could not create/verify tblmigrations: " . $e->getMessage() . "\n");
}

$already = (int)$dbh->query("SELECT COUNT(*) FROM tblmigrations")->fetchColumn();
if ($already === 0) {
    $legacyBaseline = [
        '000_baseline_tms.sql',
        '000_baseline_v6_migration.sql',
        '000_baseline_AI_MIGRATION.sql',
        '000_baseline_explore_migration.sql',
        '000_baseline_schema_repair_migration.sql',
    ];
    $ins = $dbh->prepare("INSERT INTO tblmigrations(migration, checksum) VALUES(:m, 'baseline-preexisting')");
    foreach ($legacyBaseline as $name) {
        $ins->execute([':m' => $name]);
    }
    echo "Seeded baseline: recorded " . count($legacyBaseline) . " pre-existing legacy migrations as already applied.\n\n";
}

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql');
sort($files, SORT_STRING);

$appliedStmt = $dbh->prepare("SELECT 1 FROM tblmigrations WHERE migration = :m");
$recordStmt = $dbh->prepare("INSERT INTO tblmigrations(migration, checksum) VALUES(:m, :c)");

$pendingCount = 0;
foreach ($files as $file) {
    $name = basename($file);
    $appliedStmt->execute([':m' => $name]);
    $isApplied = (bool)$appliedStmt->fetchColumn();

    if ($isApplied) {
        echo "[applied]  {$name}\n";
        continue;
    }

    $pendingCount++;
    echo "[pending]  {$name}" . ($statusOnly ? "\n" : " -- applying now:\n");
    if ($statusOnly) {
        continue;
    }

    $sql = file_get_contents($file);
    $statements = tms_split_sql_statements($sql);
    $okCount = 0;
    $warnCount = 0;
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try {
            $dbh->exec($stmt);
            $okCount++;
        } catch (PDOException $e) {
            if (tms_migration_error_is_benign($e)) {
                $warnCount++;
                echo "    (already applied) " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "...\n";
            } else {
                echo "    FAILED: " . $e->getMessage() . "\n    Statement: " . substr($stmt, 0, 200) . "\n";
                die("\nMigration {$name} failed on a non-benign error. Stopping here so later migrations don't run against an inconsistent schema. Fix the issue above and re-run.\n");
            }
        }
    }
    $recordStmt->execute([':m' => $name, ':c' => hash('sha256', $sql)]);
    echo "    -> recorded as applied ({$okCount} statement(s) run, {$warnCount} already present)\n";
}

if ($pendingCount === 0) {
    echo "\nNothing to do -- database schema is up to date.\n";
} elseif ($statusOnly) {
    echo "\n{$pendingCount} migration(s) pending. Re-run without --status to apply.\n";
} else {
    echo "\nDone.\n";
}
echo ($isCli ? "" : "</pre>");
