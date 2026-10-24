<?php
/**
 * scripts/make_deploy_package.php — copies only what production needs into deploy/htdocs/ (never .env, never uploads).
 *
 *   D:\XAMPP\php\php.exe scripts/make_deploy_package.php
 *
 * Then upload the CONTENTS of deploy/htdocs/ directly into the host's htdocs folder (index.php must end up at
 * htdocs/index.php, not htdocs/tms/index.php). Safe to re-run: deploy/htdocs is rebuilt from scratch.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require_once __DIR__ . '/lib/deploy_lib.php';

$root = realpath(__DIR__ . '/..');
$dest = $root . DIRECTORY_SEPARATOR . 'deploy' . DIRECTORY_SEPARATOR . 'htdocs';
if ($root === false) { fwrite(STDERR, "Cannot resolve project root.\n"); exit(1); }

function tms_rmtree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

tms_rmtree($dest);
if (!@mkdir($dest, 0775, true) && !is_dir($dest)) { fwrite(STDERR, "Cannot create $dest\n"); exit(1); }

$files = 0; $bytes = 0; $skipped = 0;
$walk = function (string $abs, string $rel) use (&$walk, $dest, &$files, &$bytes, &$skipped): void {
    foreach (scandir($abs) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $a = $abs . DIRECTORY_SEPARATOR . $name;
        $r = ($rel === '' ? '' : $rel . '/') . $name;
        $isDir = is_dir($a) && !is_link($a);
        if (tms_deploy_excluded($r, $isDir)) { $skipped++; continue; }
        $target = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $r);
        if ($isDir) {
            if (!is_dir($target)) @mkdir($target, 0775, true);
            $walk($a, $r);
        } elseif (is_file($a)) {
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0775, true);
            if (@copy($a, $target)) { $files++; $bytes += (int)filesize($a); }
            else fwrite(STDERR, "Could not copy $r\n");
        }
    }
};
$walk($root, '');

// make sure the upload folders exist even if empty
foreach (['uploads', 'uploads/places', 'uploads/reviews', 'uploads/wallet'] as $d) {
    $p = $dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $d);
    if (!is_dir($p)) @mkdir($p, 0775, true);
    if (!is_file($p . DIRECTORY_SEPARATOR . '.htaccess') && !is_file($p . DIRECTORY_SEPARATOR . '.gitkeep')) @file_put_contents($p . DIRECTORY_SEPARATOR . 'index.html', '');
}

// sanity checks
$ok = true;
foreach (['index.php', '.htaccess', 'backend/config.php'] as $must) {
    if (!is_file($dest . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $must))) { fwrite(STDERR, "MISSING in package: $must\n"); $ok = false; }
}
if (is_file($dest . DIRECTORY_SEPARATOR . '.env')) { fwrite(STDERR, "REFUSING: .env ended up in the package.\n"); tms_rmtree($dest); exit(1); }

echo "Package folder: $dest\nFiles: $files   Size: " . tms_deploy_human($bytes) . "   Left out: $skipped paths\n";
echo "\nUpload the CONTENTS of that folder straight into the host's htdocs folder (index.php must be at htdocs/index.php).\n";
echo "Do not upload a .env from this machine. Create one on the server from .env.infinityfree.example.\n";
exit($ok ? 0 : 1);
