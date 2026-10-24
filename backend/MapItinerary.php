<?php
/**
 * MapItinerary — saved multi-stop itineraries for signed-in customers.
 *
 * Every query is parameterised and scoped by user_email in SQL (ownership), so one customer can never read, change or
 * delete another customer's itinerary. Distance/duration totals are stored only when the caller passes them from a
 * successful route result: this class never calculates or invents them.
 */
final class MapItinerary
{
    public const MAX_STOPS = 25;
    public const MAX_PER_USER = 50;
    /** stop cap for personal trips (any stop carrying planner fields); legacy explorer saves keep MAX_STOPS */
    public const MAX_PLANNER_STOPS = 100;
    public const MAX_DAYS = 60;

    private static ?PDO $db = null;
    private static ?bool $planner = null;

    public static function useDb(?PDO $db): void { self::$db = $db; self::$planner = null; }

    /** True once migration 045 (personal trip columns) is applied. Older databases keep working with the legacy columns only. */
    public static function plannerReady(): bool
    {
        if (self::$planner !== null) return self::$planner;
        try {
            self::db()->query('SELECT destination_name, start_date, end_date, day_count, notes, status FROM tbl_map_itineraries LIMIT 0');
            self::db()->query('SELECT day_index, arrive_time, depart_time, est_cost, is_done, place_key FROM tbl_map_itinerary_stops LIMIT 0');
            self::$planner = true;
        } catch (Throwable $e) { self::$planner = false; }
        return self::$planner;
    }

    private static function db(): PDO
    {
        if (self::$db) return self::$db;
        global $dbh;
        if (isset($dbh) && $dbh instanceof PDO) return $dbh;
        throw new RuntimeException('No database connection.');
    }

