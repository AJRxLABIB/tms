<?php
/**
 * InventoryService — date/time inventory for hotels and activities: availability, expiring holds, price
 * revalidation and synchronisation health. Tables: database/migrations/023_inventory_slots.sql.
 *
 * Compatibility rule: an entity with no rows in tbl_inventory_slots is "unmanaged". Every method then returns
 * status 'unmanaged' and the existing booking flow carries on exactly as before. Nothing here blocks an
 * unmanaged entity, and nothing here invents availability.
 *
 * Concurrency: hold() locks the slot row (SELECT ... FOR UPDATE) and then does a conditional UPDATE
 * (Booked + qty <= Capacity AND IsBlackout = 0); the update's row count, not the earlier read, decides success.
 * Two buyers racing for the last unit therefore serialise and exactly one wins.
 *
 * Pure logic (no DB, unit-tested in tests/suppliers/inventory_test.php): remaining(), slotStartTs(), check(),
 * effectivePrice(), classifySyncRun(), badgeHtml().
 */
class InventoryService
{
    public const DEFAULT_HOLD_SECONDS = 900;   // 15 minutes
    public const MAX_HOLD_SECONDS     = 3600;
    public const MAX_QTY              = 50;
    public const SYNC_STALE_HOURS     = 24;

    // ───────────────────────── pure logic ─────────────────────────

    public static function remaining(array $slot): int
    {
        return max(0, (int)($slot['Capacity'] ?? 0) - (int)($slot['Booked'] ?? 0));
    }

