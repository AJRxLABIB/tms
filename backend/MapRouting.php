<?php
/**
 * MapRouting — server-side client for an OSRM-compatible routing API.
 *
 * Endpoints come from MapConfig::routeEndpoints() (env MAP_ROUTE_DRIVING / _WALKING / _CYCLING, or "off").
 * Defaults are the FOSSGIS public instances (routing.openstreetmap.de routed-car / routed-foot / routed-bike). All three were
 * checked on 2026-10-24 with a live request near Cox's Bazar and returned code "Ok". They are a fair-use public service,
 * not a guaranteed production service: results are cached, requests are short-timeout, and failures are reported honestly.
 *
 * Coordinates are [lng, lat] pairs (OSRM order). Distance and duration come ONLY from the engine; geometry is never invented.
 */
final class MapRouting
{
    public const MAX_WAYPOINTS = 25;
    private const CONNECT_TIMEOUT = 3;
    private const TOTAL_TIMEOUT   = 8;
    private const TTL_OK          = 3600;
    private const TTL_FAIL        = 60;
    private const MAX_BODY        = 4194304;   // 4 MB

    /** Test hook: fn(string $url, array $opts): ?array{status:int, body:string}  (null = network failure) */
    protected static $transport = null;
    /** Test hook: object with get(string):mixed and set(string,mixed,int):void. Defaults to CacheService on global $dbh. */
    protected static ?object $cacheStore = null;
    /** Optional hook: fn(): bool, return false to refuse the call (per-IP limiter). */
    public static $rateLimiter = null;

    /** @return list<string> enabled modes among driving|walking|cycling */
    public static function modes(): array
    {
        if (!class_exists('MapConfig')) return [];
        return array_keys(MapConfig::routeEndpoints());
    }

    /**
     * @param list<array{0:float|int|string,1:float|int|string}> $lngLatPairs
     * @return array
     */
    public static function route(array $lngLatPairs, string $mode): array
    {
        $mode = strtolower(trim($mode));
        $endpoints = class_exists('MapConfig') ? MapConfig::routeEndpoints() : [];
        if (!isset($endpoints[$mode])) {
            return self::err('mode_unsupported', 'This travel mode is not available.');
        }
        $pts = self::validatePairs($lngLatPairs);
        if (isset($pts['error'])) return ['ok' => false, 'error' => $pts['error']];
        $pts = $pts['points'];

        if (is_callable(self::$rateLimiter) && !(self::$rateLimiter)()) {
            return self::err('rate_limited', 'Too many route requests. Please wait a moment.');
        }

        $key = self::cacheKey($mode, $pts);
        $cache = self::cache();
        if ($cache) {
            $hit = self::cacheGet($cache, $key);
            if (is_array($hit)) return $hit;
            $neg = self::cacheGet($cache, $key . ':fail');
            if (is_array($neg)) return $neg;
        }

        $ep = $endpoints[$mode];
        $coords = implode(';', array_map(fn($p) => self::fmt($p[0]) . ',' . self::fmt($p[1]), $pts));
        $url = $ep['url'] . '/route/v1/' . rawurlencode($ep['profile']) . '/' . $coords . '?overview=full&geometries=geojson&steps=false';

        $res = self::http($url);
        if ($res === null) {
            $out = self::err('upstream_error', 'The routing service could not be reached.');
            if ($cache) self::cacheSet($cache, $key . ':fail', $out, self::TTL_FAIL);
            return $out;
        }
        $out = self::parse($res['status'], $res['body'], $mode, $ep['url']);
        if ($cache) {
            if ($out['ok']) self::cacheSet($cache, $key, $out, self::TTL_OK);
            elseif (($out['error']['code'] ?? '') === 'no_route') self::cacheSet($cache, $key, $out, 600);
            else self::cacheSet($cache, $key . ':fail', $out, self::TTL_FAIL);
        }
        return $out;
    }

