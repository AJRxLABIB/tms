<?php
/**
 * ApiSupport — small, dependency-free helpers shared by the REST API (api/index.php, reachable as /api/ and /api/v1/):
 *   - request correlation ids (X-Request-Id, accepted if safe, otherwise generated; echoed in header, meta and errors)
 *   - a standard error envelope  {"error":{"code","message","request_id"}}  (additive: the legacy {"success":false,"error":"text"} body is kept)
 *   - pagination (page / per_page clamped, total, links)
 *   - Idempotency-Key for POST creates (same key + same body => the stored response is replayed; same key + different body => 422)
 *
 * Everything except the idempotency store is pure (no DB, no globals other than the explicit $_SERVER argument), so it is unit-tested
 * in tests/api/contract_test.php without a database. The store needs migration 019_api_idempotency.sql; if the table is missing the
 * feature quietly turns itself off (the request is processed normally) so the API never breaks because of it.
 */
final class ApiSupport
{
    public const IDEMPOTENCY_TTL_SECONDS = 86400;   // 24 h
    public const MAX_KEY_LENGTH = 128;

    private static string $requestId = '';
    /** @var array{pdo:PDO,user:string,key:string,endpoint:string}|null */
    private static ?array $idem = null;

    // ── correlation id ──────────────────────────────────────────────────────

