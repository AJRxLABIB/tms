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

    private static ?PDO $db = null;

    public static function useDb(?PDO $db): void { self::$db = $db; }

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
        $st = self::db()->prepare(
            'SELECT i.id, i.title, i.mode, i.total_distance_m, i.total_duration_s, i.updated_at,
                    (SELECT COUNT(*) FROM tbl_map_itinerary_stops s WHERE s.itinerary_id = i.id) AS stop_count
             FROM tbl_map_itineraries i WHERE i.user_email = ? ORDER BY i.updated_at DESC, i.id DESC LIMIT 50'
        );
        $st->execute([self::email($email)]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'mode' => (string)$r['mode'],
                'total_distance_m' => $r['total_distance_m'] === null ? null : (int)$r['total_distance_m'],
                'total_duration_s' => $r['total_duration_s'] === null ? null : (int)$r['total_duration_s'],
                'stop_count' => (int)$r['stop_count'], 'updated_at' => (string)$r['updated_at']];
        }
        return $out;
    }

    public static function get(string $email, int $id): ?array
    {
        $db = self::db();
        $st = $db->prepare('SELECT id, title, mode, total_distance_m, total_duration_s, updated_at FROM tbl_map_itineraries WHERE id = ? AND user_email = ?');
        $st->execute([$id, self::email($email)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $ss = $db->prepare('SELECT position, place_id, name, lat, lng, note FROM tbl_map_itinerary_stops WHERE itinerary_id = ? ORDER BY position');
        $ss->execute([$id]);
        $stops = [];
        foreach ($ss->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $stops[] = ['position' => (int)$s['position'], 'place_id' => $s['place_id'] === null ? null : (int)$s['place_id'],
                'name' => (string)$s['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'note' => $s['note'] === null ? null : (string)$s['note']];
        }
        return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'mode' => (string)$r['mode'],
            'total_distance_m' => $r['total_distance_m'] === null ? null : (int)$r['total_distance_m'],
            'total_duration_s' => $r['total_duration_s'] === null ? null : (int)$r['total_duration_s'],
            'updated_at' => (string)$r['updated_at'], 'stops' => $stops];
    }

    /**
     * @param array $input id?, title, mode?, total_distance_m?, total_duration_s?, stops: [{place_id?, name, lat, lng, note?}]
     * @return array ['ok'=>true,'id'=>int] | ['ok'=>false,'error'=>['code','message']]
     */
    public static function save(string $email, array $input): array
    {
        $email = self::email($email);
        if ($email === '') return self::err('auth_required', 'Please sign in to save an itinerary.');
        $v = self::validate($input);
        if (!$v['ok']) return $v;
        $d = $v['data'];
        $db = self::db();
        try {
            $db->beginTransaction();
            $id = (int)($input['id'] ?? 0);
            if ($id > 0) {
                $own = $db->prepare('SELECT id FROM tbl_map_itineraries WHERE id = ? AND user_email = ? FOR UPDATE');
                $own->execute([$id, $email]);
                if (!$own->fetchColumn()) { $db->rollBack(); return self::err('not_found', 'Itinerary not found.'); }
                $db->prepare('UPDATE tbl_map_itineraries SET title = ?, mode = ?, total_distance_m = ?, total_duration_s = ? WHERE id = ? AND user_email = ?')
                   ->execute([$d['title'], $d['mode'], $d['total_distance_m'], $d['total_duration_s'], $id, $email]);
                $db->prepare('DELETE FROM tbl_map_itinerary_stops WHERE itinerary_id = ?')->execute([$id]);
            } else {
                $cnt = $db->prepare('SELECT COUNT(*) FROM tbl_map_itineraries WHERE user_email = ?');
                $cnt->execute([$email]);
                if ((int)$cnt->fetchColumn() >= self::MAX_PER_USER) { $db->rollBack(); return self::err('limit_reached', 'You can keep up to ' . self::MAX_PER_USER . ' saved itineraries. Delete one first.'); }
                $db->prepare('INSERT INTO tbl_map_itineraries (user_email, title, mode, total_distance_m, total_duration_s) VALUES (?,?,?,?,?)')
                   ->execute([$email, $d['title'], $d['mode'], $d['total_distance_m'], $d['total_duration_s']]);
                $id = (int)$db->lastInsertId();
            }
            $known = self::existingPlaceIds($db, array_filter(array_column($d['stops'], 'place_id')));
            $ins = $db->prepare('INSERT INTO tbl_map_itinerary_stops (itinerary_id, position, place_id, name, lat, lng, note) VALUES (?,?,?,?,?,?,?)');
            foreach ($d['stops'] as $i => $s) {
                $pid = ($s['place_id'] !== null && isset($known[$s['place_id']])) ? $s['place_id'] : null;
                $ins->execute([$id, $i + 1, $pid, $s['name'], $s['lat'], $s['lng'], $s['note']]);
            }
            $db->commit();
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[TourBuddy MapItinerary] save failed: ' . $e->getMessage());
            return self::err('save_failed', 'The itinerary could not be saved.');
        }
    }

    public static function delete(string $email, int $id): bool
    {
        $st = self::db()->prepare('DELETE FROM tbl_map_itineraries WHERE id = ? AND user_email = ?');   // stops cascade
        $st->execute([$id, self::email($email)]);
        return $st->rowCount() > 0;
    }

    /** Pure validation + normalisation. Stop order is the order given. */
    public static function validate(array $in): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? '')) ?? '');
        $len = mb_strlen($title, 'UTF-8');
        if ($len < 1 || $len > 120) return self::err('invalid_title', 'Give the itinerary a name of up to 120 characters.');
        $mode = strtolower((string)($in['mode'] ?? 'driving'));
        if (!in_array($mode, ['driving', 'walking', 'cycling'], true)) return self::err('invalid_mode', 'Unknown travel mode.');
        $stopsIn = $in['stops'] ?? null;
        if (!is_array($stopsIn) || count($stopsIn) < 1) return self::err('invalid_stops', 'Add at least one stop.');
        if (count($stopsIn) > self::MAX_STOPS) return self::err('too_many_stops', 'An itinerary can have at most ' . self::MAX_STOPS . ' stops.');
        $stops = [];
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
            $stops[] = ['place_id' => $pid, 'name' => $name, 'lat' => round((float)$lat, 6), 'lng' => round((float)$lng, 6), 'note' => $note === '' ? null : $note];
        }
        $dist = self::nonNegInt($in['total_distance_m'] ?? null);
        $dur  = self::nonNegInt($in['total_duration_s'] ?? null);
        return ['ok' => true, 'data' => ['title' => $title, 'mode' => $mode, 'total_distance_m' => $dist, 'total_duration_s' => $dur, 'stops' => $stops]];
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
