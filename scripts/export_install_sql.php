<?php
/**
 * scripts/export_install_sql.php — writes ONE SQL file you can import in phpMyAdmin on any host.
 *
 *   D:\XAMPP\php\php.exe scripts/export_install_sql.php                      (catalogue data only, recommended)
 *   D:\XAMPP\php\php.exe scripts/export_install_sql.php --structure-only     (empty tables)
 *   D:\XAMPP\php\php.exe scripts/export_install_sql.php --full --i-understand   (EVERYTHING, incl. personal data)
 *   options: --out=path.sql   --max-insert-bytes=500000   --split-mb=8
 *
 * It first makes sure your local database has every table the app creates (same setup as database/migrate.php), then
 * dumps each table's CREATE statement (portable: IF NOT EXISTS, no AUTO_INCREMENT counter, MySQL-8-only collations mapped)
 * and, in catalogue mode, rows only for the public-content tables listed in scripts/lib/export_sql_lib.php.
 * Users, bookings, payments, wallet documents, tokens, chats, logs and admin accounts are NEVER exported by default.
 *
 * The pure formatting helpers are unit-tested (tests/unit/export_sql_test.php). The part that reads your database
 * (SHOW TABLES / SHOW CREATE TABLE / SELECT) has not been run against MySQL by the author: check the table counts printed
 * at the end and test-import into a throw-away database before relying on it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require_once __DIR__ . '/lib/export_sql_lib.php';

$opts = ['mode' => 'catalog', 'out' => __DIR__ . '/../database/tms_install.sql', 'insert' => 500000, 'split' => 0, 'ack' => false];
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--full') $opts['mode'] = 'full';
    elseif ($a === '--structure-only') $opts['mode'] = 'structure';
    elseif ($a === '--i-understand') $opts['ack'] = true;
    elseif (str_starts_with($a, '--out=')) $opts['out'] = substr($a, 6);
    elseif (str_starts_with($a, '--max-insert-bytes=')) $opts['insert'] = max(20000, (int)substr($a, 19));
    elseif (str_starts_with($a, '--split-mb=')) $opts['split'] = max(0, (int)round((float)substr($a, 11) * 1048576));
    elseif ($a === '--help' || $a === '-h') { echo "See the header of scripts/export_install_sql.php\n"; exit(0); }
    else { fwrite(STDERR, "Unknown option: $a\n"); exit(2); }
}

if ($opts['mode'] === 'full' && !$opts['ack']) {
    fwrite(STDERR, "\n*** --full exports ALL rows: customer names, e-mails, bookings, payment records, password hashes, 2FA data. ***\n"
        . "*** Never upload such a file to a public host unless you really mean to. Add --i-understand to continue.   ***\n\n");
    exit(2);
}

// Make sure every runtime-created table exists locally before dumping (same as running database/migrate.php).
define('TMS_RUN_SCHEMA', true);
require __DIR__ . '/../backend/config.php';     // provides $dbh (PDO) from .env, never printed
if (!isset($dbh) || !($dbh instanceof PDO)) { fwrite(STDERR, "Could not connect to the database (check .env).\n"); exit(1); }

$quote = fn(string $s): string => $dbh->quote($s);

// 1. base tables
$tables = [];
foreach ($dbh->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
    if (strcasecmp((string)$row[1], 'BASE TABLE') === 0) $tables[] = (string)$row[0];
}
if (!$tables) { fwrite(STDERR, "No tables found.\n"); exit(1); }

// 2. CREATE statements
$creates = [];
foreach ($tables as $t) {
    $r = $dbh->query('SHOW CREATE TABLE ' . tms_exp_ident($t))->fetch(PDO::FETCH_NUM);
    $creates[$t] = tms_exp_rewrite_create((string)$r[1]);
}
$ordered = tms_exp_order_tables($creates);

// 3. statements
$statements = [];
foreach ($ordered as $t) $statements[] = $creates[$t];

$rowCounts = [];
foreach ($ordered as $t) {
    if (!tms_exp_wants_data($t, $opts['mode'])) continue;
    $types = [];
    foreach ($dbh->query('SHOW COLUMNS FROM ' . tms_exp_ident($t))->fetchAll(PDO::FETCH_ASSOC) as $c) $types[$c['Field']] = (string)$c['Type'];
    $cols = array_keys($types);
    if (!$cols) continue;
    $tuples = [];
    $stmt = $dbh->query('SELECT * FROM ' . tms_exp_ident($t));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vals = [];
        foreach ($cols as $c) $vals[] = tms_exp_literal($row[$c] ?? null, $types[$c], $quote);
        $tuples[] = '(' . implode(',', $vals) . ')';
    }
    $rowCounts[$t] = count($tuples);
    foreach (tms_exp_chunk_inserts($t, $cols, $tuples, $opts['insert']) as $s) $statements[] = $s;
}

// 4. write
$parts = tms_exp_split_parts($statements, $opts['split']);
$n = count($parts);
$dir = dirname($opts['out']);
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$title = 'TourBuddy install file (' . $opts['mode'] . ' mode) generated ' . gmdate('Y-m-d H:i') . ' UTC, ' . count($tables) . ' tables';
$total = 0; $files = [];
foreach ($parts as $i => $stmts) {
    $path = tms_exp_part_path($opts['out'], $i + 1, $n);
    $body = tms_exp_header($title, $n > 1 ? $i + 1 : null, $n > 1 ? $n : null) . implode("\n\n", $stmts) . "\n" . tms_exp_footer();
    if (file_put_contents($path, $body) === false) { fwrite(STDERR, "Could not write $path\n"); exit(1); }
    $total += strlen($body); $files[] = [$path, strlen($body)];
}

echo "Mode: {$opts['mode']}   Tables: " . count($tables) . "   Tables with rows exported: " . count($rowCounts) . "\n";
foreach ($rowCounts as $t => $c) echo sprintf("  %-34s %6d rows\n", $t, $c);
foreach ($files as [$p, $sz]) echo sprintf("Wrote %s (%.2f MB)\n", realpath($p) ?: $p, $sz / 1048576);
if ($n === 1 && $total > 8 * 1048576) echo "WARNING: file is over 8 MB. InfinityFree's phpMyAdmin may refuse it: re-run with --split-mb=6\n";
if ($opts['mode'] === 'full') echo "WARNING: this file contains personal data and password hashes. Keep it private and delete it after importing.\n";
echo "Next: import " . ($n > 1 ? "the parts in numeric order" : "the file") . " in phpMyAdmin (see DEPLOY_INFINITYFREE.md).\n";
