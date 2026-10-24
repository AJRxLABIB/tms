<?php
/**
 * scripts/check_env.php — deployment configuration check. Never prints a value, only whether each setting is sound.
 *   php scripts/check_env.php                 (reads .env, then the process environment)
 *   APP_ENV=production php scripts/check_env.php
 * Exit code 1 if any check FAILs (so it can gate a deploy), 0 otherwise. Needs no database connection.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../backend/env-loader.php';
require_once __DIR__ . '/../backend/EnvCheck.php';
if (function_exists('tms_load_dotenv')) tms_load_dotenv(__DIR__ . '/../.env');

$results = EnvCheck::run(EnvCheck::fromEnvironment());
foreach ($results as $r) {
    echo str_pad($r['level'], 5) . ' ' . str_pad($r['key'], 28) . ' ' . $r['message'] . "\n";
}
$s = EnvCheck::summarize($results);
echo "\n{$s['pass']} ok, {$s['warn']} warning(s), {$s['fail']} failure(s).\n";
exit($s['fail'] > 0 ? 1 : 0);
