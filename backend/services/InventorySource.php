<?php
/**
 * InventorySource — says honestly where a listing's availability/price data comes from.
 *
 *   live_sync  data pushed/pulled from the supplier's own system, with a last-sync time
 *   manual     the supplier or an admin maintains it by hand in this portal
 *   imported   bulk-imported once (CSV/feed) and not refreshed since: may be stale
 *   demo       sample/seed data, never real availability
 *
 * Rules enforced here (not in the page):
 *   - a row flagged IsSampleData, or with no source record on a sample entity, is ALWAYS 'demo';
 *   - 'live_sync' only counts as Live while its last successful sync is recent; otherwise it is shown as stale;
 *   - nothing here ever invents a sync time: no last_synced_at means "never synced".
 * Table: tbl_inventory_source (database/migrations/018_supplier_quality.sql).
 */
class InventorySource
{
    public const SOURCES = ['live_sync', 'manual', 'imported', 'demo'];
    /** A live feed older than this is no longer called "Live". */
    public const LIVE_MAX_AGE_HOURS = 24;
    /** Imported / manual data older than this is flagged "may be stale". */
    public const STALE_AFTER_DAYS = 30;

    /**
     * Pure classification (no DB). $row keys: Source, LastSyncedAt (string|null), LastSyncStatus, IsSampleData (bool|int|null).
     * @return array{source:string,label:string,badge:string,stale:bool,live:bool,age_hours:?int,reason:string}
     */
    public static function classify(?array $row, ?int $now = null): array
    {
        $now = $now ?? time();
        $sample = $row !== null && !empty($row['IsSampleData']);
        $source = $row['Source'] ?? null;
        if ($sample || $source === 'demo') {
            return self::out('demo', 'Demo data', 'demo', false, false, null, 'Sample data for demonstration. It is not real availability.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            // No record at all: say so instead of guessing "live".
            return self::out('manual', 'Manually maintained', 'manual', false, false, null, 'No sync record. Treated as manually maintained.');
        }
        $ts = !empty($row['LastSyncedAt']) ? strtotime((string)$row['LastSyncedAt']) : false;
        $ageH = $ts ? max(0, intdiv($now - $ts, 3600)) : null;

        if ($source === 'live_sync') {
            $failed = in_array(strtolower((string)($row['LastSyncStatus'] ?? '')), ['failed', 'error'], true);
            if ($ts && !$failed && $ageH <= self::LIVE_MAX_AGE_HOURS) {
                return self::out('live_sync', 'Live', 'live', false, true, $ageH, 'Synchronised with the supplier ' . $ageH . ' h ago.');
            }
            $why = !$ts ? 'Never synchronised.' : ($failed ? 'The last synchronisation failed.' : 'Last synchronised ' . $ageH . ' h ago.');
            return self::out('live_sync', 'Sync delayed – may be stale', 'stale', true, false, $ageH, $why);
        }
        $stale = $ts ? ($ageH > self::STALE_AFTER_DAYS * 24) : ($source === 'imported');
        if ($source === 'imported') {
            return self::out('imported', 'Imported – may be stale', 'stale', true, false, $ageH, $ts ? 'Imported ' . intdiv((int)$ageH, 24) . ' days ago and not refreshed.' : 'Imported once, no refresh time recorded.');
        }
        return self::out('manual', 'Manually maintained', $stale ? 'stale' : 'manual', $stale, false, $ageH, $stale ? 'Not updated for over ' . self::STALE_AFTER_DAYS . ' days.' : 'Maintained by hand.');
    }

    private static function out(string $source, string $label, string $badge, bool $stale, bool $live, ?int $age, string $reason): array
    {
        return ['source' => $source, 'label' => $label, 'badge' => $badge, 'stale' => $stale, 'live' => $live, 'age_hours' => $age, 'reason' => $reason];
    }

    /** Record that a sync ran (or failed). Only a caller that really synced should call this. */
    public static function recordSync(PDO $dbh, string $entityType, int $entityId, bool $ok, string $error = ''): void
    {
        $dbh->prepare(
            "INSERT INTO tbl_inventory_source (EntityType, EntityId, Source, LastSyncedAt, LastSyncStatus, LastSyncError)
             VALUES (?, ?, 'live_sync', IF(?, NOW(), NULL), ?, ?)
             ON DUPLICATE KEY UPDATE Source = 'live_sync',
               LastSyncedAt = IF(VALUES(LastSyncStatus) = 'ok', NOW(), LastSyncedAt),
               LastSyncStatus = VALUES(LastSyncStatus), LastSyncError = VALUES(LastSyncError)"
        )->execute([$entityType, $entityId, $ok ? 1 : 0, $ok ? 'ok' : 'failed', $ok ? null : mb_substr($error, 0, 255)]);
    }

    /** Set how an entity's inventory is maintained (admin/supplier action). 'demo' can only be set by an admin context. */
    public static function setSource(PDO $dbh, string $entityType, int $entityId, string $source): bool
    {
        if (!in_array($source, self::SOURCES, true) || !in_array($entityType, ['package', 'hotel', 'activity'], true)) return false;
        $dbh->prepare(
            "INSERT INTO tbl_inventory_source (EntityType, EntityId, Source, LastSyncedAt) VALUES (?, ?, ?, IF(? = 'manual', NOW(), NULL))
             ON DUPLICATE KEY UPDATE Source = VALUES(Source), LastSyncedAt = IF(VALUES(Source) = 'manual', NOW(), LastSyncedAt)"
        )->execute([$entityType, $entityId, $source, $source]);
        return true;
    }

    /**
     * Label for one entity. Hotels/activities carry IsSampleData in their own table; that flag always wins.
     * Fails safe: any DB problem yields the "Manually maintained / no sync record" label, never "Live".
     */
    public static function labelFor(PDO $dbh, string $entityType, int $entityId): array
    {
        $row = null;
        try {
            $st = $dbh->prepare('SELECT Source, LastSyncedAt, LastSyncStatus FROM tbl_inventory_source WHERE EntityType = ? AND EntityId = ?');
            $st->execute([$entityType, $entityId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $table = ['hotel' => ['tblhotels', 'HotelId'], 'activity' => ['tblactivities', 'ActivityId']][$entityType] ?? null;
            if ($table) {
                $s = $dbh->prepare("SELECT IsSampleData FROM {$table[0]} WHERE {$table[1]} = ?");
                $s->execute([$entityId]);
                $flag = $s->fetchColumn();
                if ($flag !== false && (int)$flag === 1) $row = ($row ?? []) + ['IsSampleData' => 1];
            }
        } catch (Throwable $e) { error_log('[TourBuddy InventorySource] ' . $e->getMessage()); }
        return self::classify($row);
    }

    /** Share of a supplier's package inventory records refreshed recently (0..1), or null when there are no records. */
    public static function freshnessForSupplier(PDO $dbh, int $supplierId, ?int $now = null): ?float
    {
        $st = $dbh->prepare(
            "SELECT i.Source, i.LastSyncedAt, i.LastSyncStatus FROM tbltourpackages p
               JOIN tbl_inventory_source i ON i.EntityType = 'package' AND i.EntityId = p.PackageId
              WHERE p.SupplierId = ? AND p.ApprovalStatus = 'approved'"
        );
        $st->execute([$supplierId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $fresh = 0;
        foreach ($rows as $r) { if (!self::classify($r, $now)['stale']) $fresh++; }
        return $fresh / count($rows);
    }

    /** HTML badge (already escaped). */
    public static function badgeHtml(array $label): string
    {
        $cls = ['live' => 'ok', 'manual' => 'info', 'stale' => 'warn', 'demo' => 'bad'][$label['badge']] ?? 'info';
        return '<span class="badge ' . $cls . '" title="' . htmlspecialchars($label['reason'], ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
