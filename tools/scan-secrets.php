<?php
/**
 * Secret scanner (no dependencies). Used by CI and runnable locally:  php tools/scan-secrets.php
 * Scans the files git tracks (or, outside a git checkout, the project tree) for things that look like real
 * credentials. It NEVER prints the matched value, only file:line and the rule name.
 * Exit code 1 if anything is found. Not a replacement for rotating a key that was ever committed:
 * removing it from the latest commit does not remove it from history.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
chdir($root);

$rules = [
    'google-api-key'   => '/AIza[0-9A-Za-z_\-]{35}/',
    'openai-style-key' => '/\bsk-[A-Za-z0-9]{32,}\b/',
    'aws-access-key'   => '/\bAKIA[0-9A-Z]{16}\b/',
    'private-key-block'=> '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
    'github-token'     => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
    'slack-token'      => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
    'hex-secret-64'    => '/(?:WALLET_ENCRYPTION_KEY|APP_SECRET|JWT_SECRET)\s*[=:]\s*[\'"]?[0-9a-fA-F]{64}\b/',
];
$skipDirs = ['.git', 'vendor', 'node_modules', 'uploads', 'admin/pacakgeimages', 'images', 'fonts'];
$skipFiles = ['.env.example', 'tools/scan-secrets.php'];
$binExt = ['png','jpg','jpeg','gif','webp','ico','woff','woff2','ttf','eot','pdf','zip','gz','tar','mp4','svg'];

$files = [];
$in = @shell_exec('git ls-files 2>/dev/null');
$problems = [];
if ($in !== null && trim($in) !== '') {
    $files = array_filter(explode("\n", trim($in)));
    foreach ($files as $f) {
        if (preg_match('~(^|/)\.env$~', $f) || preg_match('~\.(pem|key|p12|pfx)$~i', $f)) $problems[] = "$f: tracked secret-bearing file";
    }
} else {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) if ($fi->isFile()) $files[] = str_replace('\\', '/', substr($fi->getPathname(), strlen($root) + 1));
}
foreach ($files as $f) {
    foreach ($skipDirs as $d) if (strpos($f, $d . '/') === 0) continue 2;
    if (in_array($f, $skipFiles, true) || $f === '.env') continue;
    if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $binExt, true)) continue;
    if (!is_file($f) || filesize($f) > 2_000_000) continue;
    $n = 0;
    $h = @fopen($f, 'r'); if (!$h) continue;
    while (($line = fgets($h)) !== false) {
        $n++;
        foreach ($rules as $name => $re) if (preg_match($re, $line)) $problems[] = "$f:$n: $name";
    }
    fclose($h);
}
if ($problems) {
    fwrite(STDERR, "Possible secrets found (values not shown):\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
echo "No secrets found in " . count($files) . " files.\n";
