<?php
/**
 * ItineraryVersions — versioned itinerary editing for a booking.
 *
 * Every material change is a PROPOSED version that keeps the previous one, records a diff, re-checks feasibility and cost,
 * lists the CONFIRMED bookings it would touch, and only becomes the current plan when its OWNER accepts it. The booking
 * itself (tblbooking: dates, price, payment, supplier) is never read-write from here: accepting a version changes the
 * saved day-by-day plan only. Real booking changes still go through the normal modification/cancellation flow.
 *
 * Itinerary shape = what ItineraryPlanner produces (days[].schedule[] with time, duration, activity, location, cost).
 * Optional per-item fields this class understands (all backward compatible, absent => unknown, never invented):
 *   cost_status  'estimated' | 'quoted' | 'confirmed'   (missing => 'estimated': AI figures are never presented as confirmed)
 *   booking_ref  BookingId of a booking this item belongs to (a confirmed one makes the item "locked")
 *   opens/closes 'HH:MM' opening hours      lat/lng  coordinates (otherwise a geocoder callable may supply them)
 *
 * Pure functions (normalize/costSummary/diff/assess/affected) need no database. DB functions take the acting user's email and
 * verify ownership of the booking themselves; they fail soft with ok=false when the tables are not migrated yet (021).
 */
require_once __DIR__ . '/../ai/ItineraryChecker.php';
require_once __DIR__ . '/RouteOptimizer.php';

final class ItineraryVersions
{
    public const COST_STATUSES = ['estimated', 'quoted', 'confirmed'];
    private const TRANSFER_KMH = 35.0;      // urban/regional average used ONLY to flag impossible gaps, never to promise a time
    private const DETOUR = 1.3;             // straight line -> road factor

    // ── Pure: normalising, costs, diff, assessment ────────────────────────────────

    /** Same plan with every item carrying a valid cost_status ('estimated' unless stated). Never raises a status. */
    public static function normalize(array $it): array
    {
        foreach (($it['days'] ?? []) as $di => $d) {
            foreach (($d['schedule'] ?? []) as $si => $s) {
                if (!is_array($s)) { unset($it['days'][$di]['schedule'][$si]); continue; }
                $st = strtolower((string)($s['cost_status'] ?? ''));
                $it['days'][$di]['schedule'][$si]['cost_status'] = in_array($st, self::COST_STATUSES, true) ? $st : 'estimated';
            }
            if (isset($it['days'][$di]['schedule'])) $it['days'][$di]['schedule'] = array_values($it['days'][$di]['schedule']);
        }
        if (isset($it['days'])) $it['days'] = array_values($it['days']);
        return $it;
    }

    /** @return array{estimated:float,quoted:float,confirmed:float,total:float,items_without_cost:int} */
    public static function costSummary(array $it): array
    {
        $o = ['estimated' => 0.0, 'quoted' => 0.0, 'confirmed' => 0.0, 'total' => 0.0, 'items_without_cost' => 0];
        $it = self::normalize($it);
        foreach (($it['days'] ?? []) as $d) foreach (($d['schedule'] ?? []) as $s) {
            if (!isset($s['cost']) || !is_numeric($s['cost'])) { $o['items_without_cost']++; continue; }
            $c = max(0.0, (float)$s['cost']);
            $o[$s['cost_status']] += $c; $o['total'] += $c;
        }
        return $o;
    }

    /** Stable key for one schedule item, so the same activity is recognised after a reorder. */
    public static function itemKey(array $s): string
    {
        return mb_strtolower(trim((string)($s['activity'] ?? '')) . '|' . trim((string)($s['location'] ?? '')), 'UTF-8');
    }

