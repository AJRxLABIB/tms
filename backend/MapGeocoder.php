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
        $key = 'mapgeo:' . sha1(mb_strtolower($q, 'UTF-8') . '|' . $limit . '|' . $lang . '|' . $countries . '|' . $viewbox . '|' . self::base());
        if ($cache) {
            $hit = self::cacheGet($cache, $key);
            if (is_array($hit)) return $hit;
            $neg = self::cacheGet($cache, $key . ':fail');
            if (is_array($neg)) return $neg;
            // Per-client cap applies only to calls that would reach the external service.
            if (!self::withinClientCap($cache)) return self::err('rate_limited', 'Too many searches. Please wait a minute.');
        }

        $params = ['q' => $q, 'format' => 'jsonv2', 'limit' => $limit, 'addressdetails' => 0, 'accept-language' => $lang];
        if ($countries !== '') $params['countrycodes'] = $countries;
        if ($viewbox !== '') $params['viewbox'] = $viewbox;
        $url = self::base() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        self::throttle();
        $res = self::http($url);
        if ($res === null) {
            $out = self::err('upstream_error', 'The search service could not be reached.');
            if ($cache) self::cacheSet($cache, $key . ':fail', $out, self::TTL_FAIL);
            return $out;
        }
        $out = self::parse($res['status'], $res['body'], $limit);
        if ($cache) self::cacheSet($cache, $out['ok'] ? $key : $key . ':fail', $out, $out['ok'] ? self::TTL_OK : self::TTL_FAIL);
        return $out;
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
            return self::err('upstream_error', 'The search service returned an unusable answer.');
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
        $v = function_exists('tms_env') ? tms_env('MAP_GEOCODER_COUNTRIES', 'bd') : (getenv('MAP_GEOCODER_COUNTRIES') ?: 'bd');
        $v = is_string($v) ? strtolower(trim($v)) : 'bd';
        if ($v === 'any' || $v === '') return '';
        return preg_match('/^[a-z]{2}(,[a-z]{2})*$/', $v) ? $v : 'bd';
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

    private static function http(string $url): ?array
    {
        $ua = class_exists('MapConfig') ? MapConfig::userAgent() : 'TourBuddy-TourismManagement/1.0';
        $opts = ['connect_timeout' => 3, 'timeout' => 8, 'max_body' => 1048576, 'user_agent' => $ua];
        if (self::$transport) return (self::$transport)($url, $opts);
        if (!function_exists('curl_init') || stripos($url, 'https://') !== 0) return null;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf, $opts) { $buf .= $d; return strlen($buf) > $opts['max_body'] ? 0 : strlen($d); },
        ]);
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
