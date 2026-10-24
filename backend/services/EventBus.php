<?php
/**
 * EventBus — MySQL-backed durable outbox (no broker needed; see SCHEDULED_JOBS.md).
 *
 *  - publish(): idempotent by key, never throws into the caller, a harmless no-op when the tables are not migrated yet.
 *    Call it AFTER (or inside the same transaction as) the state change it describes.
 *  - claim(): atomically takes a batch (UPDATE ... ORDER BY ... LIMIT with a unique lock token; no SKIP LOCKED needed).
 *  - dispatch(): runs every registered consumer for the event type. Each (event, consumer) pair is recorded in
 *    tbl_outbox_deliveries, so a retry or replay never re-runs a consumer that already succeeded (idempotent consumers).
 *  - Failures back off exponentially (30s, 1m, 2m ... capped at 1h); after max_attempts the event is dead-lettered
 *    ('dead') and stays visible to admins until replay() puts it back.
 *  - recoverStale(): a worker that crashed mid-event leaves status 'processing'; after a timeout it is released
 *    (attempts were already counted when it was claimed, so a poison event still reaches 'dead').
 *
 * Tables: database/migrations/016_outbox_jobs.sql
 */
interface EventConsumer
{
    /** Stable unique name; recorded in tbl_outbox_deliveries. */
    public function name(): string;
    /** Event types handled, or ['*'] for all. */
    public function handles(): array;
    /** Throw to signal failure (the event is retried with backoff). Must be safe to call twice. */
    public function handle(array $event): void;
}

class EventBus
{
    public const STALE_LOCK_MINUTES = 10;
    public const MAX_BACKOFF_SECONDS = 3600;

    /** @var array<string, EventConsumer> */
    private static array $consumers = [];

    public static function registerConsumer(EventConsumer $c): void { self::$consumers[$c->name()] = $c; }
    public static function resetConsumers(): void { self::$consumers = []; }
    /** @return array<string, EventConsumer> */
    public static function consumers(): array { return self::$consumers; }

    /**
     * Queue an event. Returns the event id, or null when it was a duplicate / could not be stored.
     * A duplicate idempotency key returns the EXISTING id (so callers can treat "already published" as success).
     */
    public static function publish(PDO $dbh, string $type, string $aggregateType, $aggregateId, array $payload = [], ?string $idempotencyKey = null, int $maxAttempts = 6): ?int
    {
        try {
            $aggregateId = (string)$aggregateId;
            $key = $idempotencyKey !== null && $idempotencyKey !== ''
                ? mb_substr($idempotencyKey, 0, 190)
                : mb_substr($type . ':' . $aggregateType . ':' . $aggregateId . ':' . bin2hex(random_bytes(8)), 0, 190);
            $ins = $dbh->prepare(
                "INSERT IGNORE INTO tbl_outbox_events (event_type, aggregate_type, aggregate_id, payload, idempotency_key, max_attempts)
                 VALUES (:t, :at, :ai, :p, :k, :m)"
            );
            $ins->execute([
                ':t' => mb_substr($type, 0, 80), ':at' => mb_substr($aggregateType, 0, 60), ':ai' => mb_substr($aggregateId, 0, 64),
                ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':k' => $key, ':m' => max(1, $maxAttempts),
            ]);
            if ($ins->rowCount() > 0) return (int)$dbh->lastInsertId();
            $q = $dbh->prepare('SELECT id FROM tbl_outbox_events WHERE idempotency_key = ?');
            $q->execute([$key]);
            $id = $q->fetchColumn();
            return $id === false ? null : (int)$id;
        } catch (Throwable $e) {
            error_log('[TourBuddy EventBus] publish failed (caller unaffected): ' . $e->getMessage());
            return null;
        }
    }