    /**
     * Item-level comparison old -> new.
     * @return array{days:array,added:int,removed:int,moved:int,changed:int,cost_delta:array,summary:string}
     */
    public static function diff(array $old, array $new): array
    {
        $idx = function (array $it): array {
            $m = [];
            foreach (($it['days'] ?? []) as $d) foreach (($d['schedule'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $k = self::itemKey($s); $n = 0; while (isset($m[$k . '#' . $n])) $n++;
                $m[$k . '#' . $n] = ['day' => (int)($d['day'] ?? 0), 'time' => (string)($s['time'] ?? ''), 'item' => $s];
            }
            return $m;
        };
        $o = $idx($old); $n = $idx($new);
        $days = []; $added = $removed = $moved = $changed = 0;
        $touch = function (int $day) use (&$days) { if (!isset($days[$day])) $days[$day] = ['day' => $day, 'added' => [], 'removed' => [], 'moved' => [], 'changed' => []]; };
        foreach ($n as $k => $v) {
            $label = (string)($v['item']['activity'] ?? '');
            if (!isset($o[$k])) { $touch($v['day']); $days[$v['day']]['added'][] = $label; $added++; continue; }
            $a = $o[$k];
            if ($a['day'] !== $v['day']) { $touch($v['day']); $days[$v['day']]['moved'][] = $label . ' (day ' . $a['day'] . ' → ' . $v['day'] . ')'; $moved++; }
            elseif ($a['time'] !== $v['time']) { $touch($v['day']); $days[$v['day']]['moved'][] = $label . ' (' . $a['time'] . ' → ' . $v['time'] . ')'; $moved++; }
            elseif (($a['item']['cost'] ?? null) != ($v['item']['cost'] ?? null) || ($a['item']['duration'] ?? null) != ($v['item']['duration'] ?? null)) {
                $touch($v['day']); $days[$v['day']]['changed'][] = $label; $changed++;
            }
        }
        foreach ($o as $k => $v) if (!isset($n[$k])) { $touch($v['day']); $days[$v['day']]['removed'][] = (string)($v['item']['activity'] ?? ''); $removed++; }
        ksort($days);
        $co = self::costSummary($old); $cn = self::costSummary($new);
        $delta = [];
        foreach (['estimated', 'quoted', 'confirmed', 'total'] as $k) $delta[$k] = round($cn[$k] - $co[$k], 2);
        $parts = [];
        if ($added) $parts[] = "$added added"; if ($removed) $parts[] = "$removed removed"; if ($moved) $parts[] = "$moved moved"; if ($changed) $parts[] = "$changed changed";
        return ['days' => array_values($days), 'added' => $added, 'removed' => $removed, 'moved' => $moved, 'changed' => $changed, 'cost_delta' => $delta,
            'summary' => $parts ? implode(', ', $parts) : 'no differences'];
    }

    /**
     * Deterministic feasibility + cost assessment. Adds three checks to ItineraryChecker's own:
     *   outside_hours  item starts before 'opens' or ends after 'closes'
     *   transfer_time  gap between consecutive items at different known coordinates is shorter than the estimated transfer
     *   (and the checker's overlap / overloaded_day / budget / distance checks)
     * feasible = no error-level issue AND no outside_hours / transfer_time issue. "Not feasible" means a CONTRADICTION was found;
     * feasible=true never means the plan is guaranteed (live opening hours and traffic are unknown).
     * @param ?callable $geocoder place name -> ['lat'=>,'lng'=>]|null (used only where an item has no lat/lng)
     * @return array{feasible:bool,issues:array,errors:int,warnings:int,cost:array,checked:array,skipped:array}
     */
    public static function assess(array $it, array $trip = [], ?callable $geocoder = null): array
    {
        $it = self::normalize($it);
        $r = ItineraryChecker::check($it, $trip, $geocoder);
        $issues = $r['issues']; $extra = 0; $coordPairs = 0;
        foreach (($it['days'] ?? []) as $d) {
            $n = isset($d['day']) ? (int)$d['day'] : null; $prev = null;
            foreach (($d['schedule'] ?? []) as $s) {
                $t = ItineraryChecker::parseTime($s['time'] ?? null); $dur = ItineraryChecker::parseDuration($s['duration'] ?? null);
                $end = ($t !== null && $dur !== null) ? $t + $dur : null;
                $op = ItineraryChecker::parseTime($s['opens'] ?? null); $cl = ItineraryChecker::parseTime($s['closes'] ?? null);
                if ($t !== null && $op !== null && $t < $op) { $issues[] = ['severity' => 'warning', 'day' => $n, 'code' => 'outside_hours', 'message' => '"' . mb_substr((string)($s['activity'] ?? ''), 0, 40) . '" starts at ' . $s['time'] . ' but opens at ' . $s['opens'] . '.']; $extra++; }
                if ($end !== null && $cl !== null && $end > $cl) { $issues[] = ['severity' => 'warning', 'day' => $n, 'code' => 'outside_hours', 'message' => '"' . mb_substr((string)($s['activity'] ?? ''), 0, 40) . '" would end after closing time ' . $s['closes'] . '.']; $extra++; }
                $pos = null;
                if (isset($s['lat'], $s['lng']) && is_numeric($s['lat']) && is_numeric($s['lng'])) $pos = ['lat' => (float)$s['lat'], 'lng' => (float)$s['lng']];
                elseif ($geocoder && !empty($s['location'])) $pos = $geocoder((string)$s['location']);
                if ($prev && $pos && $prev['end'] !== null && $t !== null && $t >= $prev['end']) {
                    $km = RouteOptimizer::haversineKm($prev['pos']['lat'], $prev['pos']['lng'], $pos['lat'], $pos['lng']);
                    if ($km > 1.0) {
                        $coordPairs++;
                        $need = (int)ceil(($km * self::DETOUR / self::TRANSFER_KMH) * 60); $gap = $t - $prev['end'];
                        if ($gap < $need) { $issues[] = ['severity' => 'warning', 'day' => $n, 'code' => 'transfer_time', 'message' => sprintf('About %d min are needed to get from "%s" to "%s" (%.0f km straight line, rough estimate) but the plan leaves %d min.', $need, mb_substr($prev['name'], 0, 30), mb_substr((string)($s['activity'] ?? ''), 0, 30), $km, $gap)]; $extra++; }
                    }
                }
                $prev = $pos ? ['pos' => $pos, 'end' => $end, 'name' => (string)($s['activity'] ?? '')] : null;
            }
        }
        $errors = count(array_filter($issues, fn($i) => $i['severity'] === 'error'));
        $blocking = $errors + count(array_filter($issues, fn($i) => in_array($i['code'], ['outside_hours', 'transfer_time'], true)));
        return ['feasible' => $blocking === 0, 'issues' => $issues, 'errors' => $errors, 'warnings' => count($issues) - $errors,
            'cost' => self::costSummary($it), 'checked' => array_merge($r['checked'], ['outside_hours', 'transfer_time']),
            'skipped' => array_merge($r['skipped'], $coordPairs === 0 ? ['transfer_time (no coordinates for consecutive items)'] : [])];
    }

    /**
     * Items of the OLD plan that are tied to a CONFIRMED booking and that the new plan removes or moves.
     * @param array<int|string,bool> $confirmedRefs set of booking ids known to be confirmed (status 1) and owned by the user
     * @return array<int,array{booking_ref:string,activity:string,change:string}>
     */
    public static function affectedConfirmed(array $old, array $new, array $confirmedRefs): array
    {
        $newIdx = [];
        foreach (($new['days'] ?? []) as $d) foreach (($d['schedule'] ?? []) as $s) if (is_array($s)) $newIdx[self::itemKey($s)][] = ['day' => (int)($d['day'] ?? 0), 'time' => (string)($s['time'] ?? '')];
        $out = [];
        foreach (($old['days'] ?? []) as $d) foreach (($d['schedule'] ?? []) as $s) {
            if (!is_array($s) || !isset($s['booking_ref']) || empty($confirmedRefs[(string)$s['booking_ref']])) continue;
            $k = self::itemKey($s); $day = (int)($d['day'] ?? 0); $time = (string)($s['time'] ?? '');
            if (empty($newIdx[$k])) { $out[] = ['booking_ref' => (string)$s['booking_ref'], 'activity' => (string)($s['activity'] ?? ''), 'change' => 'removed']; continue; }
            $same = false; foreach ($newIdx[$k] as $p) if ($p['day'] === $day && $p['time'] === $time) $same = true;
            if (!$same) $out[] = ['booking_ref' => (string)$s['booking_ref'], 'activity' => (string)($s['activity'] ?? ''), 'change' => 'moved'];
        }
        return $out;
    }

    /**
     * Route-order proposal for ONE plan: for each day, re-orders items whose location can be placed on the map so the day
     * backtracks less, handing the existing time slots out in the new order. Items tied to a booking_ref, accommodation items,
     * and items without coordinates keep their slot. Pure given the geocoder. Returns the new plan and per-day saving.
     * @return array{itinerary:array,days:array,saved_km:float,changed:bool}
     */
    public static function optimizeRoutes(array $it, ?callable $geocoder): array
    {
        $it = self::normalize($it); $report = []; $total = 0.0; $any = false;
        foreach (($it['days'] ?? []) as $di => $d) {
            $sched = $d['schedule'] ?? []; $movable = []; $anchor = null;
            foreach ($sched as $si => $s) {
                $pos = null;
                if (isset($s['lat'], $s['lng']) && is_numeric($s['lat']) && is_numeric($s['lng'])) $pos = ['lat' => (float)$s['lat'], 'lng' => (float)$s['lng']];
                elseif ($geocoder && !empty($s['location'])) $pos = $geocoder((string)$s['location']);
                $fixed = !empty($s['booking_ref']) || strtolower((string)($s['type'] ?? '')) === 'accommodation';
                if ($fixed) { if (!$movable && $pos) $anchor = ['id' => 'anchor', 'lat' => $pos['lat'], 'lng' => $pos['lng']]; continue; }   // a fixed stop before the first movable one (e.g. the hotel) is where the day starts
                if ($pos) $movable[] = ['id' => $si, 'lat' => $pos['lat'], 'lng' => $pos['lng']];
            }
            if (count($movable) < 3) continue;
            $res = RouteOptimizer::optimize($anchor ? array_merge([$anchor], $movable) : $movable, true, false);
            $res['order'] = array_values(array_filter($res['order'], fn($x) => $x !== 'anchor'));
            if (!$res['changed'] || $res['saved_km'] < 1.0) continue;
            $slots = array_column($movable, 'id'); $timeSlots = [];
            foreach ($slots as $si) $timeSlots[] = ['time' => $sched[$si]['time'] ?? null];
            foreach ($res['order'] as $pos => $si) {
                $new = $sched[$si]; $slot = $timeSlots[$pos];
                if ($slot['time'] !== null) $new['time'] = $slot['time'];
                $it['days'][$di]['schedule'][$slots[$pos]] = $new;
            }
            $report[] = ['day' => (int)($d['day'] ?? $di + 1), 'saved_km' => round($res['saved_km'], 1)]; $total += $res['saved_km']; $any = true;
        }
        return ['itinerary' => $it, 'days' => $report, 'saved_km' => round($total, 1), 'changed' => $any];
    }

    // ── Database: ownership, versions, audit ──────────────────────────────────────

    private static function owns(PDO $dbh, int $bookingId, string $email): ?array
    {
        $st = $dbh->prepare('SELECT BookingId, status, FromDate, ToDate, TotalCost, NoOfPersons FROM tblbooking WHERE BookingId=? AND UserEmail=?');
        $st->execute([$bookingId, $email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function notReady(Throwable $e): bool
    {
        return $e instanceof PDOException && (string)$e->getCode() === '42S02';
    }

    private static function audit(PDO $dbh, int $bookingId, ?int $versionId, string $actor, string $action, string $detail = ''): void
    {
        try { $dbh->prepare('INSERT INTO tbl_itinerary_audit (BookingId, VersionId, Actor, Action, Detail) VALUES (?,?,?,?,?)')->execute([$bookingId, $versionId, mb_substr($actor, 0, 100), $action, mb_substr($detail, 0, 500)]); }
        catch (Throwable $e) { error_log('[TourBuddy ItineraryVersions] audit: ' . $e->getMessage()); }
    }

    /** Booking ids (string keys) that are referenced by plan items, belong to $email and are CONFIRMED (status 1). */
    public static function confirmedRefs(PDO $dbh, array $it, string $email): array
    {
        $ids = [];
        foreach (($it['days'] ?? []) as $d) foreach (($d['schedule'] ?? []) as $s) if (is_array($s) && isset($s['booking_ref']) && ctype_digit((string)$s['booking_ref'])) $ids[(int)$s['booking_ref']] = true;
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $dbh->prepare("SELECT BookingId FROM tblbooking WHERE UserEmail=? AND status=1 AND BookingId IN ($in)");
        $st->execute(array_merge([$email], array_keys($ids)));
        $out = []; foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(string)$id] = true;
        return $out;
    }

    /**
     * Make sure a CURRENT version mirrors the latest saved plan (tbl_ai_itineraries). If the saved plan changed outside this class
     * (AI regenerate, Autopilot approve) a new current version is recorded and older ones are marked superseded.
     * @return ?array the current version row (decoded) or null when the booking has no saved plan
     */
    public static function ensureCurrent(PDO $dbh, int $bookingId, string $email): ?array
    {
        if (!self::owns($dbh, $bookingId, $email)) return null;
        $st = $dbh->prepare('SELECT id, itinerary_json, ai_version FROM tbl_ai_itineraries WHERE booking_id=? AND user_email=? ORDER BY id DESC LIMIT 1');
        $st->execute([$bookingId, $email]);
        $latest = $st->fetch(PDO::FETCH_ASSOC);
        if (!$latest) return null;
        $plan = json_decode((string)$latest['itinerary_json'], true);
        if (!is_array($plan) || empty($plan['days'])) return null;
        $cur = $dbh->prepare("SELECT * FROM tbl_itinerary_versions WHERE BookingId=? AND Status='current' ORDER BY VersionNo DESC LIMIT 1");
        $cur->execute([$bookingId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['SourceItineraryId'] === (int)$latest['id']) return self::decode($row);
        $dbh->beginTransaction();
        try {
            $mx = $dbh->prepare('SELECT COALESCE(MAX(VersionNo),0) FROM tbl_itinerary_versions WHERE BookingId=? FOR UPDATE');
            $mx->execute([$bookingId]); $no = (int)$mx->fetchColumn() + 1;
            $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='superseded', DecidedAt=NOW() WHERE BookingId=? AND Status='current'")->execute([$bookingId]);
            $plan = self::normalize($plan);
            $src = (string)$latest['ai_version'] === 'autopilot-approved' ? 'autopilot' : ((string)$latest['ai_version'] === 'version-accepted' ? 'user' : 'ai');
            $dbh->prepare("INSERT INTO tbl_itinerary_versions (BookingId, UserEmail, VersionNo, ParentVersionId, Source, Status, SourceItineraryId, ItineraryJson, CostJson, Note, DecidedAt) VALUES (?,?,?,?,?,'current',?,?,?,?,NOW())")
                ->execute([$bookingId, $email, $no, $row['VersionId'] ?? null, $src, (int)$latest['id'], json_encode($plan, JSON_UNESCAPED_UNICODE), json_encode(self::costSummary($plan)), 'Saved plan']);
            $vid = (int)$dbh->lastInsertId();
            self::audit($dbh, $bookingId, $vid, $email, 'recorded', "version $no from saved plan ($src)");
            $dbh->commit();
        } catch (Throwable $e) { if ($dbh->inTransaction()) $dbh->rollBack(); throw $e; }
        $r = $dbh->prepare('SELECT * FROM tbl_itinerary_versions WHERE VersionId=?'); $r->execute([$vid]);
        return self::decode($r->fetch(PDO::FETCH_ASSOC));
    }

    private static function decode(array $r): array
    {
        $r['itinerary'] = json_decode((string)$r['ItineraryJson'], true) ?: [];
        $r['check'] = $r['CheckJson'] !== null ? (json_decode((string)$r['CheckJson'], true) ?: null) : null;
        $r['diff'] = $r['DiffJson'] !== null ? (json_decode((string)$r['DiffJson'], true) ?: null) : null;
        $r['affected'] = $r['AffectedJson'] !== null ? (json_decode((string)$r['AffectedJson'], true) ?: []) : [];
        return $r;
    }

    /**
     * Record a proposed new version of the plan. Does NOT change the current plan.
     * @return array{ok:bool,code:string,message:string,version_id:?int}
     */
    public static function propose(PDO $dbh, int $bookingId, string $email, array $proposed, string $source = 'user', string $note = '', ?callable $geocoder = null): array
    {
        try {
            $bk = self::owns($dbh, $bookingId, $email);
            if (!$bk) return ['ok' => false, 'code' => 'not_found', 'message' => 'Trip not found on your account.', 'version_id' => null];
            if ((int)$bk['status'] === 2) return ['ok' => false, 'code' => 'cancelled', 'message' => 'This booking is cancelled.', 'version_id' => null];
            if (empty($proposed['days']) || !is_array($proposed['days'])) return ['ok' => false, 'code' => 'invalid', 'message' => 'The proposed plan has no days.', 'version_id' => null];
            $base = self::ensureCurrent($dbh, $bookingId, $email);
            if (!$base) return ['ok' => false, 'code' => 'no_plan', 'message' => 'There is no saved plan to compare against yet.', 'version_id' => null];
            $proposed = self::normalize($proposed);
            $diff = self::diff($base['itinerary'], $proposed);
            if ($diff['added'] + $diff['removed'] + $diff['moved'] + $diff['changed'] === 0) return ['ok' => false, 'code' => 'no_change', 'message' => 'The proposal is identical to your current plan.', 'version_id' => null];
            $trip = ['duration' => count($proposed['days'])];
            $assess = self::assess($proposed, $trip, $geocoder);
            $affected = self::affectedConfirmed($base['itinerary'], $proposed, self::confirmedRefs($dbh, $base['itinerary'], $email));
            $dbh->beginTransaction();
            try {
                $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='superseded', DecidedAt=NOW() WHERE BookingId=? AND Status='proposed'")->execute([$bookingId]);
                $mx = $dbh->prepare('SELECT COALESCE(MAX(VersionNo),0) FROM tbl_itinerary_versions WHERE BookingId=? FOR UPDATE'); $mx->execute([$bookingId]); $no = (int)$mx->fetchColumn() + 1;
                $src = in_array($source, ['ai', 'user', 'autopilot', 'optimizer'], true) ? $source : 'user';
                $dbh->prepare("INSERT INTO tbl_itinerary_versions (BookingId, UserEmail, VersionNo, ParentVersionId, Source, Status, ItineraryJson, CostJson, CheckJson, DiffJson, AffectedJson, Note) VALUES (?,?,?,?,?,'proposed',?,?,?,?,?,?)")
                    ->execute([$bookingId, $email, $no, (int)$base['VersionId'], $src, json_encode($proposed, JSON_UNESCAPED_UNICODE), json_encode($assess['cost']),
                        json_encode(['feasible' => $assess['feasible'], 'issues' => $assess['issues'], 'checked' => $assess['checked'], 'skipped' => $assess['skipped']], JSON_UNESCAPED_UNICODE),
                        json_encode($diff, JSON_UNESCAPED_UNICODE), json_encode($affected, JSON_UNESCAPED_UNICODE), mb_substr($note, 0, 500)]);
                $vid = (int)$dbh->lastInsertId();
                self::audit($dbh, $bookingId, $vid, $email, 'proposed', "v$no ($src): " . $diff['summary'] . ($affected ? '; touches ' . count($affected) . ' confirmed item(s)' : ''));
                $dbh->commit();
            } catch (Throwable $e) { if ($dbh->inTransaction()) $dbh->rollBack(); throw $e; }
            return ['ok' => true, 'code' => 'proposed', 'message' => 'Proposal saved. Nothing has changed until you accept it.', 'version_id' => $vid];
        } catch (Throwable $e) {
            if (self::notReady($e)) return ['ok' => false, 'code' => 'not_ready', 'message' => 'Plan versions need the database update (run php database/migrate.php).', 'version_id' => null];
            error_log('[TourBuddy ItineraryVersions] propose: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error', 'message' => 'Could not save the proposal right now.', 'version_id' => null];
        }
    }

    /** Ask the deterministic route optimizer for a proposal on the current plan. */
    public static function proposeRouteOptimization(PDO $dbh, int $bookingId, string $email, ?callable $geocoder): array
    {
        try {
            $cur = self::ensureCurrent($dbh, $bookingId, $email);
        } catch (Throwable $e) {
            return self::notReady($e) ? ['ok' => false, 'code' => 'not_ready', 'message' => 'Plan versions need the database update (run php database/migrate.php).', 'version_id' => null]
                : ['ok' => false, 'code' => 'error', 'message' => 'Could not read your plan right now.', 'version_id' => null];
        }
        if (!$cur) return ['ok' => false, 'code' => 'no_plan', 'message' => 'There is no saved plan yet.', 'version_id' => null];
        $res = self::optimizeRoutes($cur['itinerary'], $geocoder);
        if (!$res['changed']) return ['ok' => false, 'code' => 'no_change', 'message' => 'No day has at least 3 mappable stops that would save 1 km or more, so there is nothing to improve.', 'version_id' => null];
        $note = 'Less backtracking (straight-line estimate, about ' . $res['saved_km'] . ' km): days ' . implode(', ', array_column($res['days'], 'day')) . '.';
        return self::propose($dbh, $bookingId, $email, $res['itinerary'], 'optimizer', $note, $geocoder);
    }

    /**
     * Owner accepts a proposed version. If it touches confirmed bookings the owner must pass $acknowledgeConfirmed = true
     * (the UI shows the list and asks). Never edits tblbooking. @return array{ok:bool,code:string,message:string}
     */
    public static function accept(PDO $dbh, int $versionId, string $email, bool $acknowledgeConfirmed = false): array
    {
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT * FROM tbl_itinerary_versions WHERE VersionId=? AND UserEmail=? FOR UPDATE');
            $st->execute([$versionId, $email]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !self::owns($dbh, (int)$row['BookingId'], $email)) { $dbh->rollBack(); return ['ok' => false, 'code' => 'not_found', 'message' => 'Version not found on your account.']; }
            $v = self::decode($row); $bid = (int)$v['BookingId'];
            if ($v['Status'] === 'current') { $dbh->commit(); return ['ok' => true, 'code' => 'already', 'message' => 'Already your current plan.']; }
            if ($v['Status'] !== 'proposed') { $dbh->rollBack(); return ['ok' => false, 'code' => $v['Status'], 'message' => 'This proposal is no longer open (' . $v['Status'] . ').']; }
            $bk = self::owns($dbh, $bid, $email);
            if ((int)$bk['status'] === 2) { $dbh->rollBack(); return ['ok' => false, 'code' => 'cancelled', 'message' => 'The booking is cancelled.']; }
            $cur = $dbh->prepare("SELECT VersionId FROM tbl_itinerary_versions WHERE BookingId=? AND Status='current' ORDER BY VersionNo DESC LIMIT 1 FOR UPDATE");
            $cur->execute([$bid]); $curId = $cur->fetchColumn();
            if ($curId === false || (int)$curId !== (int)$v['ParentVersionId']) {
                $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='superseded', DecidedAt=NOW() WHERE VersionId=?")->execute([$versionId]);
                self::audit($dbh, $bid, $versionId, $email, 'superseded', 'plan changed after this proposal');
                $dbh->commit();
                return ['ok' => false, 'code' => 'superseded', 'message' => 'Your plan changed after this proposal was made, so it was closed. Please propose again.'];
            }
            // Re-derive what it touches from the live data at decision time (not just what was stored when proposed).
            $prev = $dbh->prepare('SELECT ItineraryJson FROM tbl_itinerary_versions WHERE VersionId=?'); $prev->execute([(int)$curId]);
            $oldPlan = json_decode((string)$prev->fetchColumn(), true) ?: [];
            $affected = self::affectedConfirmed($oldPlan, $v['itinerary'], self::confirmedRefs($dbh, $oldPlan, $email));
            if ($affected && !$acknowledgeConfirmed) {
                $dbh->rollBack();
                return ['ok' => false, 'code' => 'needs_ack', 'message' => 'This change moves or removes ' . count($affected) . ' item(s) tied to confirmed bookings. Accepting only changes your plan; the bookings stay as they are and must be changed through the normal booking process. Tick the confirmation box to continue.'];
            }
            $dbh->prepare('INSERT INTO tbl_ai_itineraries (booking_id, user_email, itinerary_json, ai_version, created_at) VALUES (?,?,?,?,NOW())')
                ->execute([$bid, $email, json_encode($v['itinerary'], JSON_UNESCAPED_UNICODE), 'version-accepted']);
            $newItin = (int)$dbh->lastInsertId();
            $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='superseded', DecidedAt=NOW() WHERE VersionId=?")->execute([(int)$curId]);
            $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='current', DecidedAt=NOW(), SourceItineraryId=? WHERE VersionId=?")->execute([$newItin, $versionId]);
            self::audit($dbh, $bid, $versionId, $email, 'accepted', 'v' . $v['VersionNo'] . ($affected ? ' (acknowledged ' . count($affected) . ' confirmed item(s) affected; bookings untouched)' : ''));
            $dbh->commit();
            return ['ok' => true, 'code' => 'accepted', 'message' => 'Your plan was updated. Your bookings themselves were not changed.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            if (self::notReady($e)) return ['ok' => false, 'code' => 'not_ready', 'message' => 'Plan versions need the database update (run php database/migrate.php).'];
            error_log('[TourBuddy ItineraryVersions] accept: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error', 'message' => 'Could not apply the change right now.'];
        }
    }

    public static function reject(PDO $dbh, int $versionId, string $email): array
    {
        try {
            $st = $dbh->prepare("UPDATE tbl_itinerary_versions SET Status='rejected', DecidedAt=NOW() WHERE VersionId=? AND UserEmail=? AND Status='proposed'");
            $st->execute([$versionId, $email]);
            if ($st->rowCount() !== 1) return ['ok' => false, 'code' => 'not_open', 'message' => 'That proposal is not open.'];
            $b = $dbh->prepare('SELECT BookingId FROM tbl_itinerary_versions WHERE VersionId=?'); $b->execute([$versionId]);
            self::audit($dbh, (int)$b->fetchColumn(), $versionId, $email, 'rejected', 'kept the current plan');
            return ['ok' => true, 'code' => 'rejected', 'message' => 'Proposal rejected. Your plan is unchanged.'];
        } catch (Throwable $e) {
            return self::notReady($e) ? ['ok' => false, 'code' => 'not_ready', 'message' => 'Plan versions need the database update (run php database/migrate.php).'] : ['ok' => false, 'code' => 'error', 'message' => 'Could not do that right now.'];
        }
    }

    /** @return array{ready:bool,versions:array,audit:array} newest first; only the owner's booking */
    public static function overview(PDO $dbh, int $bookingId, string $email): array
    {
        try {
            if (!self::owns($dbh, $bookingId, $email)) return ['ready' => true, 'versions' => [], 'audit' => []];
            try { self::ensureCurrent($dbh, $bookingId, $email); } catch (Throwable $e) { if (self::notReady($e)) throw $e; error_log('[TourBuddy ItineraryVersions] ensureCurrent: ' . $e->getMessage()); }
            $v = $dbh->prepare('SELECT * FROM tbl_itinerary_versions WHERE BookingId=? AND UserEmail=? ORDER BY VersionNo DESC LIMIT 15'); $v->execute([$bookingId, $email]);
            $a = $dbh->prepare('SELECT Actor, Action, Detail, CreatedAt, VersionId FROM tbl_itinerary_audit WHERE BookingId=? ORDER BY AuditId DESC LIMIT 15'); $a->execute([$bookingId]);
            return ['ready' => true, 'versions' => array_map([self::class, 'decode'], $v->fetchAll(PDO::FETCH_ASSOC)), 'audit' => $a->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Throwable $e) {
            if (!self::notReady($e)) error_log('[TourBuddy ItineraryVersions] overview: ' . $e->getMessage());
            return ['ready' => false, 'versions' => [], 'audit' => []];
        }
    }

    /** Compare any two of the owner's versions of one booking. @return ?array diff */
    public static function compare(PDO $dbh, int $bookingId, string $email, int $fromId, int $toId): ?array
    {
        try {
            $st = $dbh->prepare('SELECT VersionId, ItineraryJson FROM tbl_itinerary_versions WHERE BookingId=? AND UserEmail=? AND VersionId IN (?,?)');
            $st->execute([$bookingId, $email, $fromId, $toId]);
            $m = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int)$r['VersionId']] = json_decode((string)$r['ItineraryJson'], true) ?: [];
            return isset($m[$fromId], $m[$toId]) ? self::diff($m[$fromId], $m[$toId]) : null;
        } catch (Throwable $e) { return null; }
    }
}
