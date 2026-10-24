<?php
/**
 * SavedPlaces — one unified "saved places" list per signed-in customer (destinations, places, food, services, stays).
 *
 * The owner is ALWAYS the session e-mail passed in by the caller (api/map.php), never anything from the request body.
 * Every query is parameterised and scoped by user_email. (user_email, place_key) is UNIQUE, so saving the same place twice
 * is idempotent. This class stores only what the browser sent after validation: it never invents coordinates or details.
 */
final class SavedPlaces
{
    public const MAX_PER_USER = 500;
    public const KINDS = ['destination', 'place', 'food', 'service', 'stay'];
    /** internal map categories + the Explore Mode categories + destination */
    public const CATEGORIES = [
        'attraction', 'historic', 'museum', 'religious', 'beach', 'mountain', 'waterfall', 'park', 'trail', 'hotel', 'restaurant',
        'market', 'transport', 'visitor_info', 'hospital', 'police', 'other',
        'pharmacy', 'atm', 'gas_station', 'shopping_mall', 'tourist_info', 'destination',
    ];

    private static ?PDO $db = null;

    public static function useDb(?PDO $db): void { self::$db = $db; }

    private static function db(): PDO
    {
        if (self::$db) return self::$db;
        global $dbh;
        if (isset($dbh) && $dbh instanceof PDO) return $dbh;
        throw new RuntimeException('No database connection.');
    }

    // ───────────────────────── pure helpers (unit tested)

    /** Normalised owner key (same rule as MapItinerary). Empty string means "not signed in". */
    public static function ownerKey(string $email): string { return mb_strtolower(trim($email), 'UTF-8'); }

    public static function validKey(string $key): bool
    {
        return (bool)preg_match('/^[a-z]{1,12}:[A-Za-z0-9._:\/\-]{1,100}$/', $key);
    }

    /** Sensible kind for a category when the client did not send one. */
    public static function kindForCategory(string $category): string
    {
        return match ($category) {
            'destination' => 'destination',
            'hotel' => 'stay',
            'restaurant' => 'food',
            'hospital', 'police', 'pharmacy', 'atm', 'gas_station', 'tourist_info', 'visitor_info', 'transport' => 'service',
            default => 'place',
        };
    }

    /** @return array{ok:true,data:array}|array{ok:false,error:array{code:string,message:string}} */
    public static function validate(array $in): array
    {
        $key = is_string($in['place_key'] ?? null) ? trim($in['place_key']) : '';
        if (!self::validKey($key)) return self::err('invalid_key', 'This place cannot be saved because it has no stable identifier.');
        $name = trim(preg_replace('/\s+/u', ' ', is_string($in['name'] ?? null) ? $in['name'] : '') ?? '');
        $nlen = mb_strlen($name, 'UTF-8');
        if ($nlen < 1 || $nlen > 160) return self::err('invalid_name', 'A saved place needs a name of up to 160 characters.');
        $lat = $in['lat'] ?? null; $lng = $in['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || (float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180
            || ((float)$lat == 0.0 && (float)$lng == 0.0)) {
            return self::err('invalid_coords', 'The place has invalid coordinates.');
        }
        $category = is_string($in['category'] ?? null) ? strtolower(trim($in['category'])) : 'other';
        if ($category === '') $category = 'other';
        if (!in_array($category, self::CATEGORIES, true)) return self::err('invalid_category', 'Unknown place category.');
        $kind = is_string($in['kind'] ?? null) ? strtolower(trim($in['kind'])) : '';
        if ($kind === '') $kind = self::kindForCategory($category);
        if (!in_array($kind, self::KINDS, true)) return self::err('invalid_kind', 'Unknown place kind.');
        return ['ok' => true, 'data' => [
            'place_key' => $key, 'kind' => $kind, 'name' => $name, 'category' => $category,
            'lat' => round((float)$lat, 6), 'lng' => round((float)$lng, 6),
            'country' => self::text($in['country'] ?? null, 100), 'locality' => self::text($in['locality'] ?? null, 150),
            'destination_name' => self::text($in['destination_name'] ?? null, 160), 'note' => self::text($in['note'] ?? null, 300),
        ]];
    }

    private static function text($v, int $max): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
        if ($v === '') return null;
        return mb_substr($v, 0, $max, 'UTF-8');
    }

    // ───────────────────────── database

    /** @return list<array> newest first */
    public static function list(string $email, ?string $category = null, int $limit = 200): array
    {
        $limit = max(1, min(self::MAX_PER_USER, $limit));
        $sql = 'SELECT place_key, kind, name, category, lat, lng, country, locality, destination_name, note, created_at
                FROM tbl_saved_places WHERE user_email = ?';
        $args = [self::ownerKey($email)];
        if ($category !== null && $category !== '') { $sql .= ' AND category = ?'; $args[] = $category; }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;   // $limit is a clamped int
        $st = self::db()->prepare($sql);
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['place_key' => (string)$r['place_key'], 'kind' => (string)$r['kind'], 'name' => (string)$r['name'],
                'category' => (string)$r['category'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
                'country' => $r['country'], 'locality' => $r['locality'], 'destination_name' => $r['destination_name'],
                'note' => $r['note'], 'created_at' => (string)$r['created_at']];
        }
        return $out;
    }

    /** @return array{ok:true,saved:bool,created:bool}|array{ok:false,error:array} idempotent: an existing row is kept as it is */
    public static function save(string $email, array $payload): array
    {
        $owner = self::ownerKey($email);
        if ($owner === '') return self::err('auth_required', 'Please sign in to save places.');
        $v = self::validate($payload);
        if (!$v['ok']) return $v;
        $d = $v['data'];
        $db = self::db();
        try {
            if (self::isSaved($owner, $d['place_key'])) return ['ok' => true, 'saved' => true, 'created' => false];
            $cnt = $db->prepare('SELECT COUNT(*) FROM tbl_saved_places WHERE user_email = ?');
            $cnt->execute([$owner]);
            if ((int)$cnt->fetchColumn() >= self::MAX_PER_USER) {
                return self::err('limit_reached', 'You can save up to ' . self::MAX_PER_USER . ' places. Remove some first.');
            }
            // INSERT IGNORE + the UNIQUE key make a double click or two tabs racing harmless
            $ins = $db->prepare('INSERT IGNORE INTO tbl_saved_places (user_email, place_key, kind, name, category, lat, lng, country, locality, destination_name, note)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$owner, $d['place_key'], $d['kind'], $d['name'], $d['category'], $d['lat'], $d['lng'],
                $d['country'], $d['locality'], $d['destination_name'], $d['note']]);
            return ['ok' => true, 'saved' => true, 'created' => $ins->rowCount() > 0];
        } catch (Throwable $e) {
            error_log('[TourBuddy SavedPlaces] save failed: ' . $e->getMessage());
            return self::err('save_failed', 'The place could not be saved.');
        }
    }

    public static function remove(string $email, string $placeKey): bool
    {
        if (!self::validKey($placeKey)) return false;
        $st = self::db()->prepare('DELETE FROM tbl_saved_places WHERE user_email = ? AND place_key = ?');
        $st->execute([self::ownerKey($email), $placeKey]);
        return $st->rowCount() > 0;
    }

    public static function isSaved(string $email, string $placeKey): bool
    {
        $st = self::db()->prepare('SELECT 1 FROM tbl_saved_places WHERE user_email = ? AND place_key = ? LIMIT 1');
        $st->execute([self::ownerKey($email), $placeKey]);
        return (bool)$st->fetchColumn();
    }

    private static function err(string $code, string $message): array { return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]; }
}