    /** Pure: validates coordinate pairs ([lng, lat]) and returns normalised floats. */
    public static function validatePairs(array $pairs): array
    {
        $n = count($pairs);
        if ($n < 2) return ['error' => ['code' => 'invalid_coords', 'message' => 'At least two points are needed.']];
        if ($n > self::MAX_WAYPOINTS) return ['error' => ['code' => 'too_many_waypoints', 'message' => 'At most ' . self::MAX_WAYPOINTS . ' points are allowed.']];
        $out = [];
        foreach (array_values($pairs) as $p) {
            if (!is_array($p) || count($p) < 2) return ['error' => ['code' => 'invalid_coords', 'message' => 'Each point must be longitude and latitude.']];
            $lng = $p[0]; $lat = $p[1];
            if (!is_numeric($lng) || !is_numeric($lat)) return ['error' => ['code' => 'invalid_coords', 'message' => 'Coordinates must be numbers.']];
            $lng = (float)$lng; $lat = (float)$lat;
            if (!is_finite($lng) || !is_finite($lat) || $lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                return ['error' => ['code' => 'invalid_coords', 'message' => 'Coordinates are out of range (longitude comes first, then latitude).']];
            }
            $out[] = [$lng, $lat];
        }
        return ['points' => $out];
    }

    /** Pure: parses "lng,lat;lng,lat" (as used in the API query string). */
    public static function parseCoordsParam(string $coords): array
    {
        $pairs = [];
        foreach (explode(';', trim($coords)) as $part) {
            $bits = explode(',', trim($part));
            if (count($bits) !== 2) return [];
            $pairs[] = [$bits[0], $bits[1]];
        }
        return $pairs;
    }

    public static function cacheKey(string $mode, array $pts): string
    {
        $s = $mode . '|' . implode(';', array_map(fn($p) => round($p[0], 5) . ',' . round($p[1], 5), $pts));
        return 'maproute:' . sha1($s);
    }

    /** Pure: turns an OSRM response into our result or error. */
    public static function parse(int $status, string $body, string $mode, string $source): array
    {
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return self::err('upstream_error', 'The routing service returned an unreadable answer.');
        }
        $code = (string)($j['code'] ?? '');
        if ($code === 'NoRoute' || $code === 'NoSegment') {
            return self::err('no_route', 'No route could be found between these points for this travel mode.');
        }
        if ($status < 200 || $status >= 300 || $code !== 'Ok') {
            return self::err($status === 429 ? 'rate_limited' : 'upstream_error', 'The routing service could not calculate this route.');
        }
        $r = $j['routes'][0] ?? null;
        $geom = is_array($r) ? ($r['geometry'] ?? null) : null;
        if (!is_array($r) || !is_array($geom) || ($geom['type'] ?? '') !== 'LineString' || !is_array($geom['coordinates'] ?? null) || count($geom['coordinates']) < 2) {
            return self::err('upstream_error', 'The routing service returned no usable route geometry.');
        }
        if (!isset($r['distance'], $r['duration']) || !is_numeric($r['distance']) || !is_numeric($r['duration'])) {
            return self::err('upstream_error', 'The routing service returned no distance or duration.');
        }
        $legs = [];
        foreach ((array)($r['legs'] ?? []) as $l) {
            if (isset($l['distance'], $l['duration']) && is_numeric($l['distance']) && is_numeric($l['duration'])) {
                $legs[] = ['distance_m' => (int)round((float)$l['distance']), 'duration_s' => (int)round((float)$l['duration'])];
            }
        }
        return [
            'ok'         => true,
            'mode'       => $mode,
            'distance_m' => (int)round((float)$r['distance']),
            'duration_s' => (int)round((float)$r['duration']),
            'geometry'   => ['type' => 'LineString', 'coordinates' => $geom['coordinates']],
            'legs'       => $legs,
            'source'     => (string)(parse_url($source, PHP_URL_HOST) ?: 'osrm'),
        ];
    }

    private static function err(string $code, string $message): array
    {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function fmt($v): string { return rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.'); }

    private static function http(string $url): ?array
    {
        $opts = ['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::TOTAL_TIMEOUT, 'max_body' => self::MAX_BODY,
                 'user_agent' => class_exists('MapConfig') ? MapConfig::userAgent() : 'TourBuddy-TourismManagement/1.0'];
        if (self::$transport) return (self::$transport)($url, $opts);
        if (!function_exists('curl_init') || stripos($url, 'https://') !== 0) return null;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $opts['connect_timeout'], CURLOPT_TIMEOUT => $opts['timeout'],
            CURLOPT_USERAGENT => $opts['user_agent'], CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf, $opts) { $buf .= $d; return strlen($buf) > $opts['max_body'] ? 0 : strlen($d); },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $ok === false ? null : ['status' => $status, 'body' => $buf];
    }

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
    public static function _setTestHooks($transport, ?object $cache): void { self::$transport = $transport; self::$cacheStore = $cache; }
}
