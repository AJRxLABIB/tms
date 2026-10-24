<?php
/**
 * scripts/lib/export_sql_lib.php — pure helpers for scripts/export_install_sql.php (no database needed, unit-tested
 * in tests/unit/export_sql_test.php). Nothing here talks to MySQL except the optional $quote callable the caller passes.
 */

/**
 * Tables whose ROWS are exported in the default 'catalog' mode: public catalogue and content only.
 * Everything else is exported as structure only (no personal or operational data leaves your machine).
 * Names come from the CREATE TABLE statements in backend/config.php and database/migrations.
 */
const TMS_EXPORT_CATALOG_TABLES = [
    'tbltourpackages',            // tour packages (image paths are kept as-is, upload the images separately)
    'tblpackageaddons',           // package add-ons
    'tblactivities',              // activities catalogue
    'tblhotels',                  // hotels catalogue
    'tblblog',                    // blog posts
    'tblpages',                   // About / Contact / Privacy pages
    'tblemergencycontacts',       // emergency numbers per country
    'tbl_bd_destination_guides',  // curated Bangladesh destination guides
    'tbl_travel_notices',         // admin-entered, sourced travel notices
    'tbl_map_places',             // map places (only verified/admin/OSM records, no users)
    'tbl_map_place_aliases',
    'tbl_map_place_images',
    'tblmigrations',              // so database/migrate.php sees every migration as applied
];

/** Tables never exported with data, not even in --full (runtime fingerprint must be rebuilt on the new host). */
const TMS_EXPORT_NEVER_DATA = ['tbl_app_meta'];

/** `name` with backticks escaped. */
function tms_exp_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** True for column types that must be written as hex (0x...) rather than a quoted string. */
function tms_exp_is_binary_type(string $type): bool
{
    return (bool)preg_match('/^(tiny|medium|long)?blob\b|^(var)?binary\b|^bit\b/i', trim($type));
}

/** True for column types where a numeric-looking value may be written unquoted. */
function tms_exp_is_numeric_type(string $type): bool
{
    return (bool)preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real)\b/i', trim($type));
}

/**
 * One value as an SQL literal.
 * @param mixed    $v     value as returned by PDO (string|int|float|null)
 * @param string   $type  column type from SHOW COLUMNS, e.g. 'varchar(20)', 'blob', 'int(11) unsigned'
 * @param callable $quote function(string): string producing a quoted+escaped literal (PDO::quote)
 */
function tms_exp_literal($v, string $type, callable $quote): string
{
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v) || is_float($v)) return (string)$v;
    $s = (string)$v;
    if (tms_exp_is_binary_type($type)) return $s === '' ? "''" : '0x' . bin2hex($s);
    if (tms_exp_is_numeric_type($type) && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $s)) return $s;
    return $quote($s);
}

/** Map collations/charsets that older MySQL and many MariaDB versions do not know to widely supported ones. */
function tms_exp_map_collation(string $sql): string
{
    $sql = preg_replace('/utf8mb4_0900_\w+/i', 'utf8mb4_unicode_ci', $sql);
    $sql = preg_replace('/utf8mb4_uca1400_\w+/i', 'utf8mb4_unicode_ci', $sql);
    $sql = preg_replace('/utf8mb3_(\w+)/i', 'utf8_$1', $sql);
    $sql = preg_replace('/\butf8mb3\b/i', 'utf8', $sql);
    return $sql;
}