    /** Release events whose worker died; dead-letter those that already used all attempts. Returns rows touched. */
    public static function recoverStale(PDO $dbh, int $minutes = self::STALE_LOCK_MINUTES): int
    {
        $minutes = max(1, $minutes);
        $n = 0;
        $st = $dbh->prepare(
            "UPDATE tbl_outbox_events SET status = IF(attempts >= max_attempts, 'dead', 'failed'), locked_by = NULL, locked_at = NULL,
                    available_at = NOW(), last_error = 'worker stopped before finishing (lock expired)'
             WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL :m MINUTE)"
        );
        $st->bindValue(':m', $minutes, PDO::PARAM_INT);
        $st->execute();
        return $n + $st->rowCount();
    }

    /**
     * Atomically claim up to $limit due events for this worker. attempts is incremented at claim time.
     * @return array<int, array<string,mixed>> events with 'payload' decoded
     */
    public static function claim(PDO $dbh, string $workerId, int $limit = 20, ?string $typePrefix = null): array
    {
        $limit = max(1, min(200, $limit));
        $prefix = $typePrefix !== null ? ' AND event_type LIKE ' . $dbh->quote(addcslashes($typePrefix, '%_\\') . '%') : '';   // optional: restrict to one event-type prefix (tests, dedicated workers)
        $token = mb_substr($workerId, 0, 40) . '-' . bin2hex(random_bytes(6));
        $up = $dbh->prepare(
            "UPDATE tbl_outbox_events SET status = 'processing', locked_by = :tok, locked_at = NOW(), attempts = attempts + 1
             WHERE status IN ('pending','failed') AND available_at <= NOW() AND attempts < max_attempts$prefix
             ORDER BY id LIMIT $limit"
        );
        $up->execute([':tok' => $token]);
        if ($up->rowCount() === 0) return [];
        $sel = $dbh->prepare("SELECT * FROM tbl_outbox_events WHERE locked_by = :tok AND status = 'processing' ORDER BY id");
        $sel->execute([':tok' => $token]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $d = json_decode((string)$r['payload'], true);
            $r['payload'] = is_array($d) ? $d : [];
        }
        return $rows;
    }

    /** Seconds to wait before retry number $attempt (1-based): 30, 60, 120 ... capped. */
    public static function backoffSeconds(int $attempt): int
    {
        return (int)min(self::MAX_BACKOFF_SECONDS, 30 * (2 ** max(0, $attempt - 1)));
    }

    /**
     * Run all consumers for one claimed event, then settle it (done / failed+backoff / dead).
     * @return string resulting status
     */
    public static function dispatch(PDO $dbh, array $event): string
    {
        $id = (int)$event['id'];
        $errors = [];
        foreach (self::$consumers as $c) {
            $types = $c->handles();
            if (!in_array('*', $types, true) && !in_array($event['event_type'], $types, true)) continue;

            $done = $dbh->prepare("SELECT 1 FROM tbl_outbox_deliveries WHERE event_id = ? AND consumer = ? AND status = 'done'");
            $done->execute([$id, $c->name()]);
            if ($done->fetchColumn()) continue;                         // already delivered on an earlier attempt / before a replay

            try {
                $c->handle($event);
                $dbh->prepare("INSERT INTO tbl_outbox_deliveries (event_id, consumer, status) VALUES (?,?, 'done')
                               ON DUPLICATE KEY UPDATE status = 'done', error = NULL, delivered_at = NOW()")
                    ->execute([$id, $c->name()]);
            } catch (Throwable $e) {
                $msg = mb_substr($c->name() . ': ' . $e->getMessage(), 0, 480);
                $errors[] = $msg;
                try {
                    $dbh->prepare("INSERT INTO tbl_outbox_deliveries (event_id, consumer, status, error) VALUES (?,?, 'failed', ?)
                                   ON DUPLICATE KEY UPDATE status = 'failed', error = VALUES(error), delivered_at = NOW()")
                        ->execute([$id, $c->name(), $msg]);
                } catch (Throwable $e2) { /* bookkeeping only */ }
            }
        }
        return $errors ? self::fail($dbh, $event, implode(' | ', $errors)) : self::ack($dbh, $id);
    }

    public static function ack(PDO $dbh, int $id): string
    {
        $dbh->prepare("UPDATE tbl_outbox_events SET status = 'done', processed_at = NOW(), locked_by = NULL, locked_at = NULL, last_error = NULL WHERE id = ?")->execute([$id]);
        return 'done';
    }

    public static function fail(PDO $dbh, array $event, string $error): string
    {
        $attempts = (int)$event['attempts'];
        $dead = $attempts >= (int)$event['max_attempts'];
        $delay = self::backoffSeconds($attempts);
        $dbh->prepare(
            "UPDATE tbl_outbox_events SET status = :s, locked_by = NULL, locked_at = NULL, last_error = :e,
                    available_at = DATE_ADD(NOW(), INTERVAL :d SECOND)
             WHERE id = :id"
        )->execute([':s' => $dead ? 'dead' : 'failed', ':e' => mb_substr($error, 0, 490), ':d' => $dead ? 0 : $delay, ':id' => (int)$event['id']]);
        if ($dead) error_log('[TourBuddy EventBus] event ' . $event['id'] . ' (' . $event['event_type'] . ') dead-lettered: ' . $error);
        return $dead ? 'dead' : 'failed';
    }

    /** Process one batch end to end. Returns counts by resulting status. */
    public static function runBatch(PDO $dbh, string $workerId, int $limit = 20, ?string $typePrefix = null): array
    {
        $counts = ['done' => 0, 'failed' => 0, 'dead' => 0, 'recovered' => self::recoverStale($dbh)];
        foreach (self::claim($dbh, $workerId, $limit, $typePrefix) as $ev) {
            try { $status = self::dispatch($dbh, $ev); }
            catch (Throwable $e) { $status = self::fail($dbh, $ev, 'dispatcher error: ' . $e->getMessage()); }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    /** Admin action: put a dead (or failed) event back in the queue. Already-delivered consumers are skipped on the rerun. */
    public static function replay(PDO $dbh, int $eventId): bool
    {
        $st = $dbh->prepare(
            "UPDATE tbl_outbox_events SET status = 'pending', attempts = 0, available_at = NOW(), locked_by = NULL, locked_at = NULL
             WHERE id = ? AND status IN ('dead','failed')"
        );
        $st->execute([$eventId]);
        return $st->rowCount() > 0;
    }

    /** @return array<string,int> counts per status plus oldest pending age in seconds, for dashboards. */
    public static function stats(PDO $dbh): array
    {
        $out = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'dead' => 0, 'done' => 0, 'oldest_pending_age_s' => 0];
        try {
            foreach ($dbh->query("SELECT status, COUNT(*) c FROM tbl_outbox_events GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['status']] = (int)$r['c'];
            $out['oldest_pending_age_s'] = (int)$dbh->query("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0) FROM tbl_outbox_events WHERE status IN ('pending','failed')")->fetchColumn();
        } catch (Throwable $e) { /* tables not migrated yet */ }
        return $out;
    }
}