    /** A client-supplied id is only accepted if it is short and URL/log safe; anything else is replaced. */
    public static function sanitizeRequestId(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) > 64) return null;
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{7,63}$/', $raw) ? $raw : null;
    }

    public static function generateRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    /** Establishes the id for this request and sends it back as a header. Safe to call more than once. */
    public static function begin(array $server): string
    {
        if (self::$requestId === '') {
            self::$requestId = self::sanitizeRequestId($server['HTTP_X_REQUEST_ID'] ?? null) ?? self::generateRequestId();
            if (!headers_sent()) header('X-Request-Id: ' . self::$requestId);
        }
        return self::$requestId;
    }

    public static function requestId(): string
    {
        return self::$requestId !== '' ? self::$requestId : self::begin($_SERVER);
    }

    /** For tests only. */
    public static function reset(): void { self::$requestId = ''; self::$idem = null; }

    // ── errors ──────────────────────────────────────────────────────────────

    /** Default machine-readable code for an HTTP status. */
    public static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'bad_request',
            $status === 401 => 'unauthorized',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 409 => 'conflict',
            $status === 413 => 'payload_too_large',
            $status === 422 => 'unprocessable',
            $status === 429 => 'rate_limited',
            $status >= 500  => 'server_error',
            default         => 'error',
        };
    }

    /** @return array{error:array{code:string,message:string,request_id:string}} */
    public static function errorEnvelope(int $status, string $message, ?string $code = null, ?string $requestId = null): array
    {
        return ['error' => [
            'code'       => $code ?? self::codeForStatus($status),
            'message'    => $message,
            'request_id' => $requestId ?? self::requestId(),
        ]];
    }

    /**
     * Body for the existing API: keeps "success":false and the plain-string "error" every current client reads, and ADDS
     * "error_code", "request_id" and the standard envelope under "error_detail". Nothing a client already parses changes.
     */
    public static function legacyErrorBody(int $status, string $message, ?string $code = null): array
    {
        $env = self::errorEnvelope($status, $message, $code);
        return [
            'success'      => false,
            'error'        => $message,
            'error_code'   => $env['error']['code'],
            'request_id'   => $env['error']['request_id'],
            'error_detail' => $env['error'],
        ];
    }

    // ── pagination ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $query   usually $_GET (page, per_page, limit)
     * @return array{page:int,per_page:int,offset:int}
     */
    public static function pageParams(array $query, int $defaultPerPage = 12, int $maxPerPage = 50): array
    {
        $page = (int)($query['page'] ?? 1);
        $raw  = $query['per_page'] ?? $query['limit'] ?? $defaultPerPage;   // "limit" is the name existing clients already use
        $per  = (int)$raw;
        $page = max(1, min($page, 1000000));
        $per  = max(1, min($per > 0 ? $per : $defaultPerPage, $maxPerPage));
        return ['page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per];
    }

    /**
     * @param array<string,mixed> $query  the current query string parameters (carried into the links)
     * @return array{total:int,page:int,per_page:int,total_pages:int,links:array<string,?string>}
     */
    public static function pageMeta(int $total, int $page, int $perPage, string $path, array $query = []): array
    {
        $total = max(0, $total);
        $pages = $perPage > 0 ? (int)ceil($total / $perPage) : 0;
        $link = function (int $p) use ($path, $query, $perPage): string {
            unset($query['page'], $query['limit'], $query['per_page']);
            return $path . '?' . http_build_query($query + ['page' => $p, 'per_page' => $perPage]);
        };
        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $pages,
            'links'       => [
                'self'  => $link($page),
                'first' => $link(1),
                'prev'  => $page > 1 ? $link(min($page - 1, max(1, $pages))) : null,
                'next'  => $page < $pages ? $link($page + 1) : null,
                'last'  => $link(max(1, $pages)),
            ],
        ];
    }

    // ── idempotency ─────────────────────────────────────────────────────────

    /** Valid key: 8–128 chars of letters, digits and - _ . : (UUIDs and ULIDs qualify). */
    public static function validIdempotencyKey(?string $key): bool
    {
        $key = (string)$key;
        return strlen($key) >= 8 && strlen($key) <= self::MAX_KEY_LENGTH && preg_match('/^[A-Za-z0-9._:\-]+$/', $key) === 1;
    }

    /** Order-independent fingerprint of a request body, so retries with reordered fields still match. */
    public static function requestHash(string $endpoint, array $body): string
    {
        $canon = function ($v) use (&$canon) {
            if (is_array($v)) { ksort($v); return array_map($canon, $v); }
            return is_scalar($v) || $v === null ? (string)$v : '';
        };
        unset($body['action'], $body['csrf_token']);
        return hash('sha256', $endpoint . '|' . json_encode($canon($body), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Call once the caller is authenticated, before doing the work. Returns normally when the request should be processed
     * (no key sent, feature unavailable, or first use of the key). Replays the stored response (and exits) for a repeat,
     * answers 422 for a key reused with a different body and 409 while the first request is still running.
     *
     * @param array<string,mixed> $body
     * @param callable(int,array):void $respond   api_json-style sender that exits (injected so this stays testable)
     */
    public static function idempotencyBegin(PDO $pdo, string $user, string $endpoint, array $body, array $server, callable $respond): void
    {
        $key = $server['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($key === null || $key === '') return;
        if (!self::validIdempotencyKey($key)) {
            $respond(400, self::legacyErrorBody(400, 'Idempotency-Key must be 8-128 characters: letters, digits, - _ . :', 'invalid_idempotency_key'));
            return;
        }
        $hash = self::requestHash($endpoint, $body);
        try {
            if (mt_rand(1, 50) === 1) {
                $pdo->exec("DELETE FROM tbl_api_idempotency WHERE ExpiresAt < NOW()");
            }
            $ins = $pdo->prepare(
                "INSERT IGNORE INTO tbl_api_idempotency (UserEmail, IdemKey, Endpoint, RequestHash, State, ExpiresAt)
                 VALUES (:u, :k, :e, :h, 'in_progress', DATE_ADD(NOW(), INTERVAL " . self::IDEMPOTENCY_TTL_SECONDS . " SECOND))"
            );
            $ins->execute([':u' => $user, ':k' => $key, ':e' => $endpoint, ':h' => $hash]);
            if ($ins->rowCount() === 1) {                       // first time we see this key: process, then store the result
                self::$idem = ['pdo' => $pdo, 'user' => $user, 'key' => $key, 'endpoint' => $endpoint];
                return;
            }
            $sel = $pdo->prepare("SELECT RequestHash, State, ResponseStatus, ResponseBody, ExpiresAt < NOW() AS Expired
                                  FROM tbl_api_idempotency WHERE UserEmail = :u AND IdemKey = :k AND Endpoint = :e");
            $sel->execute([':u' => $user, ':k' => $key, ':e' => $endpoint]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) return;                                  // vanished (cleanup race): just process normally
            if ((int)$row['Expired'] === 1) {                   // old key: reclaim it
                $pdo->prepare("UPDATE tbl_api_idempotency SET RequestHash=:h, State='in_progress', ResponseStatus=NULL, ResponseBody=NULL,
                                      ExpiresAt = DATE_ADD(NOW(), INTERVAL " . self::IDEMPOTENCY_TTL_SECONDS . " SECOND)
                               WHERE UserEmail=:u AND IdemKey=:k AND Endpoint=:e")
                    ->execute([':h' => $hash, ':u' => $user, ':k' => $key, ':e' => $endpoint]);
                self::$idem = ['pdo' => $pdo, 'user' => $user, 'key' => $key, 'endpoint' => $endpoint];
                return;
            }
            if (!hash_equals((string)$row['RequestHash'], $hash)) {
                $respond(422, self::legacyErrorBody(422, 'This Idempotency-Key was already used with a different request body.', 'idempotency_key_reused'));
                return;
            }
            if ($row['State'] !== 'done') {
                $respond(409, self::legacyErrorBody(409, 'A request with this Idempotency-Key is still being processed. Retry shortly.', 'idempotency_in_progress'));
                return;
            }
            if (!headers_sent()) header('Idempotent-Replayed: true');
            $stored = json_decode((string)$row['ResponseBody'], true);
            $respond((int)$row['ResponseStatus'], is_array($stored) ? $stored : ['success' => true]);
        } catch (Throwable $e) {
            error_log('[TourBuddy API] idempotency unavailable (processing normally): ' . $e->getMessage());
            self::$idem = null;                                 // table missing / DB hiccup: never block the request
        }
    }

    /** True if an idempotent request is currently being recorded (used by api_json). */
    public static function idempotencyActive(): bool { return self::$idem !== null; }

    /**
     * Called by the response sender with the final status and payload. 2xx results are stored for replay; any other result
     * releases the key so the client can fix the request and retry with the same key.
     */
    public static function idempotencyFinish(int $status, array $payload): void
    {
        if (self::$idem === null) return;
        $c = self::$idem; self::$idem = null;
        try {
            if ($status >= 200 && $status < 300) {
                $c['pdo']->prepare("UPDATE tbl_api_idempotency SET State='done', ResponseStatus=:s, ResponseBody=:b
                                    WHERE UserEmail=:u AND IdemKey=:k AND Endpoint=:e")
                    ->execute([':s' => $status, ':b' => json_encode($payload, JSON_UNESCAPED_UNICODE), ':u' => $c['user'], ':k' => $c['key'], ':e' => $c['endpoint']]);
            } else {
                $c['pdo']->prepare("DELETE FROM tbl_api_idempotency WHERE UserEmail=:u AND IdemKey=:k AND Endpoint=:e AND State='in_progress'")
                    ->execute([':u' => $c['user'], ':k' => $c['key'], ':e' => $c['endpoint']]);
            }
        } catch (Throwable $e) {
            error_log('[TourBuddy API] idempotency finish failed: ' . $e->getMessage());
        }
    }

    // ── rate-limit headers ──────────────────────────────────────────────────

    /** @return array<string,string> */
    public static function rateLimitHeaders(int $limit, int $count, int $windowSeconds, ?int $nowTs = null): array
    {
        $now = $nowTs ?? time();
        $reset = $windowSeconds - ($now % max(1, $windowSeconds));
        $h = [
            'X-RateLimit-Limit'     => (string)$limit,
            'X-RateLimit-Remaining' => (string)max(0, $limit - $count),
            'X-RateLimit-Reset'     => (string)$reset,       // seconds until the window resets
        ];
        if ($count > $limit) $h['Retry-After'] = (string)$reset;
        return $h;
    }
}