    /**
     * Moment the cutoff is measured from. A timed slot starts at SlotDate+StartTime. An all-day slot
     * (StartTime 00:00:00, e.g. a hotel night) is measured from the END of that day, so a same-day booking is
     * still possible when cutoff is 0.
     */
    public static function slotStartTs(string $date, string $time = '00:00:00'): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ? $time : '00:00:00';
        if (strlen($time) === 5) $time .= ':00';
        $base = strtotime($date . ' 00:00:00 UTC');
        if ($base === false) return null;
        if ($time === '00:00:00') return $base + 86399;
        [$h, $m, $s] = array_map('intval', explode(':', $time));
        return $base + $h * 3600 + $m * 60 + $s;
    }

    /**
     * Can $qty units of $slot be held at time $now (all timestamps are UTC-naive, caller keeps them consistent)?
     * @return array{ok:bool,reason:string}   reason: ok|bad_qty|blackout|sold_out|insufficient|cutoff|bad_slot
     */
    public static function check(array $slot, int $qty, int $now): array
    {
        if ($qty < 1 || $qty > self::MAX_QTY) return ['ok' => false, 'reason' => 'bad_qty'];
        if (!empty($slot['IsBlackout'])) return ['ok' => false, 'reason' => 'blackout'];
        $start = self::slotStartTs((string)($slot['SlotDate'] ?? ''), (string)($slot['StartTime'] ?? '00:00:00'));
        if ($start === null) return ['ok' => false, 'reason' => 'bad_slot'];
        if ($start - ((int)($slot['CutoffMinutes'] ?? 0) * 60) <= $now) return ['ok' => false, 'reason' => 'cutoff'];
        $rem = self::remaining($slot);
        if ($rem <= 0) return ['ok' => false, 'reason' => 'sold_out'];
        if ($rem < $qty) return ['ok' => false, 'reason' => 'insufficient'];
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** Authoritative unit price: the slot's override when set (> 0), otherwise the listing price. */
    public static function effectivePrice($override, int $listingPrice): int
    {
        return ($override !== null && $override !== '' && (int)$override > 0) ? (int)$override : $listingPrice;
    }

    /**
     * Health of the most recent sync run for one source. $row keys: Status, StartedAt, FinishedAt, Error.
     * @return array{state:string,stale:bool,age_hours:?int,message:string}   state: ok|failed|partial|running|never
     */
    public static function classifySyncRun(?array $row, int $now, int $staleHours = self::SYNC_STALE_HOURS): array
    {
        if (!$row) return ['state' => 'never', 'stale' => true, 'age_hours' => null, 'message' => 'Never synchronised.'];
        $status = (string)($row['Status'] ?? '');
        $ref = !empty($row['FinishedAt']) ? strtotime((string)$row['FinishedAt']) : strtotime((string)($row['StartedAt'] ?? ''));
        $age = $ref ? max(0, intdiv($now - $ref, 3600)) : null;
        if ($status === 'failed') {
            return ['state' => 'failed', 'stale' => true, 'age_hours' => $age, 'message' => 'Last run failed' . (!empty($row['Error']) ? ': ' . $row['Error'] : '.')];
        }
        if ($status === 'running') {
            $stuck = $age !== null && $age >= 2;   // a run that has been "running" for 2+ hours is stuck
            return ['state' => 'running', 'stale' => $stuck, 'age_hours' => $age, 'message' => $stuck ? 'Run started ' . $age . ' h ago and never finished.' : 'Run in progress.'];
        }
        $stale = $age === null || $age > $staleHours;
        $msg = $stale ? 'Last successful run ' . ($age === null ? 'time unknown' : $age . ' h ago') . ' (limit ' . $staleHours . ' h).' : 'Synchronised ' . $age . ' h ago.';
        return ['state' => $status === 'partial' ? 'partial' : 'ok', 'stale' => $stale || $status === 'partial', 'age_hours' => $age, 'message' => ($status === 'partial' ? 'Partly synchronised. ' : '') . $msg];
    }

    /** Escaped HTML badge for an InventorySource::classify() result. Demo is never presented as live. */
    public static function badgeHtml(array $label): string
    {
        $colors = ['live' => '#047857', 'manual' => '#1d4ed8', 'stale' => '#b45309', 'demo' => '#6b7280'];
        $badge = (string)($label['badge'] ?? 'manual');
        $c = $colors[$badge] ?? '#6b7280';
        $text = htmlspecialchars((string)($label['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $why  = htmlspecialchars((string)($label['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
        return '<span class="tms-inv-badge" title="' . $why . '" style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff;background:' . $c . ';">' . $text . '</span>';
    }

    /** Server wall-clock time expressed on the same (UTC-naive) scale slotStartTs() uses for slot dates. */
    public static function nowLocalAsUtc(): int
    {
        return (int)strtotime(date('Y-m-d H:i:s') . ' UTC');
    }

    // ───────────────────────── database ─────────────────────────

    private static function validType(string $type): bool { return $type === 'hotel' || $type === 'activity'; }

    /** True when the entity has at least one slot row (i.e. inventory is tracked for it). */
    public static function isManaged(PDO $dbh, string $type, int $entityId): bool
    {
        if (!self::validType($type)) return false;
        try {
            $st = $dbh->prepare('SELECT 1 FROM tbl_inventory_slots WHERE EntityType = ? AND EntityId = ? LIMIT 1');
            $st->execute([$type, $entityId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }   // table missing (migration not applied) = unmanaged
    }

    /**
     * Availability for a date range (inclusive, max 62 days).
     * @return array{status:string,slots:array}   status: unmanaged|ok|bad_range
     */
    public static function availability(PDO $dbh, string $type, int $entityId, string $from, string $to): array
    {
        if (!self::validType($type) || self::slotStartTs($from) === null || self::slotStartTs($to) === null || $to < $from
            || (strtotime($to) - strtotime($from)) > 62 * 86400) {
            return ['status' => 'bad_range', 'slots' => []];
        }
        if (!self::isManaged($dbh, $type, $entityId)) return ['status' => 'unmanaged', 'slots' => []];
        $st = $dbh->prepare(
            'SELECT SlotId, SlotDate, StartTime, Capacity, Booked, PriceOverride, CutoffMinutes, IsBlackout, Source
               FROM tbl_inventory_slots WHERE EntityType = ? AND EntityId = ? AND SlotDate BETWEEN ? AND ? ORDER BY SlotDate, StartTime'
        );
        $st->execute([$type, $entityId, $from, $to]);
        $now = self::nowLocalAsUtc();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = self::check($row, 1, $now);
            $row['Remaining'] = self::remaining($row);
            $row['Bookable'] = $c['ok'];
            $row['Reason'] = $c['reason'];
            $out[] = $row;
        }
        return ['status' => 'ok', 'slots' => $out];
    }

    /**
     * Place an expiring hold. Idempotent per (slot, holder): a second call while a hold is active returns it.
     * @return array{status:string,hold_id?:int,expires_at?:string,qty?:int,reason?:string}
     *   status: held|unmanaged|unavailable|error   (reason carries the check() reason when unavailable)
     */
    public static function hold(PDO $dbh, string $type, int $entityId, string $date, int $qty, string $holder,
                                int $ttlSeconds = self::DEFAULT_HOLD_SECONDS, string $startTime = '00:00:00'): array
    {
        $holder = trim($holder);
        if (!self::validType($type) || $holder === '' || strlen($holder) > 120) return ['status' => 'error', 'reason' => 'bad_input'];
        if (!self::isManaged($dbh, $type, $entityId)) return ['status' => 'unmanaged'];
        $ttl = max(60, min(self::MAX_HOLD_SECONDS, $ttlSeconds));
        $own = !$dbh->inTransaction();
        try {
            if ($own) $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT * FROM tbl_inventory_slots WHERE EntityType = ? AND EntityId = ? AND SlotDate = ? AND StartTime = ? FOR UPDATE');
            $st->execute([$type, $entityId, $date, strlen($startTime) === 5 ? $startTime . ':00' : $startTime]);
            $slot = $st->fetch(PDO::FETCH_ASSOC);
            if (!$slot) { if ($own) $dbh->rollBack(); return ['status' => 'unavailable', 'reason' => 'no_slot']; }

            // Existing active hold by this holder: return it (idempotent) unless it already lapsed, in which case free it first.
            $ex = $dbh->prepare("SELECT HoldId, Qty, ExpiresAt, (ExpiresAt < NOW()) AS Lapsed FROM tbl_inventory_holds WHERE SlotId = ? AND Holder = ? AND Status = 'active' FOR UPDATE");
            $ex->execute([(int)$slot['SlotId'], $holder]);
            if ($old = $ex->fetch(PDO::FETCH_ASSOC)) {
                if (!(int)$old['Lapsed']) {
                    if ($own) $dbh->commit();
                    return ['status' => 'held', 'hold_id' => (int)$old['HoldId'], 'expires_at' => (string)$old['ExpiresAt'], 'qty' => (int)$old['Qty'], 'reason' => 'existing'];
                }
                self::finishHold($dbh, (int)$old['HoldId'], (int)$slot['SlotId'], (int)$old['Qty'], 'expired');
                $slot['Booked'] = max(0, (int)$slot['Booked'] - (int)$old['Qty']);
            }

            $chk = self::check($slot, $qty, self::nowLocalAsUtc());
            if (!$chk['ok']) { if ($own) $dbh->commit(); return ['status' => 'unavailable', 'reason' => $chk['reason']]; }

            // The conditional UPDATE is the real guard; the read above only gives a friendly reason.
            $up = $dbh->prepare('UPDATE tbl_inventory_slots SET Booked = Booked + :q WHERE SlotId = :id AND IsBlackout = 0 AND Booked + :q2 <= Capacity');
            $up->execute([':q' => $qty, ':q2' => $qty, ':id' => (int)$slot['SlotId']]);
            if ($up->rowCount() !== 1) { if ($own) $dbh->rollBack(); return ['status' => 'unavailable', 'reason' => 'sold_out']; }

            $ins = $dbh->prepare("INSERT INTO tbl_inventory_holds (SlotId, Holder, Qty, Status, ActiveFlag, ExpiresAt) VALUES (?, ?, ?, 'active', 1, DATE_ADD(NOW(), INTERVAL ? SECOND))");
            $ins->execute([(int)$slot['SlotId'], $holder, $qty, $ttl]);
            $holdId = (int)$dbh->lastInsertId();
            $exp = $dbh->prepare('SELECT ExpiresAt FROM tbl_inventory_holds WHERE HoldId = ?');
            $exp->execute([$holdId]);
            if ($own) $dbh->commit();
            return ['status' => 'held', 'hold_id' => $holdId, 'expires_at' => (string)$exp->fetchColumn(), 'qty' => $qty];
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TMS Inventory] hold failed: ' . $e->getMessage());
            return ['status' => 'error', 'reason' => 'exception'];
        }
    }

    /** Close an active hold row and return its units to the slot. Caller holds the locks and the transaction. */
    private static function finishHold(PDO $dbh, int $holdId, int $slotId, int $qty, string $newStatus): void
    {
        $dbh->prepare("UPDATE tbl_inventory_holds SET Status = ?, ActiveFlag = NULL WHERE HoldId = ? AND Status = 'active'")->execute([$newStatus, $holdId]);
        $dbh->prepare('UPDATE tbl_inventory_slots SET Booked = GREATEST(0, Booked - ?) WHERE SlotId = ?')->execute([$qty, $slotId]);
    }

    /** Release an active hold early (customer left checkout). Only the holder can release. */
    public static function release(PDO $dbh, int $holdId, string $holder): bool
    {
        return self::closeActive($dbh, $holdId, $holder, 'released') === 'released';
    }

    /**
     * Turn an active hold into a booking: the units stay counted, the hold stops expiring.
     * A hold whose time has run out but that the sweep has not yet released still owns its units (they are
     * still in Booked), so converting it is safe and a late supplier/payment confirmation is not lost.
     * A hold the sweep already released is refused ('expired'): the units may have been resold.
     * @return string converted|already_converted|expired|not_found|forbidden|error
     */
    public static function convert(PDO $dbh, int $holdId, string $holder, string $ref = ''): string
    {
        $own = !$dbh->inTransaction();
        try {
            if ($own) $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT HoldId, SlotId, Holder, Status FROM tbl_inventory_holds WHERE HoldId = ? FOR UPDATE');
            $st->execute([$holdId]);
            $h = $st->fetch(PDO::FETCH_ASSOC);
            if (!$h) { if ($own) $dbh->rollBack(); return 'not_found'; }
            if (!hash_equals((string)$h['Holder'], $holder)) { if ($own) $dbh->rollBack(); return 'forbidden'; }
            if ($h['Status'] === 'converted') { if ($own) $dbh->commit(); return 'already_converted'; }
            if ($h['Status'] !== 'active') { if ($own) $dbh->rollBack(); return 'expired'; }
            $dbh->prepare("UPDATE tbl_inventory_holds SET Status = 'converted', ActiveFlag = NULL, ConvertedRef = ? WHERE HoldId = ? AND Status = 'active'")
                ->execute([$ref !== '' ? mb_substr($ref, 0, 40) : null, $holdId]);
            if ($own) $dbh->commit();
            return 'converted';
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TMS Inventory] convert failed: ' . $e->getMessage());
            return 'error';
        }
    }

    private static function closeActive(PDO $dbh, int $holdId, string $holder, string $newStatus): string
    {
        $own = !$dbh->inTransaction();
        try {
            if ($own) $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT SlotId, Holder, Qty, Status FROM tbl_inventory_holds WHERE HoldId = ? FOR UPDATE');
            $st->execute([$holdId]);
            $h = $st->fetch(PDO::FETCH_ASSOC);
            if (!$h || !hash_equals((string)$h['Holder'], $holder) || $h['Status'] !== 'active') { if ($own) $dbh->rollBack(); return 'noop'; }
            // Lock the slot too, in the same order hold() uses (slot then hold is impossible here, so keep it short).
            $dbh->prepare('SELECT SlotId FROM tbl_inventory_slots WHERE SlotId = ? FOR UPDATE')->execute([(int)$h['SlotId']]);
            self::finishHold($dbh, $holdId, (int)$h['SlotId'], (int)$h['Qty'], $newStatus);
            if ($own) $dbh->commit();
            return $newStatus;
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TMS Inventory] close hold failed: ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * Expire lapsed active holds and return their units. Re-checks each hold under a row lock, so it is safe to
     * run while buyers are active and safe to re-run. $dryRun lists without changing.
     * @return array{found:int,expired:int,ids:int[]}
     */
    public static function sweepExpired(PDO $dbh, bool $dryRun = true, int $limit = 500): array
    {
        $ids = $dbh->query("SELECT HoldId FROM tbl_inventory_holds WHERE Status = 'active' AND ExpiresAt < NOW() ORDER BY HoldId LIMIT " . max(1, min(5000, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        $res = ['found' => count($ids), 'expired' => 0, 'ids' => array_map('intval', $ids)];
        if ($dryRun) return $res;
        foreach ($ids as $id) {
            try {
                $dbh->beginTransaction();
                $h = $dbh->prepare("SELECT SlotId, Qty FROM tbl_inventory_holds WHERE HoldId = ? AND Status = 'active' AND ExpiresAt < NOW() FOR UPDATE");
                $h->execute([(int)$id]);
                $row = $h->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $dbh->rollBack(); continue; }
                $dbh->prepare('SELECT SlotId FROM tbl_inventory_slots WHERE SlotId = ? FOR UPDATE')->execute([(int)$row['SlotId']]);
                self::finishHold($dbh, (int)$id, (int)$row['SlotId'], (int)$row['Qty'], 'expired');
                $dbh->commit();
                $res['expired']++;
            } catch (Throwable $e) {
                if ($dbh->inTransaction()) $dbh->rollBack();
                error_log('[TMS Inventory] sweep hold ' . $id . ': ' . $e->getMessage());
            }
        }
        return $res;
    }

    /**
     * Revalidate a quoted price at checkout against the authoritative record. Never trusts the client's number.
     * @return array{status:string,price:int,matches:bool}   status: unmanaged|ok|no_slot
     */
    public static function revalidatePrice(PDO $dbh, string $type, int $entityId, string $date, int $listingPrice, ?int $quoted = null, string $startTime = '00:00:00'): array
    {
        if (!self::isManaged($dbh, $type, $entityId)) return ['status' => 'unmanaged', 'price' => $listingPrice, 'matches' => $quoted === null || $quoted === $listingPrice];
        $st = $dbh->prepare('SELECT PriceOverride FROM tbl_inventory_slots WHERE EntityType = ? AND EntityId = ? AND SlotDate = ? AND StartTime = ?');
        $st->execute([$type, $entityId, $date, strlen($startTime) === 5 ? $startTime . ':00' : $startTime]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['status' => 'no_slot', 'price' => $listingPrice, 'matches' => false];
        $p = self::effectivePrice($row['PriceOverride'], $listingPrice);
        return ['status' => 'ok', 'price' => $p, 'matches' => $quoted === null || $quoted === $p];
    }

    // ───────────── booking-flow helpers (used inside the caller's transaction) ─────────────

    /** Human-readable reason shown to a customer for an 'unavailable' result. */
    public static function reasonMessage(string $reason, string $date = ''): string
    {
        $on = $date !== '' ? ' on ' . $date : '';
        return match ($reason) {
            'sold_out'  => 'Sorry, that is sold out' . $on . '. Please choose another date or fewer rooms/people.',
            'cutoff'    => 'Booking for' . ($date !== '' ? ' ' . $date : ' that date') . ' has closed (past the booking cut-off).',
            'blackout'  => 'That is not available' . $on . ' (blackout date).',
            'no_slot'   => 'No availability has been published' . $on . '.',
            default     => 'That is not available' . $on . '. Please choose another date.',
        };
    }

    /**
     * Reserve one unit-count ($qty) on each of $dates for a booking about to be inserted. MUST be called inside the
     * caller's open transaction so a failure rolls the whole booking back. The price comes from the slot (override)
     * or the listing price, never from the client.
     * @param string[] $dates  Y-m-d, one per night (hotel) or a single activity date
     * @return array{status:string,total:int,hold_ids:int[],reason?:string,date?:string}
     *   status: unmanaged (caller keeps its legacy path unchanged) | ok | unavailable | error
     */
    public static function reserveForBooking(PDO $dbh, string $type, int $entityId, array $dates, int $qty, string $holder, int $listingPrice): array
    {
        if (!self::validType($type) || $qty < 1 || !$dates) return ['status' => 'error', 'total' => 0, 'hold_ids' => [], 'reason' => 'bad_input'];
        if (!self::isManaged($dbh, $type, $entityId)) return ['status' => 'unmanaged', 'total' => 0, 'hold_ids' => []];
        if (count($dates) > 62) return ['status' => 'unavailable', 'total' => 0, 'hold_ids' => [], 'reason' => 'too_long'];
        $total = 0; $ids = [];
        foreach ($dates as $d) {
            $h = self::hold($dbh, $type, $entityId, $d, $qty, $holder, 900);
            if ($h['status'] !== 'held') {
                return ['status' => $h['status'] === 'unavailable' ? 'unavailable' : 'error', 'total' => 0, 'hold_ids' => [], 'reason' => (string)($h['reason'] ?? 'error'), 'date' => $d];
            }
            $ids[] = (int)$h['hold_id'];
            $p = self::revalidatePrice($dbh, $type, $entityId, $d, $listingPrice);
            $total += $p['price'] * $qty;
        }
        return ['status' => 'ok', 'total' => $total, 'hold_ids' => $ids];
    }

    /** Turn the holds from reserveForBooking() into a booking reference (e.g. 'hotel:123'). Same transaction as the insert. */
    public static function confirmReservation(PDO $dbh, array $holdIds, string $holder, string $ref): bool
    {
        foreach ($holdIds as $id) {
            $r = self::convert($dbh, (int)$id, $holder, $ref);
            if ($r !== 'converted' && $r !== 'already_converted') return false;
        }
        return true;
    }

    /** Booking reference stored in tbl_inventory_holds.ConvertedRef. */
    public static function bookingRef(string $type, int $bookingId): string { return $type . ':' . $bookingId; }

    /**
     * Give back the units of a booking that was cancelled/deleted. Idempotent: only holds still 'converted' are
     * released, each exactly once, so repeat cancels, admin + customer cancels and retries never double-release.
     * Safe (returns 0) for unmanaged bookings and when the inventory tables do not exist.
     */
    public static function releaseBooking(PDO $dbh, string $type, int $bookingId): int
    {
        if (!self::validType($type) || $bookingId <= 0) return 0;
        $ref = self::bookingRef($type, $bookingId);
        $own = !$dbh->inTransaction();
        $n = 0;
        try {
            if ($own) $dbh->beginTransaction();
            $st = $dbh->prepare("SELECT HoldId, SlotId, Qty FROM tbl_inventory_holds WHERE ConvertedRef = ? AND Status = 'converted' ORDER BY SlotId FOR UPDATE");
            $st->execute([$ref]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $dbh->prepare('SELECT SlotId FROM tbl_inventory_slots WHERE SlotId = ? FOR UPDATE')->execute([(int)$h['SlotId']]);
                $u = $dbh->prepare("UPDATE tbl_inventory_holds SET Status = 'released', ActiveFlag = NULL WHERE HoldId = ? AND Status = 'converted'");
                $u->execute([(int)$h['HoldId']]);
                if ($u->rowCount() === 1) {
                    $dbh->prepare('UPDATE tbl_inventory_slots SET Booked = GREATEST(0, Booked - ?) WHERE SlotId = ?')->execute([(int)$h['Qty'], (int)$h['SlotId']]);
                    $n++;
                }
            }
            if ($own) $dbh->commit();
        } catch (Throwable $e) {
            if ($own && $dbh->inTransaction()) $dbh->rollBack();
            error_log('[TMS Inventory] releaseBooking ' . $ref . ' failed: ' . $e->getMessage());
            return 0;   // table missing / DB error: legacy behaviour (nothing to release) and the cancel itself still goes ahead
        }
        return $n;
    }

    /** Short availability line for a detail page: next $days days, only for managed entities. Null when unmanaged. */
    public static function summaryForNextDays(PDO $dbh, string $type, int $entityId, int $days = 14): ?array
    {
        $av = self::availability($dbh, $type, $entityId, date('Y-m-d'), date('Y-m-d', time() + $days * 86400));
        if ($av['status'] !== 'ok') return null;
        $open = 0; $units = 0;
        foreach ($av['slots'] as $s) { if ($s['Bookable']) { $open++; $units += $s['Remaining']; } }
        return ['slots_open' => $open, 'units_left' => $units, 'days' => $days];
    }

    // ───────────────────────── sync health ─────────────────────────

    public static function syncStart(PDO $dbh, string $source, ?string $entityType = null): int
    {
        $dbh->prepare("INSERT INTO tbl_inventory_sync_log (Source, EntityType, Status) VALUES (?, ?, 'running')")->execute([mb_substr($source, 0, 60), $entityType]);
        return (int)$dbh->lastInsertId();
    }

    public static function syncFinish(PDO $dbh, int $syncId, string $status, int $seen = 0, int $changed = 0, string $error = ''): void
    {
        if (!in_array($status, ['ok', 'partial', 'failed'], true)) $status = 'failed';
        $dbh->prepare('UPDATE tbl_inventory_sync_log SET Status = ?, ItemsSeen = ?, ItemsChanged = ?, Error = ?, FinishedAt = NOW() WHERE SyncId = ?')
            ->execute([$status, max(0, $seen), max(0, $changed), $error !== '' ? mb_substr($error, 0, 255) : null, $syncId]);
    }

    /**
     * Health of every source that has ever logged a run (latest run each), flagged stale/failed.
     * @return array<int,array{source:string,entity_type:?string,health:array,items_seen:int,items_changed:int,started_at:string}>
     */
    public static function syncHealth(PDO $dbh, int $staleHours = self::SYNC_STALE_HOURS): array
    {
        $rows = $dbh->query(
            'SELECT l.* FROM tbl_inventory_sync_log l
               JOIN (SELECT Source, MAX(SyncId) AS m FROM tbl_inventory_sync_log GROUP BY Source) x ON x.m = l.SyncId
              ORDER BY l.Source'
        )->fetchAll(PDO::FETCH_ASSOC);
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['source' => (string)$r['Source'], 'entity_type' => $r['EntityType'], 'health' => self::classifySyncRun($r, $now, $staleHours),
                      'items_seen' => (int)$r['ItemsSeen'], 'items_changed' => (int)$r['ItemsChanged'], 'started_at' => (string)$r['StartedAt']];
        }
        return $out;
    }
}