/** Make a SHOW CREATE TABLE statement safe to import on another server (re-runnable, portable). */
function tms_exp_rewrite_create(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('/^CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $sql);
    $sql = preg_replace('/\s+AUTO_INCREMENT\s*=\s*\d+/i', '', $sql);
    $sql = preg_replace('/\/\*!\d+\s+DEFINER\s*=[^*]*\*\//i', '', $sql);
    $sql = tms_exp_map_collation($sql);
    return rtrim($sql, ';') . ';';
}

/** Tables referenced by FOREIGN KEY ... REFERENCES `t` inside a CREATE TABLE statement. */
function tms_exp_fk_targets(string $createSql): array
{
    preg_match_all('/REFERENCES\s+`([^`]+)`/i', $createSql, $m);
    return array_values(array_unique($m[1] ?? []));
}

/**
 * Order tables so a referenced table comes before the table that references it. Cycles and self-references fall back
 * to alphabetical order (the file also sets FOREIGN_KEY_CHECKS=0, so the order is a courtesy, not a requirement).
 * @param array<string,string> $creates table => CREATE statement
 * @return string[]
 */
function tms_exp_order_tables(array $creates): array
{
    $names = array_keys($creates);
    sort($names, SORT_STRING);
    $deps = [];
    foreach ($names as $n) {
        $deps[$n] = array_values(array_filter(tms_exp_fk_targets($creates[$n]), fn($t) => $t !== $n && isset($creates[$t])));
    }
    $out = []; $state = [];
    $visit = function (string $n) use (&$visit, &$out, &$state, $deps): void {
        if (($state[$n] ?? 0) !== 0) return;      // 1 = in progress (cycle) / 2 = done
        $state[$n] = 1;
        foreach ($deps[$n] as $d) $visit($d);
        $state[$n] = 2;
        $out[] = $n;
    };
    foreach ($names as $n) $visit($n);
    return $out;
}

/** Should this table's rows be exported in this mode? @param string $mode catalog|full|structure */
function tms_exp_wants_data(string $table, string $mode, ?array $allow = null): bool
{
    $t = strtolower($table);
    if (in_array($t, TMS_EXPORT_NEVER_DATA, true)) return false;
    if ($mode === 'structure') return false;
    if ($mode === 'full') return true;
    return in_array($t, array_map('strtolower', $allow ?? TMS_EXPORT_CATALOG_TABLES), true);
}

/**
 * Build multi-row INSERT IGNORE statements, each at most about $maxBytes long (a single oversized row still gets its own
 * statement). INSERT IGNORE keeps a second import of the same file from failing on duplicate keys.
 * @param string[] $columns
 * @param string[] $tuples  already-rendered "(v1,v2,...)" strings
 * @return string[]
 */
function tms_exp_chunk_inserts(string $table, array $columns, array $tuples, int $maxBytes = 500000): array
{
    if (!$tuples) return [];
    $head = 'INSERT IGNORE INTO ' . tms_exp_ident($table) . ' (' . implode(',', array_map('tms_exp_ident', $columns)) . ") VALUES\n";
    $stmts = []; $cur = []; $size = strlen($head);
    foreach ($tuples as $t) {
        $len = strlen($t) + 2;
        if ($cur && $size + $len > $maxBytes) {
            $stmts[] = $head . implode(",\n", $cur) . ';';
            $cur = []; $size = strlen($head);
        }
        $cur[] = $t; $size += $len;
    }
    if ($cur) $stmts[] = $head . implode(",\n", $cur) . ';';
    return $stmts;
}

/** File prologue for every output file. */
function tms_exp_header(string $title, ?int $part = null, ?int $parts = null): string
{
    $h = "-- " . $title . "\n";
    if ($part !== null) $h .= "-- PART {$part}" . ($parts ? " OF {$parts}" : '') . " -- import the parts in numeric order\n";
    $h .= "-- Import with phpMyAdmin (Import tab) into an EMPTY database. Safe to re-run: tables use IF NOT EXISTS, rows use INSERT IGNORE.\n";
    $h .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n";
    return $h;
}

function tms_exp_footer(): string
{
    return "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

/**
 * Group whole statements into parts no bigger than $maxBytes (plus header/footer). $maxBytes <= 0 means one part.
 * A statement larger than the limit still goes into a part of its own.
 * @param string[] $statements
 * @return string[][] list of statement lists
 */
function tms_exp_split_parts(array $statements, int $maxBytes): array
{
    if ($maxBytes <= 0) return [$statements];
    $parts = []; $cur = []; $size = 0;
    foreach ($statements as $s) {
        $len = strlen($s) + 2;
        if ($cur && $size + $len > $maxBytes) { $parts[] = $cur; $cur = []; $size = 0; }
        $cur[] = $s; $size += $len;
    }
    if ($cur) $parts[] = $cur;
    return $parts ?: [[]];
}

/** Output file name for part $i of $n (1-based) given the base path ending in .sql. */
function tms_exp_part_path(string $basePath, int $i, int $n): string
{
    if ($n <= 1) return $basePath;
    return preg_replace('/\.sql$/i', '', $basePath) . sprintf('_part%02d.sql', $i);
}
