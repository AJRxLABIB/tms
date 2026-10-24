<?php
/**
 * ModelRegistry — which models the local Ollama runtime actually has installed.
 *
 * Asks `GET {base}/api/tags` (short timeout), keeps the answer for a few seconds, and is safe when the runtime is
 * down: list() returns [] and reachable() is false, nothing throws. The result is also kept in tbl_app_meta so
 * separate PHP requests do not each hit the runtime (a negative result is cached even more briefly).
 *
 * It records only model name, digest, size and parameter size — never prompts or answers.
 *
 * Test seam: pass a $fetch callable fn(string $url, int $timeoutSec): array{0:int,1:string} returning [httpCode, body].
 */
class ModelRegistry
{
    private string $baseUrl;
    private $fetch;
    private ?PDO $db;
    private int $ttl;
    private int $negativeTtl;
    /** @var array<string,array>|null */
    private ?array $models = null;
    private bool $reachable = false;
    private int $loadedAt = 0;

    public function __construct(string $baseUrl = 'http://127.0.0.1:11434', ?callable $fetch = null, ?PDO $db = null, int $ttl = 30, int $negativeTtl = 10)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->fetch = $fetch;
        $this->db = $db;
        $this->ttl = max(1, $ttl);
        $this->negativeTtl = max(1, $negativeTtl);
    }

    /** @return array<string,array{name:string,digest:string,size:int,parameter_size:string,family:string,modified:string}> keyed by lower-case name */
    public function list(): array
    {
        $this->load();
        return $this->models ?? [];
    }

    public function reachable(): bool { $this->load(); return $this->reachable; }

    public function isAvailable(string $model): bool
    {
        return $this->info($model) !== null;
    }

    /** Info for one model; "name" matches "name:latest". Null when not installed or runtime unreachable. */
    public function info(string $model): ?array
    {
        $m = $this->list();
        foreach (self::variants($model) as $v) {
            if (isset($m[$v])) return $m[$v];
        }
        return null;
    }

    /** Same as info() but never touches the network or database: only what this request already loaded. For hot paths. */
    public function infoIfLoaded(string $model): ?array
    {
        if ($this->models === null) return null;
        foreach (self::variants($model) as $v) {
            if (isset($this->models[$v])) return $this->models[$v];
        }
        return null;
    }

    public static function variants(string $model): array
    {
        $m = strtolower(trim($model));
        if ($m === '') return [];
        return str_contains($m, ':') ? array_values(array_unique([$m, preg_replace('/:latest$/', '', $m)])) : [$m, $m . ':latest'];
    }

    /** Parses an /api/tags body. Pure, so it is unit-tested. */
    public static function parseTags(string $body): ?array
    {
        $d = json_decode($body, true);
        if (!is_array($d) || !isset($d['models']) || !is_array($d['models'])) return null;
        $out = [];
        foreach ($d['models'] as $row) {
            if (!is_array($row)) continue;
            $name = (string)($row['name'] ?? $row['model'] ?? '');
            if ($name === '') continue;
            $det = is_array($row['details'] ?? null) ? $row['details'] : [];
            $out[strtolower($name)] = [
                'name'           => $name,
                'digest'         => substr((string)($row['digest'] ?? ''), 0, 64),
                'size'           => (int)($row['size'] ?? 0),
                'parameter_size' => (string)($det['parameter_size'] ?? ''),
                'family'         => (string)($det['family'] ?? ''),
                'modified'       => (string)($row['modified_at'] ?? ''),
            ];
        }
        return $out;
    }

    private function load(): void
    {
        $now = time();
        if ($this->models !== null && ($now - $this->loadedAt) < ($this->reachable ? $this->ttl : $this->negativeTtl)) return;

        // Shared short-lived cache across requests.
        $cached = $this->readShared($now);
        if ($cached !== null) {
            [$this->reachable, $this->models] = $cached;
            $this->loadedAt = $now;
            return;
        }

        $parsed = null;
        try {
            [$code, $body] = $this->http($this->baseUrl . '/api/tags', 2);
            if ($code === 200) $parsed = self::parseTags($body);
        } catch (\Throwable $e) { $parsed = null; }

        $this->reachable = $parsed !== null;
        $this->models = $parsed ?? [];
        $this->loadedAt = $now;
        $this->writeShared($now);
    }

    private function http(string $url, int $timeout): array
    {
        if ($this->fetch !== null) return ($this->fetch)($url, $timeout);
        if (!function_exists('curl_init')) return [0, ''];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => $timeout]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    private function key(): string { return 'ai_models_' . substr(md5($this->baseUrl), 0, 8); }

    private function apcuOn(): bool { return function_exists('apcu_fetch') && (bool)ini_get('apc.enabled') && (PHP_SAPI !== 'cli' || (bool)ini_get('apc.enable_cli')); }

    /**
     * Cross-request cache. A positive model list is large, so it is kept in APCu when the server has it (otherwise each
     * request asks the local runtime once, which is a few milliseconds). Only the "runtime is down" fact is shared
     * through the database (tbl_app_meta.v is VARCHAR(120)), so a dead runtime is not retried by every request.
     */
    private function readShared(int $now): ?array
    {
        if ($this->apcuOn()) {
            $hit = apcu_fetch($this->key() . '_p', $ok);
            if ($ok && is_array($hit) && ($now - (int)($hit['t'] ?? 0)) < $this->ttl) return [true, (array)($hit['m'] ?? [])];
        }
        if (!$this->db) return null;
        try {
            $st = $this->db->prepare('SELECT v FROM tbl_app_meta WHERE k = ?');
            $st->execute([$this->key() . '_down']);
            $v = $st->fetchColumn();
            if (is_string($v) && ctype_digit($v) && ($now - (int)$v) >= 0 && ($now - (int)$v) < $this->negativeTtl) return [false, []];
        } catch (\Throwable $e) { /* best effort */ }
        return null;
    }

    private function writeShared(int $now): void
    {
        if ($this->reachable && $this->apcuOn()) { apcu_store($this->key() . '_p', ['t' => $now, 'm' => $this->models], $this->ttl); return; }
        if (!$this->reachable && $this->db) {
            try {
                $this->db->prepare('INSERT INTO tbl_app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$this->key() . '_down', (string)$now]);
            } catch (\Throwable $e) { /* best effort */ }
        }
    }
}
