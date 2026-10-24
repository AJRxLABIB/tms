<?php
/**
 * scripts/lib/deploy_lib.php — pure path rules for scripts/make_deploy_package.php (unit-tested, no filesystem access).
 */

/** Top-level folders never copied to production. */
const TMS_DEPLOY_EXCLUDE_DIRS = ['.git', '.github', '.vscode', '.idea', 'tests', 'tools', '_deprecated', 'deploy', 'node_modules', 'vendor-dev'];

/** Top-level markdown/doc files that ARE copied (everything else *.md and the planning/audit documents are not). */
const TMS_DEPLOY_KEEP_DOCS = ['README.md', 'DEPLOYMENT.md', 'DEPLOY_INFINITYFREE.md'];

/**
 * Should this relative path (forward slashes, no leading slash) be left out of the production package?
 * @param bool $isDir true when $rel is a directory
 */
function tms_deploy_excluded(string $rel, bool $isDir): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return false;
    $parts = explode('/', $rel);
    $base = end($parts);
    $top = $parts[0];

    if (in_array($top, TMS_DEPLOY_EXCLUDE_DIRS, true)) return true;
    foreach ($parts as $p) { if ($p === 'node_modules' || $p === '.git') return true; }

    // secrets and local config: never packaged (the .env.*.example templates carry no secrets and are kept)
    if (preg_match('/^\.env($|\.(?!.*example$))/i', $base)) return true;

    // database folder: keep migrations + the runner, drop legacy dumps, zips and generated install files
    if ($top === 'database') {
        if (isset($parts[1]) && $parts[1] === 'legacy-sql') return true;
        if (!$isDir && preg_match('/\.(zip|sql)$/i', $base) && !str_starts_with($rel, 'database/migrations/')) return true;
    }

    // uploads: keep the directories and their protection files, never user content
    if ($top === 'uploads') {
        if ($isDir) return false;
        return !in_array($base, ['.htaccess', '.gitkeep', 'index.html'], true);
    }
    // scripts/ci is CI-only
    if ($top === 'scripts' && ($parts[1] ?? '') === 'ci') return true;
    if ($isDir) return false;

    // root-level files
    if (count($parts) === 1) {
        if (preg_match('/\.(md|markdown)$/i', $base)) return !in_array($base, TMS_DEPLOY_KEEP_DOCS, true);
        if (preg_match('/\.(sql|zip|log|bak|old|orig|txt)$/i', $base) && $base !== 'robots.txt') return true;
        if ($base === 'INSTALL.html') return true;
    }

    if (preg_match('/\.(log|bak|old|orig|swp|tmp|zip|tar|gz)$/i', $base)) return true;
    if (in_array($base, ['Thumbs.db', '.DS_Store'], true)) return true;
    if (preg_match('/^tmp-/i', $base)) return true;
    return false;
}

/** "12.3 MB" style size. */
function tms_deploy_human(int $bytes): string
{
    if ($bytes >= 1048576) return sprintf('%.1f MB', $bytes / 1048576);
    if ($bytes >= 1024) return sprintf('%.0f KB', $bytes / 1024);
    return $bytes . ' B';
}
