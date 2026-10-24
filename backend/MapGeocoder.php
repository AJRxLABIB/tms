<?php
/**
 * MapGeocoder — server-side Nominatim (or compatible) search, used ONLY for an explicit "search" submit.
 *
 * Follows https://operations.osmfoundation.org/policies/nominatim/ : an identifying User-Agent (MapConfig::userAgent(), set
 * MAP_CONTACT_EMAIL), at most 1 request per second across the whole site, results cached for 24 hours, and NO
 * search-as-you-type use (autocomplete is served from the site's own database by api/map.php). The endpoint is configurable
 * (MAP_GEOCODER_URL), so a self-hosted Nominatim or Photon instance can replace the public one without code changes.
 */
final class MapGeocoder
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 200;
    private const TTL_OK   = 86400;
    private const TTL_FAIL = 60;
    private const PER_IP_PER_MINUTE = 10;

    /** Test hooks (see MapRouting). */
    protected static $transport = null;
    protected static ?object $cacheStore = null;
    /** fn(): float  seconds clock, fn(float): void sleeper, fn(): string client id. */
    protected static $clock = null;
    protected static $sleeper = null;
    protected static ?string $clientId = null;
    protected static ?string $lockFile = null;

    /**
     * @param array|null $bias optional ['lng'=>float,'lat'=>float,'lang'=>'bn'|'en']
     */
    public static function search(string $q, ?array $bias, int $limit = 5): array
    {
        $q = self::normalise($q);
        $len = mb_strlen($q, 'UTF-8');
        if ($len < self::MIN_LENGTH) return self::err('query_too_short', 'Type at least ' . self::MIN_LENGTH . ' characters to search the wider map.');
        if ($len > self::MAX_LENGTH) return self::err('query_too_long', 'That search is too long.');
        $limit = max(1, min(10, $limit));
        $lang = (($bias['lang'] ?? 'en') === 'bn') ? 'bn,en' : 'en';
        $countries = self::countries();

        $viewbox = '';
        if ($bias && isset($bias['lng'], $bias['lat']) && is_numeric($bias['lng']) && is_numeric($bias['lat'])) {
            $lng = (float)$bias['lng']; $lat = (float)$bias['lat'];
            if ($lng >= -180 && $lng <= 180 && $lat >= -90 && $lat <= 90) {
                $viewbox = sprintf('%.4f,%.4f,%.4f,%.4f', max(-180, $lng - 0.5), min(90, $lat + 0.5), min(180, $lng + 0.5), max(-90, $lat - 0.5));
            }
        }

        $cache = self::cache();
        $mtItems = [];
        // Google Places (when GOOGLE_PLACES_API_KEY is set) is asked first; its results are never cached or stored (Google's terms).
        $gkey = self::googleKey();
        if ($gkey !== '' && self::googleBudgetOk($cache)) {
            if ($cache && !self::withinClientCap($cache)) return self::err('rate_limited', 'Too many searches. Please wait a minute.');
            $g = self::google($gkey, $q, $limit, $lang, $bias);
            if (!empty($g['ok']) && !empty($g['items'])) return $g;
        }
        // MapTiler Geocoding (free tier, no card needed) comes next; not cached either, to respect its terms.
        // Server-side key: MAPTILER_SERVER_KEY (no origin restriction) if set, else the browser key.
        $mkey = self::maptilerServerKey();
        if ($mkey !== '' && self::maptilerBudgetOk($cache)) {
            if ($cache && !self::withinClientCap($cache)) return self::err('rate_limited', 'Too many searches. Please wait a minute.');
            $m = self::maptiler($mkey, $q, $limit, $lang, $bias);
            if (!empty($m['ok']) && !empty($m['items'])) $mtItems = $m['items'];
        }
        $key = 'mapgeo:' . sha1(mb_strtolower($q, 'UTF-8') . '|' . $limit . '|' . $lang . '|' . $countries . '|' . $viewbox . '|' . self::base());
        if ($cache) {
            $hit = self::cacheGet($cache, $key);
            if (is_array($hit)) return $mtItems ? self::mergeItems($q, $mtItems, !empty($hit['ok']) ? $hit['items'] : [], $limit) : $hit;
            $neg = self::cacheGet($cache, $key . ':fail');
            if (is_array($neg)) return $neg;
            // Per-client cap applies only to calls that would reach the external service.
            if (!self::withinClientCap($cache)) return self::err('rate_limited', 'Too many searches. Please wait a minute.');
        }

        $params = ['q' => $q, 'format' => 'jsonv2', 'limit' => $limit, 'addressdetails' => 0, 'accept-language' => $lang];
        if ($countries !== '') $params['countrycodes'] = $countries;
        if ($viewbox !== '') $params['viewbox'] = $viewbox;
        $url = self::base() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // The public Nominatim server often refuses (HTTP 403) unknown hosts. After one refusal go straight to Photon for an hour
        // instead of making every search wait for a call that will fail.
        $blockedKey = 'mapgeo:nominatim_blocked';
        $blocked = $cache ? is_array(self::cacheGet($cache, $blockedKey)) : false;
        $out = null;
        if ($blocked) {
            $pho = self::photon($q, $limit, $lang, $countries, $bias);
            if (!empty($pho['ok'])) $out = $pho;
        }
        if ($out === null) {
            self::throttle();
            $res = self::http($url);
            $out = $res === null
                ? self::err('upstream_error', 'The search service could not be reached.')
                : self::parse($res['status'], $res['body'], $limit);
            if (empty($out['ok'])) {
                if ($cache && $res !== null && in_array((int)($res['status'] ?? 0), [403, 429], true)) self::cacheSet($cache, $blockedKey, ['blocked' => true], 3600);
                // Nominatim refused or failed: try Photon, a second free OpenStreetMap geocoder.
                $pho = self::photon($q, $limit, $lang, $countries, $bias);
                if (!empty($pho['ok'])) $out = $pho;
            }
        }
        if ($cache) self::cacheSet($cache, $out['ok'] ? $key : $key . ':fail', $out, $out['ok'] ? self::TTL_OK : self::TTL_FAIL);
        if ($mtItems) return self::mergeItems($q, $mtItems, !empty($out['ok']) ? $out['items'] : [], $limit);
        return $out;
    }

    /** Daily cap for MapTiler geocoding calls (free tier is 100k/month). MAPTILER_DAILY_LIMIT, default 2000. */
    private static function maptilerBudgetOk(?object $cache): bool
    {
        if (!$cache) return true;
        $lim = (int)(function_exists('tms_env') ? tms_env('MAPTILER_DAILY_LIMIT', '2000') : 2000);
        if ($lim <= 0) $lim = 2000;
        if (is_array(self::cacheGet($cache, 'mapmt:off'))) return false;
        $k = 'mapmt:day:' . gmdate('Ymd');
        $n = (int)(self::cacheGet($cache, $k) ?? 0);
        if ($n >= $lim) return false;
        self::cacheSet($cache, $k, $n + 1, 90000);
        return true;
    }

    /** Merges MapTiler and OSM-geocoder results: de-duplicated by name+distance, best name match to the query first. */
    public static function mergeItems(string $q, array $a, array $b, int $limit): array
    {
        $norm = fn($t) => preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string)$t, 'UTF-8'));
        $qn = trim($norm($q));
        $score = function ($it) use ($norm, $qn) {
            $n = trim($norm($it['name'] ?? ''));
            if ($n === $qn) return 3;
            if ($n !== '' && ($n === $qn . ' ' || strpos($n, $qn) === 0)) return 2;
            if ($n !== '' && $qn !== '' && strpos($n, $qn) !== false) return 1.5;
            if ($n !== '' && strpos($qn, $n) !== false) return 1;
            return 0;
        };
        $all = [];
        foreach (array_merge($b, $a) as $i => $it) {      // OSM items first: they carry POI/landmark names
            foreach ($all as $e) {
                if (abs($e['lat'] - $it['lat']) < 0.003 && abs($e['lng'] - $it['lng']) < 0.003) continue 2;
            }
            $it['_s'] = $score($it); $it['_i'] = $i; $all[] = $it;
        }
        usort($all, fn($x, $y) => $y['_s'] <=> $x['_s'] ?: $x['_i'] <=> $y['_i']);
        $all = array_slice($all, 0, $limit);
        foreach ($all as &$it) unset($it['_s'], $it['_i']);
        return ['ok' => true, 'items' => $all, 'source' => 'maptiler'];
    }

    private static function maptilerServerKey(): string
    {
        $k = function_exists('tms_env') ? tms_env('MAPTILER_SERVER_KEY', '') : (getenv('MAPTILER_SERVER_KEY') ?: '');
        $k = is_string($k) ? trim($k) : '';
        if (preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k)) return $k;
        return class_exists('MapConfig') ? MapConfig::maptilerKey() : '';
    }

    private static function maptiler(string $key, string $q, int $limit, string $lang, ?array $bias): array
    {
        $url = 'https://api.maptiler.com/geocoding/' . rawurlencode($q) . '.json?key=' . rawurlencode($key)
             . '&limit=' . max(1, min(10, $limit)) . '&language=' . (substr($lang, 0, 2) === 'bn' ? 'bn,en' : 'en');
        if ($bias && isset($bias['lng'], $bias['lat']) && is_numeric($bias['lng']) && is_numeric($bias['lat'])) $url .= '&proximity=' . (float)$bias['lng'] . ',' . (float)$bias['lat'];
        // The key is origin-restricted in the MapTiler dashboard; a server call has no browser origin, so present this site's own.
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
        $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $host;
        $res = self::http($url, ['headers' => ['Origin: ' . $origin, 'Referer: ' . $origin . '/']]);
        if ($res === null) return self::err('upstream_error', 'The search service could not be reached.');
        if (in_array((int)$res['status'], [401, 403], true) && ($c = self::cache())) self::cacheSet($c, 'mapmt:off', ['off' => true], 600);
        return self::parseMaptiler($res['status'], $res['body'], $limit);
    }

    /** Pure: parses a MapTiler Geocoding (GeoJSON) answer into the common item shape. */
    public static function parseMaptiler(int $status, string $body, int $limit = 5): array
    {
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($j)) {
            error_log('[TourBuddy geocoder] MapTiler HTTP ' . $status);
            return self::err('upstream_error', 'The search service returned an unusable answer.');
        }
        $items = [];
        foreach ((array)($j['features'] ?? []) as $f) {
            $c = $f['center'] ?? ($f['geometry']['coordinates'] ?? null);
            if (!is_array($c) || count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1])) continue;
            $lng = (float)$c[0]; $lat = (float)$c[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $name = trim((string)($f['text'] ?? ''));
            $disp = trim((string)($f['place_name'] ?? ''));
            if ($name === '' && $disp === '') continue;
            $item = ['name' => $name !== '' ? $name : $disp, 'display' => $disp !== '' ? $disp : $name, 'lat' => $lat, 'lng' => $lng,
                     'type' => (string)(($f['place_type'][0] ?? '') ?: ''), 'source' => 'maptiler'];
            $b = $f['bbox'] ?? null;
            if (is_array($b) && count($b) === 4 && array_reduce($b, fn($a, $x) => $a && is_numeric($x), true)) $item['bbox'] = array_map('floatval', $b);
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return ['ok' => true, 'items' => $items, 'source' => 'maptiler'];
    }

    private static function googleKey(): string
    {
        $k = function_exists('tms_env') ? tms_env('GOOGLE_PLACES_API_KEY', '') : (getenv('GOOGLE_PLACES_API_KEY') ?: '');
        $k = is_string($k) ? trim($k) : '';
        return preg_match('/^[A-Za-z0-9_\-]{20,80}$/', $k) ? $k : '';
    }

    /** Server-wide daily cap on Google calls, so a loop or a crawler can never run up a bill. GOOGLE_PLACES_DAILY_LIMIT, default 300. */
    private static function googleBudgetOk(?object $cache): bool
    {
        if (!$cache) return true;
        $lim = (int)(function_exists('tms_env') ? tms_env('GOOGLE_PLACES_DAILY_LIMIT', '300') : 300);
        if ($lim <= 0) $lim = 300;
        if (is_array(self::cacheGet($cache, 'mapgoogle:off'))) return false;   // key refused recently (billing/permission): skip for an hour
        $k = 'mapgoogle:day:' . gmdate('Ymd');
        $n = (int)(self::cacheGet($cache, $k) ?? 0);
        if ($n >= $lim) return false;
        self::cacheSet($cache, $k, $n + 1, 90000);
        return true;
    }

    /** Google Places API (New) Text Search. Asks only for name, address, location and type (the cheapest field set). */
    private static function google(string $key, string $q, int $limit, string $lang, ?array $bias): array
    {
        $body = ['textQuery' => $q, 'maxResultCount' => $limit, 'languageCode' => substr($lang, 0, 2) === 'bn' ? 'bn' : 'en'];
        if ($bias && isset($bias['lng'], $bias['lat']) && is_numeric($bias['lng']) && is_numeric($bias['lat'])) {
            $body['locationBias'] = ['circle' => ['center' => ['latitude' => (float)$bias['lat'], 'longitude' => (float)$bias['lng']], 'radius' => 50000.0]];
        }
        $res = self::http('https://places.googleapis.com/v1/places:searchText', [
            'post' => json_encode($body),
            'headers' => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.location,places.primaryType,places.viewport'],
        ]);
        if ($res === null) return self::err('upstream_error', 'The search service could not be reached.');
        if (in_array((int)$res['status'], [401, 403], true) && ($c = self::cache())) self::cacheSet($c, 'mapgoogle:off', ['off' => true], 3600);
        return self::parseGoogle($res['status'], $res['body'], $limit);
    }

    /** Pure: parses a Places API (New) searchText answer into the common item shape. */
    public static function parseGoogle(int $status, string $body, int $limit = 5): array
    {
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($j)) {
            error_log('[TourBuddy geocoder] Google Places HTTP ' . $status . ': ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 200));
            return self::err('upstream_error', 'The search service returned an unusable answer' . ($status >= 400 ? ' (HTTP ' . $status . ')' : '') . '.');
        }
        $items = [];
        foreach ((array)($j['places'] ?? []) as $p) {
            $lat = $p['location']['latitude'] ?? null; $lng = $p['location']['longitude'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $name = trim((string)($p['displayName']['text'] ?? ''));
            $addr = trim((string)($p['formattedAddress'] ?? ''));
            if ($name === '' && $addr === '') continue;
            $item = ['name' => $name !== '' ? $name : $addr, 'display' => $addr !== '' ? $addr : $name, 'lat' => (float)$lat, 'lng' => (float)$lng,
                     'type' => (string)($p['primaryType'] ?? ''), 'source' => 'google'];
            $v = $p['viewport'] ?? null;
            if (is_array($v) && isset($v['low']['latitude'], $v['low']['longitude'], $v['high']['latitude'], $v['high']['longitude'])) $item['bbox'] = [(float)$v['low']['longitude'], (float)$v['low']['latitude'], (float)$v['high']['longitude'], (float)$v['high']['latitude']];
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return ['ok' => true, 'items' => $items, 'source' => 'google'];
    }

    /** Second provider (Photon, OpenStreetMap data, free fair use). Returns the same shape as parse(). */
    private static function photon(string $q, int $limit, string $lang, string $countries, ?array $bias): array
    {
        $params = ['q' => $q, 'limit' => $limit, 'lang' => in_array(substr($lang, 0, 2), ['en', 'de', 'fr', 'it'], true) ? substr($lang, 0, 2) : 'en'];
        if ($countries === 'bd') $params['bbox'] = '88.0,20.5,92.7,26.7';
        if ($bias && isset($bias['lng'], $bias['lat']) && is_numeric($bias['lng']) && is_numeric($bias['lat'])) { $params['lon'] = (float)$bias['lng']; $params['lat'] = (float)$bias['lat']; }
        $res = self::http('https://photon.komoot.io/api/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        if ($res === null) return self::err('upstream_error', 'The search service could not be reached.');
        return self::parsePhoton($res['status'], $res['body'], $limit);
    }

    /** Pure: parses a Photon GeoJSON FeatureCollection into the same items parse() returns. */
    public static function parsePhoton(int $status, string $body, int $limit = 5): array
    {
        if ($status === 429) return self::err('rate_limited', 'The search service is busy. Please try again shortly.');
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($j) || !isset($j['features']) || !is_array($j['features'])) return self::err('upstream_error', 'The search service returned an unusable answer.');
        $items = [];
        foreach ($j['features'] as $f) {
            $c = $f['geometry']['coordinates'] ?? null;
            if (!is_array($c) || count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1])) continue;
            $lng = (float)$c[0]; $lat = (float)$c[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $pr = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            $name = trim((string)($pr['name'] ?? ''));
            $parts = array_values(array_filter(array_unique(array_map(static fn($k) => trim((string)($pr[$k] ?? '')), ['name', 'district', 'city', 'state', 'country'])), static fn($v) => $v !== ''));
            if ($name === '' && !$parts) continue;
            $item = ['name' => $name !== '' ? $name : $parts[0], 'display' => implode(', ', $parts), 'lat' => $lat, 'lng' => $lng,
                     'type' => (string)($pr['osm_key'] ?? '') . (isset($pr['osm_value']) ? ':' . $pr['osm_value'] : ''), 'source' => 'photon'];
            $ex = $pr['extent'] ?? null;   // [west, north, east, south]
            if (is_array($ex) && count($ex) === 4 && count(array_filter($ex, 'is_numeric')) === 4) {
                [$w, $n, $e, $sv] = array_map('floatval', $ex);
                if ($sv >= -90 && $n <= 90 && $w >= -180 && $e <= 180 && $sv <= $n && $w <= $e) $item['bbox'] = [$w, $sv, $e, $n];
            }
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return ['ok' => true, 'items' => $items, 'source' => 'photon'];
    }

    /** Pure: trims, collapses whitespace, strips control characters. */
    public static function normalise(string $q): string
    {
        $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q) ?? '';
        $q = preg_replace('/\s+/u', ' ', $q) ?? '';
        return trim($q);
    }

    /** Pure: parses a Nominatim jsonv2 array. Invalid or out-of-range coordinates are dropped. */
    public static function parse(int $status, string $body, int $limit = 5): array
    {
        if ($status === 429) return self::err('rate_limited', 'The search service is busy. Please try again shortly.');
        $j = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($j) || (array_keys($j) !== range(0, count($j) - 1) && $j !== [])) {
            // Say WHY (status code) so a blocked or misconfigured install can be fixed; the body is only logged, never shown.
            error_log('[TourBuddy geocoder] upstream HTTP ' . $status . ': ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 160));
            $why = $status === 403 ? ' (HTTP 403: the provider refused this server, check MAP_CONTACT_EMAIL and the network)' : ($status >= 400 ? ' (HTTP ' . $status . ')' : '');
            return self::err('upstream_error', 'The search service returned an unusable answer' . $why . '.');
        }
        $items = [];
        foreach ($j as $r) {
            if (!is_array($r) || !isset($r['lat'], $r['lon']) || !is_numeric($r['lat']) || !is_numeric($r['lon'])) continue;
            $lat = (float)$r['lat']; $lng = (float)$r['lon'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
            $display = trim((string)($r['display_name'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') $name = trim(explode(',', $display)[0] ?? '');
            if ($name === '' && $display === '') continue;
            $item = [
                'name' => $name, 'display' => $display !== '' ? $display : $name, 'lat' => $lat, 'lng' => $lng,
                'type' => (string)($r['category'] ?? '') . ($r['type'] ?? '' ? ':' . $r['type'] : ''), 'source' => 'nominatim',
            ];
            $bb = $r['boundingbox'] ?? null;   // [south, north, west, east]
            if (is_array($bb) && count($bb) === 4 && count(array_filter($bb, 'is_numeric')) === 4) {
                [$s, $n, $w, $e] = array_map('floatval', $bb);
                if ($s >= -90 && $n <= 90 && $w >= -180 && $e <= 180 && $s <= $n && $w <= $e) $item['bbox'] = [$w, $s, $e, $n];
            }
            $items[] = $item;
            if (count($items) >= $limit) break;
        }
        return ['ok' => true, 'items' => $items, 'source' => 'nominatim'];
    }

    private static function base(): string
    {
        return class_exists('MapConfig') ? MapConfig::geocoderUrl() : 'https://nominatim.openstreetmap.org/search';
    }

    private static function countries(): string
    {
        $v = function_exists('tms_env') ? tms_env('MAP_GEOCODER_COUNTRIES', 'any') : (getenv('MAP_GEOCODER_COUNTRIES') ?: 'any');   // worldwide by default; e.g. 'bd' or 'bd,in' restricts
        $v = is_string($v) ? strtolower(trim($v)) : 'any';
        if ($v === 'any' || $v === '') return '';
        return preg_match('/^[a-z]{2}(,[a-z]{2})*$/', $v) ? $v : '';
    }

    /** Global 1 request/second across processes (file lock + last-call timestamp). */
    private static function throttle(): void
    {
        $now = self::$clock ? (self::$clock)() : microtime(true);
        $file = self::$lockFile ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tms_geocoder_throttle.lock');
        $fh = @fopen($file, 'c+');
        if (!$fh) return;
        if (@flock($fh, LOCK_EX)) {
            $last = (float)trim((string)stream_get_contents($fh));
            $wait = ($last + 1.0) - $now;
            if ($wait > 0) {
                if (self::$sleeper) (self::$sleeper)($wait); else usleep((int)min(1000000, $wait * 1000000));
                $now = self::$clock ? (self::$clock)() : microtime(true);
            }
            ftruncate($fh, 0); rewind($fh); fwrite($fh, sprintf('%.3f', $now));
            fflush($fh); flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    private static function withinClientCap(object $cache): bool
    {
        $id = self::$clientId ?? (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $k = 'mapgeo:cap:' . sha1($id) . ':' . gmdate('YmdHi');
        $n = (int)(self::cacheGet($cache, $k) ?? 0);
        if ($n >= self::PER_IP_PER_MINUTE) return false;
        self::cacheSet($cache, $k, $n + 1, 90);
        return true;
    }

    private static function http(string $url, array $extra = []): ?array
    {
        $ua = class_exists('MapConfig') ? MapConfig::userAgent() : 'TourBuddy-TourismManagement/1.0';
        $opts = ['connect_timeout' => 3, 'timeout' => 8, 'max_body' => 1048576, 'user_agent' => $ua] + $extra;
        if (self::$transport) return (self::$transport)($url, $opts);
        if (!function_exists('curl_init') || stripos($url, 'https://') !== 0) return null;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], (array)($extra['headers'] ?? [])), CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf, $opts) { $buf .= $d; return strlen($buf) > $opts['max_body'] ? 0 : strlen($d); },
        ]);
        if (isset($extra['post'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$extra['post']); }
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $ok === false ? null : ['status' => $status, 'body' => $buf];
    }

    private static function err(string $code, string $message): array { return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]; }

    private static function cache(): ?object
    {
        if (self::$cacheStore) return self::$cacheStore;
        global $dbh;
        if (isset($dbh) && $dbh instanceof PDO && class_exists('CacheService')) {
            try { return new CacheService($dbh, false); } catch (Throwable $e) { return null; }
        }
        return null;
    }
    private static function cacheGet(object $c, string $k) { try { return $c->get($k); } catch (Throwable $e) { return null; } }
    private static function cacheSet(object $c, string $k, $v, int $ttl): void { try { $c->set($k, $v, $ttl); } catch (Throwable $e) {} }

    /** Test helper. */
    public static function _setTestHooks($transport, ?object $cache, $clock = null, $sleeper = null, ?string $clientId = null, ?string $lockFile = null): void
    {
        self::$transport = $transport; self::$cacheStore = $cache; self::$clock = $clock; self::$sleeper = $sleeper;
        self::$clientId = $clientId; self::$lockFile = $lockFile;
    }
}
