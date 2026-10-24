<?php
/**
 * Pure tests for scripts/lib/export_sql_lib.php and scripts/lib/deploy_lib.php (no database, no filesystem).
 *   php tests/unit/export_sql_test.php
 * NOT covered: the code in scripts/export_install_sql.php that reads a real MySQL database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../scripts/lib/export_sql_lib.php';
require_once __DIR__ . '/../../scripts/lib/deploy_lib.php';

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $n . ($ok ? '' : "  $d") . "\n"; }

$q = fn(string $s): string => "'" . str_replace(["\\", "'", "\n", "\r", "\0"], ["\\\\", "\\'", "\\n", "\\r", "\\0"], $s) . "'";

// identifiers
t('ident quotes', tms_exp_ident('tbl') === '`tbl`');
t('ident escapes backtick', tms_exp_ident('a`b') === '`a``b`');

// literals
t('NULL', tms_exp_literal(null, 'varchar(10)', $q) === 'NULL');
t('int type numeric string unquoted', tms_exp_literal('42', 'int(11)', $q) === '42');
t('negative decimal unquoted', tms_exp_literal('-3.50', 'decimal(10,2)', $q) === '-3.50');
t('varchar digits stay quoted (phone numbers keep leading zeros)', tms_exp_literal('01712345678', 'varchar(20)', $q) === "'01712345678'");
t('int type but garbage value is quoted, not injected', tms_exp_literal('1; DROP TABLE x', 'int(11)', $q) === "'1; DROP TABLE x'");
t('string with quote escaped', tms_exp_literal("O'Brien", 'varchar(50)', $q) === "'O\\'Brien'");
t('newline escaped', tms_exp_literal("a\nb", 'text', $q) === "'a\\nb'");
t('blob as hex', tms_exp_literal("\x00\xFF", 'blob', $q) === '0x00ff');
t('empty blob', tms_exp_literal('', 'longblob', $q) === "''");
t('varbinary hex', tms_exp_literal('AB', 'varbinary(16)', $q) === '0x4142');
t('bangla text quoted untouched', tms_exp_literal('কক্সবাজার', 'varchar(100)', $q) === "'কক্সবাজার'");
t('int PHP value', tms_exp_literal(7, 'int', $q) === '7');
t('binary type detect', tms_exp_is_binary_type('mediumblob') && tms_exp_is_binary_type('binary(16)') && !tms_exp_is_binary_type('varchar(5)') && !tms_exp_is_binary_type('text'));

// collation mapping / create rewriting
t('0900 collation mapped', tms_exp_map_collation('COLLATE utf8mb4_0900_ai_ci') === 'COLLATE utf8mb4_unicode_ci');
t('uca1400 collation mapped', tms_exp_map_collation('COLLATE=utf8mb4_uca1400_ai_ci') === 'COLLATE=utf8mb4_unicode_ci');
t('utf8mb3 mapped', tms_exp_map_collation('CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci') === 'CHARSET=utf8 COLLATE=utf8_general_ci');
t('utf8mb4_unicode_ci untouched', tms_exp_map_collation('COLLATE utf8mb4_unicode_ci') === 'COLLATE utf8mb4_unicode_ci');
$create = "CREATE TABLE `tblx` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `n` varchar(10) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `fk1` FOREIGN KEY (`n`) REFERENCES `tbly` (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=4521 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
$r = tms_exp_rewrite_create($create);
t('rewrite adds IF NOT EXISTS', str_starts_with($r, 'CREATE TABLE IF NOT EXISTS `tblx`'));
t('rewrite keeps column AUTO_INCREMENT', str_contains($r, '`id` int(11) NOT NULL AUTO_INCREMENT,'));
t('rewrite strips table AUTO_INCREMENT=N', !preg_match('/AUTO_INCREMENT=\d+/', $r));
t('rewrite maps collations', !str_contains($r, '0900'));
t('rewrite ends with one semicolon', str_ends_with($r, ';') && !str_ends_with($r, ';;'));
t('rewrite is idempotent', tms_exp_rewrite_create($r) === $r);
t('rewrite handles existing IF NOT EXISTS', str_starts_with(tms_exp_rewrite_create('CREATE TABLE IF NOT EXISTS `a` (`x` int)'), 'CREATE TABLE IF NOT EXISTS `a`'));
t('fk targets', tms_exp_fk_targets($create) === ['tbly']);

// ordering
$set = ['child' => 'CREATE TABLE `child` (a int, CONSTRAINT f FOREIGN KEY (a) REFERENCES `parent` (id))',
        'parent' => 'CREATE TABLE `parent` (id int)',
        'alone' => 'CREATE TABLE `alone` (id int)',
        'grand' => 'CREATE TABLE `grand` (a int, CONSTRAINT g FOREIGN KEY (a) REFERENCES `child` (id))'];
$o = tms_exp_order_tables($set);
t('parent before child before grand', array_search('parent', $o) < array_search('child', $o) && array_search('child', $o) < array_search('grand', $o));
t('all tables present once', count($o) === 4 && count(array_unique($o)) === 4);
$cyc = ['a' => 'CREATE TABLE `a` (x int, FOREIGN KEY (x) REFERENCES `b` (id))', 'b' => 'CREATE TABLE `b` (x int, FOREIGN KEY (x) REFERENCES `a` (id))', 's' => 'CREATE TABLE `s` (x int, FOREIGN KEY (x) REFERENCES `s` (id))', 'u' => 'CREATE TABLE `u` (x int, FOREIGN KEY (x) REFERENCES `missing` (id))'];
$oc = tms_exp_order_tables($cyc);
t('cycle, self reference and unknown target do not hang or drop tables', count($oc) === 4);

// allow-list filtering
t('catalog: packages yes', tms_exp_wants_data('tbltourpackages', 'catalog'));
t('catalog: case-insensitive', tms_exp_wants_data('TBLTOURPACKAGES', 'catalog'));
foreach (['tblusers', 'tblbooking', 'tblpayments', 'tbltraveldocuments', 'admin', 'tblapi_tokens', 'tbl_password_resets', 'tblchat_messages', 'tblenquiry', 'tbl_notifications', 'tbl_fraud_cases', 'tbl_map_itineraries', 'tbl_login_attempts', 'tbl_suppliers', 'tblcoupons'] as $p) {
    t("catalog: personal/operational table $p has NO data", !tms_exp_wants_data($p, 'catalog'));
}
t('structure mode: nothing', !tms_exp_wants_data('tbltourpackages', 'structure'));
t('full mode: users included', tms_exp_wants_data('tblusers', 'full'));
t('app_meta fingerprint never exported, even in full', !tms_exp_wants_data('tbl_app_meta', 'full') && !tms_exp_wants_data('tbl_app_meta', 'catalog'));
t('migrations table exported in catalog mode', tms_exp_wants_data('tblmigrations', 'catalog'));
t('allow-list has no personal-data table names', !array_intersect(TMS_EXPORT_CATALOG_TABLES, ['tblusers', 'tblbooking', 'tblpayments', 'admin', 'tbltraveldocuments']));

// insert chunking
$tuples = [];
for ($i = 0; $i < 100; $i++) $tuples[] = "($i,'" . str_repeat('x', 50) . "')";
$ch = tms_exp_chunk_inserts('t', ['id', 'v'], $tuples, 1000);
t('chunking splits big sets', count($ch) > 1);
t('every chunk within limit', max(array_map('strlen', $ch)) <= 1100);
t('every chunk is INSERT IGNORE with columns', !array_filter($ch, fn($s) => !str_starts_with($s, 'INSERT IGNORE INTO `t` (`id`,`v`) VALUES')));
t('every chunk ends with semicolon', !array_filter($ch, fn($s) => !str_ends_with($s, ';')));
t('all rows preserved in order', substr_count(implode("\n", $ch), "'xxxxxxxxxx") === 100 && str_contains($ch[0], '(0,'));
t('empty tuple list gives no statement', tms_exp_chunk_inserts('t', ['id'], []) === []);
$one = tms_exp_chunk_inserts('t', ['v'], ["('" . str_repeat('y', 5000) . "')"], 100);
t('oversized single row still emitted', count($one) === 1);

// part splitting
$stm = array_map(fn($i) => str_repeat('s', 400) . $i . ';', range(1, 10));
$parts = tms_exp_split_parts($stm, 1000);
t('split into several parts', count($parts) > 1);
t('statements never split across parts and order preserved', array_merge(...$parts) === $stm);
t('split disabled -> one part', count(tms_exp_split_parts($stm, 0)) === 1);
t('part path single', tms_exp_part_path('/x/tms_install.sql', 1, 1) === '/x/tms_install.sql');
t('part path numbered', tms_exp_part_path('/x/tms_install.sql', 3, 12) === '/x/tms_install_part03.sql');
$h = tms_exp_header('T', 2, 3);
t('header has utf8mb4, FK off, part note', str_contains($h, 'SET NAMES utf8mb4') && str_contains($h, 'FOREIGN_KEY_CHECKS=0') && str_contains($h, 'PART 2 OF 3'));
t('footer re-enables FK', str_contains(tms_exp_footer(), 'FOREIGN_KEY_CHECKS=1'));

// deploy exclusions
$ex = fn(string $p, bool $d = false) => tms_deploy_excluded($p, $d);
foreach (['.env', '.env.local', '.git', '.git/config', 'tests', 'tests/run.php', 'tools/create-admin.php', '_deprecated/x.php', 'deploy/htdocs/index.php', 'node_modules/a/b.js',
          'database/legacy-sql/tms.sql', 'database/sql.zip', 'database/tms_install.sql', 'database/tms_install_part01.sql', 'CHANGELOG.md', 'SESSION_SUMMARY_INDEX-1.md', 'AUDIT_REPORT_ROUND3.md',
          'AI_MIGRATION.sql', 'v6_migration.sql', 'uploads/wallet/secret.pdf', 'uploads/reviews/a.jpg', 'uploads/places/p.webp', 'error.log', 'scripts/ci/pure_tests.sh', 'INSTALL.html', 'backend/tmp-round115-test-runner-browsercheck.php', 'Thumbs.db', 'x/backup.bak'] as $p) {
    t("excluded: $p", $ex($p, false), 'should be excluded');
}
foreach (['index.php', '.htaccess', 'backend/config.php', 'backend/.htaccess', 'frontend/tour-map.php', 'js/vendor/maplibre/maplibre-gl-csp.js', 'database/migrate.php', 'database/migrations/041_map_places.sql',
          'uploads/.htaccess', 'uploads/places/.htaccess', 'README.md', 'DEPLOYMENT.md', 'DEPLOY_INFINITYFREE.md', '.env.infinityfree.example', '.env.example', 'robots.txt', 'scripts/check_env.php', 'scripts/lib/deploy_lib.php', 'images/logo.png', 'data/.htaccess', 'manifest.json', 'sw.js'] as $p) {
    t("kept: $p", !$ex($p, false), 'should be kept');
}
t('dirs kept: uploads/places', !$ex('uploads/places', true));
t('dirs excluded: tests', $ex('tests', true));
t('dirs excluded: scripts/ci', $ex('scripts/ci', true));
t('windows separators handled', $ex('tests\\ai\\run_eval.php', false));
t('human size', tms_deploy_human(1536) === '2 KB' && tms_deploy_human(5 * 1048576) === '5.0 MB' && tms_deploy_human(12) === '12 B');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