    /** @return list<array> summaries (no stops), newest first */
    public static function list(string $email): array
    {
        $pl = self::plannerReady();
        $extra = $pl ? ', i.destination_name, i.start_date, i.end_date, i.day_count, i.status,
                    (SELECT SUM(s2.est_cost) FROM tbl_map_itinerary_stops s2 WHERE s2.itinerary_id = i.id) AS est_cost,
                    (SELECT COUNT(*) FROM tbl_map_itinerary_stops s3 WHERE s3.itinerary_id = i.id AND s3.est_cost IS NULL) AS cost_unknown' : '';
        $st = self::db()->prepare(
            'SELECT i.id, i.title, i.mode, i.total_distance_m, i.total_duration_s, i.updated_at,
                    (SELECT COUNT(*) FROM tbl_map_itinerary_stops s WHERE s.itinerary_id = i.id) AS stop_count' . $extra . '
             FROM tbl_map_itineraries i WHERE i.user_email = ? ORDER BY i.updated_at DESC, i.id DESC LIMIT 50'
        );
        $st->execute([self::email($email)]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'mode' => (string)$r['mode'],
                'total_distance_m' => $r['total_distance_m'] === null ? null : (int)$r['total_distance_m'],
                'total_duration_s' => $r['total_duration_s'] === null ? null : (int)$r['total_duration_s'],
                'stop_count' => (int)$r['stop_count'], 'updated_at' => (string)$r['updated_at']];
            if ($pl) {
                $row['destination_name'] = $r['destination_name'] === null ? null : (string)$r['destination_name'];
                $row['start_date'] = $r['start_date'] === null ? null : (string)$r['start_date'];
                $row['end_date'] = $r['end_date'] === null ? null : (string)$r['end_date'];
                $row['days'] = self::daysFor($row['start_date'], $row['end_date'], $r['day_count'] === null ? null : (int)$r['day_count'], 1);
                $row['status'] = (string)$r['status'];
                $row['est_cost'] = $r['est_cost'] === null ? null : round((float)$r['est_cost'], 2);
                $row['cost_unknown_stops'] = (int)$r['cost_unknown'];
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function get(string $email, int $id): ?array
    {
        $db = self::db();
        $pl = self::plannerReady();
        $st = $db->prepare('SELECT id, title, mode, total_distance_m, total_duration_s, updated_at'
            . ($pl ? ', destination_name, start_date, end_date, day_count, notes, status' : '') . ' FROM tbl_map_itineraries WHERE id = ? AND user_email = ?');
        $st->execute([$id, self::email($email)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $stops = self::readStops($db, $id, $pl);
        $out = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'mode' => (string)$r['mode'],
            'total_distance_m' => $r['total_distance_m'] === null ? null : (int)$r['total_distance_m'],
            'total_duration_s' => $r['total_duration_s'] === null ? null : (int)$r['total_duration_s'],
            'updated_at' => (string)$r['updated_at'], 'stops' => $stops];
        if ($pl) {
            $maxDay = 1; foreach ($stops as $s) $maxDay = max($maxDay, (int)($s['day_index'] ?? 1));
            $out['destination_name'] = $r['destination_name'] === null ? null : (string)$r['destination_name'];
            $out['start_date'] = $r['start_date'] === null ? null : (string)$r['start_date'];
            $out['end_date'] = $r['end_date'] === null ? null : (string)$r['end_date'];
            $out['notes'] = $r['notes'] === null ? null : (string)$r['notes'];
            $out['status'] = (string)$r['status'];
            $out['days'] = self::daysFor($out['start_date'], $out['end_date'], $r['day_count'] === null ? null : (int)$r['day_count'], $maxDay);
            $out['planner_ready'] = true;
        }
        return $out;
    }

    /** @return list<array> stops in position order, with planner fields when the columns exist */
    private static function readStops(PDO $db, int $id, bool $pl): array
    {
        $ss = $db->prepare('SELECT position, place_id, name, lat, lng, note' . ($pl ? ', day_index, arrive_time, depart_time, est_cost, is_done, place_key' : '')
            . ' FROM tbl_map_itinerary_stops WHERE itinerary_id = ? ORDER BY position');
        $ss->execute([$id]);
        $stops = [];
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $row = ['position' => (int)$s['position'], 'place_id' => $s['place_id'] === null ? null : (int)$s['place_id'],
                'name' => (string)$s['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'note' => $s['note'] === null ? null : (string)$s['note']];
            if ($pl) {
                $row['day_index'] = $s['day_index'] === null ? null : (int)$s['day_index'];
                $row['arrive_time'] = $s['arrive_time'] === null ? null : substr((string)$s['arrive_time'], 0, 5);
                $row['depart_time'] = $s['depart_time'] === null ? null : substr((string)$s['depart_time'], 0, 5);
                $row['est_cost'] = $s['est_cost'] === null ? null : round((float)$s['est_cost'], 2);
                $row['is_done'] = (int)$s['is_done'] === 1;
                $row['place_key'] = $s['place_key'] === null ? null : (string)$s['place_key'];
            }
            $stops[] = $row;
        }
        return $stops;
    }

    /** Replace all stops of a trip (caller owns the transaction). Position is 1..n in the order given. */
    private static function writeStops(PDO $db, int $id, array $stops, bool $pl): void
    {
        $db->prepare('DELETE FROM tbl_map_itinerary_stops WHERE itinerary_id = ?')->execute([$id]);
        $known = self::existingPlaceIds($db, array_filter(array_column($stops, 'place_id')));
        if ($pl) {
            $ins = $db->prepare('INSERT INTO tbl_map_itinerary_stops (itinerary_id, position, place_id, name, lat, lng, note, day_index, arrive_time, depart_time, est_cost, is_done, place_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        } else {
            $ins = $db->prepare('INSERT INTO tbl_map_itinerary_stops (itinerary_id, position, place_id, name, lat, lng, note) VALUES (?,?,?,?,?,?,?)');
        }
        foreach (array_values($stops) as $i => $s) {
            $pid = (!empty($s['place_id']) && isset($known[(int)$s['place_id']])) ? (int)$s['place_id'] : null;
            $args = [$id, $i + 1, $pid, $s['name'], $s['lat'], $s['lng'], $s['note'] ?? null];
            if ($pl) array_push($args, $s['day_index'] ?? null, $s['arrive_time'] ?? null, $s['depart_time'] ?? null, $s['est_cost'] ?? null, !empty($s['is_done']) ? 1 : 0, $s['place_key'] ?? null);
            $ins->execute($args);
        }
    }

    /** column => value for the trip-level fields present in $meta (whitelisted names, so safe to put in SQL) */
    private static function metaSet(array $meta): array
    {
        $out = [];
        foreach (['destination_name', 'start_date', 'end_date', 'day_count', 'notes', 'status'] as $k) if (array_key_exists($k, $meta)) $out[$k] = $meta[$k];
        return $out;
    }

    /**
     * @param array $input id?, title, mode?, total_distance_m?, total_duration_s?, stops: [{place_id?, name, lat, lng, note?, day_index?, arrive_time?, depart_time?, est_cost?, is_done?, place_key?}],
     *                     planner? (true allows an empty trip), destination_name?, start_date?, end_date?, day_count?, notes?, status?
     * @return array ['ok'=>true,'id'=>int] | ['ok'=>false,'error'=>['code','message']]
     */
    public static function save(string $email, array $input): array
    {
        $email = self::email($email);
        if ($email === '') return self::err('auth_required', 'Please sign in to save an itinerary.');
        $pl = self::plannerReady();
        $id = (int)($input['id'] ?? 0);
        $db = self::db();
        $existing = [];
        if ($id > 0 && $pl) {
            $q = $db->prepare('SELECT start_date, end_date, day_count FROM tbl_map_itineraries WHERE id = ? AND user_email = ?');
            $q->execute([$id, $email]);
            $existing = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $v = self::validate($input, $existing);
        if (!$v['ok']) return $v;
        $d = $v['data'];
        try {
            $db->beginTransaction();
            if ($id > 0) {
                $own = $db->prepare('SELECT id FROM tbl_map_itineraries WHERE id = ? AND user_email = ? FOR UPDATE');
                $own->execute([$id, $email]);
                if (!$own->fetchColumn()) { $db->rollBack(); return self::err('not_found', 'Itinerary not found.'); }
                $set = ['title' => $d['title'], 'mode' => $d['mode'], 'total_distance_m' => $d['total_distance_m'], 'total_duration_s' => $d['total_duration_s']];
                if ($pl) $set += self::metaSet($d['meta']);
                $db->prepare('UPDATE tbl_map_itineraries SET ' . implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ? AND user_email = ?')
                   ->execute(array_merge(array_values($set), [$id, $email]));
                if ($pl && !$d['has_planner_fields']) {   // legacy explorer save: keep the planner data of stops that are still in the list
                    $d['stops'] = self::mergeLegacy($d['stops'], self::readStops($db, $id, true));
                }
            } else {
                $cnt = $db->prepare('SELECT COUNT(*) FROM tbl_map_itineraries WHERE user_email = ?');
                $cnt->execute([$email]);
                if ((int)$cnt->fetchColumn() >= self::MAX_PER_USER) { $db->rollBack(); return self::err('limit_reached', 'You can keep up to ' . self::MAX_PER_USER . ' saved itineraries. Delete one first.'); }
                $set = ['user_email' => $email, 'title' => $d['title'], 'mode' => $d['mode'], 'total_distance_m' => $d['total_distance_m'], 'total_duration_s' => $d['total_duration_s']];
                if ($pl) $set += self::metaSet($d['meta']);
                $db->prepare('INSERT INTO tbl_map_itineraries (' . implode(', ', array_map(static fn($c) => "`$c`", array_keys($set))) . ') VALUES (' . implode(',', array_fill(0, count($set), '?')) . ')')
                   ->execute(array_values($set));
                $id = (int)$db->lastInsertId();
            }
            self::writeStops($db, $id, $d['stops'], $pl);
            $db->commit();
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[TourBuddy MapItinerary] save failed: ' . $e->getMessage());
            return self::err('save_failed', 'The itinerary could not be saved.');
        }
    }

    /** Create an empty personal trip (a plan only: no booking, payment or package row is ever created here). */
    public static function create(string $email, array $input): array
    {
        $email = self::email($email);
        if ($email === '') return self::err('auth_required', 'Please sign in to plan a trip.');
        if (!self::plannerReady()) return self::err('planner_unavailable', 'The trip planner is not set up yet. Ask the site owner to run the database update.');
        $input['stops'] = [];
        $input['planner'] = true;
        unset($input['id']);
        return self::save($email, $input);
    }

    public static function delete(string $email, int $id): bool
    {
        $st = self::db()->prepare('DELETE FROM tbl_map_itineraries WHERE id = ? AND user_email = ?');   // stops cascade
        $st->execute([$id, self::email($email)]);
        return $st->rowCount() > 0;
    }

    /** Copy a whole trip (stops, times, costs, notes). Progress flags are reset. */
    public static function duplicateTrip(string $email, int $id, ?string $title = null): array
    {
        $email = self::email($email);
        if (!self::plannerReady()) return self::err('planner_unavailable', 'The trip planner is not set up yet.');
        $src = self::get($email, $id);
        if (!$src) return self::err('not_found', 'Trip not found.');
        $t = $title !== null ? trim($title) : '';
        if ($t === '') $t = mb_substr('Copy of ' . $src['title'], 0, 120, 'UTF-8');
        $stops = [];
        foreach ($src['stops'] as $s) { $s['is_done'] = false; $stops[] = $s; }
        return self::save($email, [
            'title' => $t, 'mode' => $src['mode'], 'planner' => true, 'stops' => $stops,
            'destination_name' => $src['destination_name'] ?? null, 'start_date' => $src['start_date'] ?? null, 'end_date' => $src['end_date'] ?? null,
            'day_count' => ($src['start_date'] ?? null) ? null : ($src['days'] ?? null), 'notes' => $src['notes'] ?? null, 'status' => 'planned',
            'total_distance_m' => $src['total_distance_m'], 'total_duration_s' => $src['total_duration_s'],
        ]);
    }

    /** Copy the stops of one day to the end of another day of the same trip. */
    public static function duplicateDay(string $email, int $id, int $fromDay, int $toDay): array
    {
        $email = self::email($email);
        if (!self::plannerReady()) return self::err('planner_unavailable', 'The trip planner is not set up yet.');
        $trip = self::get($email, $id);
        if (!$trip) return self::err('not_found', 'Trip not found.');
        $r = self::duplicateDayStops($trip['stops'], $fromDay, $toDay, (int)$trip['days']);
        if (!$r['ok']) return $r;
        return self::replaceStopsOf($email, $id, $r['stops']);
    }

    /** op: ['dir' => 'up'|'down'] reorders inside the day, ['day' => n] moves the stop to the end of day n. $position is the 1-based stop position. */
    public static function moveStop(string $email, int $id, int $position, array $op): array
    {
        $email = self::email($email);
        if (!self::plannerReady()) return self::err('planner_unavailable', 'The trip planner is not set up yet.');
        $trip = self::get($email, $id);
        if (!$trip) return self::err('not_found', 'Trip not found.');
        $r = self::applyMove($trip['stops'], $position - 1, (string)($op['dir'] ?? ''), isset($op['day']) ? (int)$op['day'] : null, (int)$trip['days']);
        if (!$r['ok']) return $r;
        return self::replaceStopsOf($email, $id, $r['stops']);
    }

    private static function replaceStopsOf(string $email, int $id, array $stops): array
    {
        $db = self::db();
        try {
            $db->beginTransaction();
            $own = $db->prepare('SELECT id FROM tbl_map_itineraries WHERE id = ? AND user_email = ? FOR UPDATE');
            $own->execute([$id, $email]);
            if (!$own->fetchColumn()) { $db->rollBack(); return self::err('not_found', 'Trip not found.'); }
            self::writeStops($db, $id, $stops, true);
            $db->prepare('UPDATE tbl_map_itineraries SET updated_at = NOW() WHERE id = ? AND user_email = ?')->execute([$id, $email]);
            $db->commit();
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[TourBuddy MapItinerary] stop update failed: ' . $e->getMessage());
            return self::err('save_failed', 'The trip could not be updated.');
        }
    }

    // ───────────────────────── pure helpers (unit tested, no database)

    /** @return string|null|false null = empty, false = invalid, else 'Y-m-d' */
    public static function parseDate($v)
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return false;
        if ((int)$m[1] < 2000 || (int)$m[1] > 2100 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return false;
        return $v;
    }

    /** @return string|null|false null = empty, false = invalid, else 'HH:MM' */
    public static function parseTime($v)
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v, $m)) return false;
        return $m[1] . ':' . $m[2];
    }

    /** @return float|null|false null = unknown, false = invalid */
    public static function parseCost($v)
    {
        if ($v === null || $v === '') return null;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v >= 10000000) return false;
        return round((float)$v, 2);
    }

    /** Whole days from start to end inclusive, or null when a date is missing. */
    public static function daySpan(?string $start, ?string $end): ?int
    {
        if (!$start || !$end) return null;
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start); $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if (!$a || !$b) return null;
        return (int)$a->diff($b)->format('%r%a') + 1;
    }

    /** Number of days of a trip: from the dates, else the stored day count, else the highest day used (at least 1). */
    public static function daysFor(?string $start, ?string $end, ?int $dayCount, int $maxDayUsed): int
    {
        $span = self::daySpan($start, $end);
        if ($span !== null && $span >= 1) return min($span, self::MAX_DAYS);
        return max(1, min(self::MAX_DAYS, max((int)$dayCount, $maxDayUsed)));
    }

    /** Validates the trip-level planner fields. Only keys present in $in (or derived from them) are returned in 'meta'. */
    public static function validateMeta(array $in, array $existing = []): array
    {
        $meta = [];
        if (array_key_exists('destination_name', $in)) {
            $dn = is_string($in['destination_name']) ? trim(preg_replace('/\s+/u', ' ', $in['destination_name']) ?? '') : '';
            if (mb_strlen($dn, 'UTF-8') > 160) return self::err('invalid_destination', 'The destination name can have up to 160 characters.');
            $meta['destination_name'] = $dn === '' ? null : $dn;
        }
        if (array_key_exists('notes', $in)) {
            $n = is_string($in['notes']) ? trim($in['notes']) : '';
            if (mb_strlen($n, 'UTF-8') > 2000) return self::err('invalid_notes', 'Trip notes can have up to 2000 characters.');
            $meta['notes'] = $n === '' ? null : $n;
        }
        if (array_key_exists('status', $in)) {
            $st = is_string($in['status']) ? strtolower(trim($in['status'])) : '';
            if (!in_array($st, ['planned', 'completed'], true)) return self::err('invalid_status', 'Status must be planned or completed.');
            $meta['status'] = $st;
        }
        $touch = array_key_exists('start_date', $in) || array_key_exists('end_date', $in) || array_key_exists('day_count', $in);
        if ($touch) {
            $s = array_key_exists('start_date', $in) ? self::parseDate($in['start_date']) : self::parseDate($existing['start_date'] ?? null);
            $e = array_key_exists('end_date', $in) ? self::parseDate($in['end_date']) : self::parseDate($existing['end_date'] ?? null);
            if ($s === false || $e === false) return self::err('invalid_dates', 'Dates must look like YYYY-MM-DD and be real calendar dates.');
            if (($s === null) !== ($e === null)) return self::err('invalid_dates', 'Give both a start date and an end date, or neither.');
            $dc = null;
            if ($s !== null) {
                $span = self::daySpan($s, $e);
                if ($span === null || $span < 1) return self::err('invalid_dates', 'The end date cannot be before the start date.');
                if ($span > self::MAX_DAYS) return self::err('invalid_dates', 'A trip can last at most ' . self::MAX_DAYS . ' days.');
            } else {
                $raw = array_key_exists('day_count', $in) ? $in['day_count'] : ($existing['day_count'] ?? null);
                if ($raw !== null && $raw !== '') {
                    if (!is_numeric($raw) || (int)$raw != $raw || (int)$raw < 1 || (int)$raw > self::MAX_DAYS) return self::err('invalid_days', 'The number of days must be between 1 and ' . self::MAX_DAYS . '.');
                    $dc = (int)$raw;
                }
            }
            $meta['start_date'] = $s; $meta['end_date'] = $e; $meta['day_count'] = $dc;
        }
        return ['ok' => true, 'meta' => $meta];
    }

    /** Pure validation + normalisation. Stop order is the order given (personal trips: grouped by day, stable). */
    public static function validate(array $in, array $existing = []): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')) ?? '');
        $len = mb_strlen($title, 'UTF-8');
        if ($len < 1 || $len > 120) return self::err('invalid_title', 'Give the itinerary a name of up to 120 characters.');
        $mode = strtolower((string)($in['mode'] ?? 'driving'));
        if (!in_array($mode, ['driving', 'walking', 'cycling'], true)) return self::err('invalid_mode', 'Unknown travel mode.');
        $mv = self::validateMeta($in, $existing);
        if (!$mv['ok']) return $mv;
        $meta = $mv['meta'];
        $stopsIn = $in['stops'] ?? null;
        $allowEmpty = !empty($in['planner']);
        if (!is_array($stopsIn) || (count($stopsIn) < 1 && !$allowEmpty)) return self::err('invalid_stops', 'Add at least one stop.');
        $hasPlanner = false;
        foreach ($stopsIn as $s) {
            if (is_array($s) && (array_key_exists('day_index', $s) || array_key_exists('arrive_time', $s) || array_key_exists('depart_time', $s)
                || array_key_exists('est_cost', $s) || array_key_exists('is_done', $s) || array_key_exists('place_key', $s))) { $hasPlanner = true; break; }
        }
        $cap = ($hasPlanner || $allowEmpty) ? self::MAX_PLANNER_STOPS : self::MAX_STOPS;
        if (count($stopsIn) > $cap) return self::err('too_many_stops', 'An itinerary can have at most ' . $cap . ' stops.');
        $stops = []; $maxDay = 1; $anyDay = false;
        foreach (array_values($stopsIn) as $s) {
            if (!is_array($s)) return self::err('invalid_stops', 'Invalid stop.');
            $name = trim(preg_replace('/\s+/u', ' ', (string)($s['name'] ?? '')) ?? '');
            if ($name === '' || mb_strlen($name, 'UTF-8') > 160) return self::err('invalid_stops', 'Every stop needs a name of up to 160 characters.');
            $lat = $s['lat'] ?? null; $lng = $s['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || (float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
                return self::err('invalid_coords', 'A stop has invalid coordinates.');
            }
            $note = isset($s['note']) ? trim((string)$s['note']) : '';
            if (mb_strlen($note, 'UTF-8') > 300) $note = mb_substr($note, 0, 300, 'UTF-8');
            $pid = (isset($s['place_id']) && is_numeric($s['place_id']) && (int)$s['place_id'] > 0) ? (int)$s['place_id'] : null;
            $day = null;
            if (isset($s['day_index']) && $s['day_index'] !== '') {
                if (!is_numeric($s['day_index']) || (int)$s['day_index'] != $s['day_index'] || (int)$s['day_index'] < 1 || (int)$s['day_index'] > self::MAX_DAYS) return self::err('invalid_day', 'A stop has an invalid day.');
                $day = (int)$s['day_index']; $anyDay = true; $maxDay = max($maxDay, $day);
            }
            $arr = self::parseTime($s['arrive_time'] ?? null); $dep = self::parseTime($s['depart_time'] ?? null);
            if ($arr === false || $dep === false) return self::err('invalid_time', 'Times must look like HH:MM.');
            if ($arr !== null && $dep !== null && $dep < $arr) return self::err('invalid_time', 'A stop cannot leave before it arrives.');
            $cost = self::parseCost($s['est_cost'] ?? null);
            if ($cost === false) return self::err('invalid_cost', 'Costs must be zero or more.');
            $key = isset($s['place_key']) && is_string($s['place_key']) && preg_match('/^[a-z]{1,12}:[A-Za-z0-9._:\/\-]{1,100}$/', $s['place_key']) ? $s['place_key'] : null;
            $stops[] = ['place_id' => $pid, 'name' => $name, 'lat' => round((float)$lat, 6), 'lng' => round((float)$lng, 6), 'note' => $note === '' ? null : $note,
                'day_index' => $day, 'arrive_time' => $arr, 'depart_time' => $dep, 'est_cost' => $cost, 'is_done' => !empty($s['is_done']), 'place_key' => $key];
        }
        // day range: dates or day_count decide it; otherwise the trip simply grows to the highest day used
        $start = array_key_exists('start_date', $meta) ? $meta['start_date'] : ($existing['start_date'] ?? null);
        $end = array_key_exists('end_date', $meta) ? $meta['end_date'] : ($existing['end_date'] ?? null);
        $dc = array_key_exists('day_count', $meta) ? $meta['day_count'] : (isset($existing['day_count']) ? (int)$existing['day_count'] : null);
        $span = self::daySpan($start, $end);
        $limit = $span ?? $dc;
        if ($anyDay && $limit !== null && $maxDay > $limit) return self::err('invalid_day', 'A stop is planned on day ' . $maxDay . ' but the trip only has ' . $limit . ' day(s).');
        if ($anyDay && $span === null && ($dc === null || $maxDay > $dc)) $meta['day_count'] = max((int)$dc, $maxDay);
        if ($anyDay) $stops = self::orderByDay($stops);
        $dist = self::nonNegInt($in['total_distance_m'] ?? null);
        $dur  = self::nonNegInt($in['total_duration_s'] ?? null);
        return ['ok' => true, 'data' => ['title' => $title, 'mode' => $mode, 'total_distance_m' => $dist, 'total_duration_s' => $dur,
            'stops' => $stops, 'meta' => $meta, 'has_planner_fields' => $hasPlanner]];
    }

    /** Stable sort by day (missing day counts as day 1; once any stop has a day, every stop gets one). */
    public static function orderByDay(array $stops): array
    {
        foreach ($stops as $i => &$s) { $s['_i'] = $i; $s['day_index'] = (int)($s['day_index'] ?? 1) ?: 1; }
        unset($s);
        usort($stops, static fn($a, $b) => [$a['day_index'], $a['_i']] <=> [$b['day_index'], $b['_i']]);
        foreach ($stops as &$s) unset($s['_i']);
        return array_values($stops);
    }

    /** Legacy (explorer) saves carry no planner fields: copy them from the old stop with the same name and coordinates. */
    public static function mergeLegacy(array $newStops, array $oldStops): array
    {
        $by = [];
        foreach ($oldStops as $o) $by[$o['name'] . '|' . $o['lat'] . '|' . $o['lng']][] = $o;
        foreach ($newStops as &$s) {
            $k = $s['name'] . '|' . $s['lat'] . '|' . $s['lng'];
            if (!empty($by[$k])) {
                $o = array_shift($by[$k]);
                foreach (['day_index', 'arrive_time', 'depart_time', 'est_cost', 'is_done', 'place_key'] as $f) $s[$f] = $o[$f] ?? null;
                if (($s['note'] ?? null) === null) $s['note'] = $o['note'] ?? null;
            }
        }
        unset($s);
        return $newStops;
    }

    private static function dayOf(array $s): int { return (int)($s['day_index'] ?? 1) ?: 1; }

    /** Insert $stop after the last stop of its day (or in day order when that day is empty). */
    private static function insertInDay(array $stops, array $stop): array
    {
        $d = self::dayOf($stop); $at = count($stops);
        foreach ($stops as $i => $s) { if (self::dayOf($s) > $d) { $at = $i; break; } }
        array_splice($stops, $at, 0, [$stop]);
        return $stops;
    }

    /** @return array{ok:true,stops:array,changed:bool}|array{ok:false,error:array} $index is 0-based */
    public static function applyMove(array $stops, int $index, string $dir, ?int $toDay, int $days): array
    {
        $stops = array_values($stops);
        if ($index < 0 || $index >= count($stops)) return self::err('invalid_stop', 'That stop does not exist.');
        foreach ($stops as &$x) $x['day_index'] = self::dayOf($x);
        unset($x);
        if ($toDay !== null) {
            if ($toDay < 1 || $toDay > $days) return self::err('invalid_day', 'That day is outside the trip.');
            if ($toDay === $stops[$index]['day_index']) return ['ok' => true, 'stops' => $stops, 'changed' => false];
            $s = $stops[$index]; array_splice($stops, $index, 1);
            $s['day_index'] = $toDay;
            return ['ok' => true, 'stops' => self::insertInDay($stops, $s), 'changed' => true];
        }
        if ($dir !== 'up' && $dir !== 'down') return self::err('invalid_move', 'Move up, move down or give a day.');
        $step = $dir === 'up' ? -1 : 1; $day = $stops[$index]['day_index'];
        for ($j = $index + $step; $j >= 0 && $j < count($stops); $j += $step) {
            if ($stops[$j]['day_index'] === $day) {
                $t = $stops[$index]; $stops[$index] = $stops[$j]; $stops[$j] = $t;
                return ['ok' => true, 'stops' => $stops, 'changed' => true];
            }
        }
        return ['ok' => true, 'stops' => $stops, 'changed' => false];
    }

    /** Copy the stops of $from to the end of $to. Costs and times are copied, progress flags are reset. */
    public static function duplicateDayStops(array $stops, int $from, int $to, int $days): array
    {
        if ($from < 1 || $from > $days || $to < 1 || $to > $days) return self::err('invalid_day', 'That day is outside the trip.');
        $src = array_values(array_filter($stops, static fn($s) => self::dayOf($s) === $from));
        if (!$src) return self::err('empty_day', 'That day has no stops to copy.');
        if (count($stops) + count($src) > self::MAX_PLANNER_STOPS) return self::err('too_many_stops', 'A trip can have at most ' . self::MAX_PLANNER_STOPS . ' stops.');
        foreach ($stops as &$x) $x['day_index'] = self::dayOf($x);
        unset($x);
        foreach ($src as $s) { $s['day_index'] = $to; $s['is_done'] = false; $stops = self::insertInDay($stops, $s); }
        return ['ok' => true, 'stops' => $stops];
    }

    private static function nonNegInt($v): ?int
    {
        return (is_numeric($v) && (float)$v >= 0 && (float)$v < 2147483647) ? (int)round((float)$v) : null;
    }

    private static function email(string $e): string { return mb_strtolower(trim($e), 'UTF-8'); }

    /** @return array<int,true> ids that exist as active places (empty if the places table is absent) */
    private static function existingPlaceIds(PDO $db, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            $q = $db->prepare("SELECT PlaceId FROM tbl_map_places WHERE Status = 'active' AND PlaceId IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $q->execute($ids);
            return array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) { return []; }
    }

    private static function err(string $code, string $message): array { return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]; }
}
