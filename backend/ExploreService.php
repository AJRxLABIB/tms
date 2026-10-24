<?php
/**
 * ExploreService.php — Backend for "Explore Mode"
 *
 * Wraps every third-party integration used by Explore Mode behind a single,
 * cached, fail-soft interface:
 *
 *   • Nominatim (OpenStreetMap)      — destination search / geocoding      (free, no key)
 *   • Open-Meteo                     — live weather + forecast + air quality (free, no key)
 *   • Overpass API (OpenStreetMap)   — nearby places                        (free, no key)
 *   • Google Places API              — nearby places w/ ratings & photos    (optional upgrade)
 *   • OSRM                           — driving/walking/cycling routes       (free, no key)
 *   • Google Directions API          — routes incl. public transit          (optional upgrade)
 *
 * Every method returns a plain array and never throws — on any upstream
 * failure it returns ['success' => false, 'error' => '...'] so calling code
 * (api/explore.php) can degrade gracefully instead of crashing the page.
 */

require_once __DIR__ . '/CacheService.php';
// FIX (Round 114, package/places/map upgrade): needed for
// PlaceDetailsService::openNowFromOsm(), reused (not reimplemented) by
// parseOverpassPlaces() below so a Nearby Places LIST can show a real
// "Open now"/"Closed now" badge per place, not only inside the single-place
// detail modal PlaceDetailsService was originally built for.
require_once __DIR__ . '/PlaceDetailsService.php';

class ExploreService {

    private PDO $db;
    private CacheService $cache;
    // Nominatim's usage policy requires a real, reachable contact. This used to
    // be "support@tms.local", which is not a routable domain — exactly the kind
    // of UA that gets an IP blocked, and a block here takes out destination
    // search entirely. Set TMS_CONTACT_EMAIL in .env to your own address.
    private string $ua;

    public function __construct(PDO $db, ?CacheService $cache = null) {
        $contact = defined('TMS_CONTACT_EMAIL') && TMS_CONTACT_EMAIL
            ? TMS_CONTACT_EMAIL
            : (function_exists('tms_env') ? (tms_env('TMS_CONTACT_EMAIL', '') ?: '') : '');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->ua = 'TourBuddy-Travel-Explorer/1.0 (+http://' . $host . '; contact: '
                  . ($contact !== '' ? $contact : 'admin@' . $host) . ')';
        $this->db    = $db;
        // ensureTable=false: includes/config.php already migrates the shared
        // tblcache table once per request via its own $tmsCache instance.
        $this->cache = $cache ?? new CacheService($db, false);
    }

    // ════════════════════════════════════════════════════════════════════
    // DESTINATION SEARCH (autocomplete)
    // ════════════════════════════════════════════════════════════════════

    /** Destinations from our own catalogue (packages that carry coordinates), used only when Nominatim fails. */
    protected function searchLocalDestinations(string $query, int $limit): array {
        try {
            $st = $this->db->prepare(
                "SELECT PackageLocation, Country, MapLat, MapLng FROM tbltourpackages
                 WHERE Status = 1 AND MapLat IS NOT NULL AND MapLat <> '' AND MapLng IS NOT NULL AND MapLng <> ''
                   AND (PackageLocation LIKE :q OR Country LIKE :q OR PackageName LIKE :q)
                 GROUP BY PackageLocation, Country, MapLat, MapLng LIMIT " . (int)max(1, min(20, $limit))
            );
            $st->execute([':q' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%']);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $label = trim((string)$r['PackageLocation']);
                $out[] = ['display_name' => $label . ($r['Country'] ? ', ' . $r['Country'] : ''), 'short_name' => $label,
                          'lat' => (float)$r['MapLat'], 'lng' => (float)$r['MapLng'], 'type' => 'catalogue',
                          'country' => (string)$r['Country'], 'country_code' => ''];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function searchDestinations(string $query, int $limit = 6): array {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) < 2) {
            return ['success' => true, 'results' => []];
        }

        $cacheKey = 'search_' . md5(strtolower($query)) . "_$limit";
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'results' => $cached, 'cached' => true];
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'              => $query,
            'format'         => 'jsonv2',
            'addressdetails' => 1,
            'limit'          => $limit,
        ]);

        $res = $this->httpRequest($url, 'GET', null, false, 8, 3);
        if (!$res['success'] || !is_array($res['data'] ?? null)) {
            // Nominatim down or rate-limiting: still let people find destinations we already have coordinates for.
            $local = $this->searchLocalDestinations($query, $limit);
            return $local ? ['success' => true, 'results' => $local, 'cached' => false, 'source' => 'local'] : ($res['success'] ? ['success' => false, 'error' => 'Search failed.'] : $res);
        }

        $results = [];
        foreach ($res['data'] as $item) {
            $addr = $item['address'] ?? [];
            $results[] = [
                'display_name' => $item['display_name'] ?? '',
                'short_name'   => $item['name'] ?? explode(',', $item['display_name'] ?? '')[0],
                'lat'          => (float)($item['lat'] ?? 0),
                'lng'          => (float)($item['lon'] ?? 0),
                'type'         => $item['type'] ?? $item['class'] ?? 'place',
                'country'      => $addr['country'] ?? '',
                'country_code' => strtoupper($addr['country_code'] ?? ''),
            ];
        }

        $this->cacheSet($cacheKey, $results, 60 * 60 * 24); // 24h — place search results rarely change
        return ['success' => true, 'results' => $results, 'cached' => false];
    }

    // ════════════════════════════════════════════════════════════════════
    // WEATHER — Open-Meteo (current + hourly + 7-day + air quality)
    // ════════════════════════════════════════════════════════════════════

    public function getWeather(float $lat, float $lng): array {
        $cacheKey = 'weather_' . round($lat, 3) . '_' . round($lng, 3);
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'data' => $cached, 'cached' => true];
        }

        $weatherUrl = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude'        => $lat,
            'longitude'       => $lng,
            'current'         => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m,is_day',
            'hourly'          => 'temperature_2m,weather_code,precipitation_probability',
            'daily'           => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset,wind_speed_10m_max',
            'forecast_days'   => 7,
            'timezone'        => 'auto',
        ]);

        // Air quality is a separate Open-Meteo endpoint, and doesn't depend on
        // the forecast response in any way -- Round 113 (AI-upgrade priority,
        // speed pass) fires both concurrently via httpRequestMulti() instead
        // of waiting for the forecast call to fully finish before starting
        // the air-quality one. Roughly halves this method's network time
        // (both endpoints are typically similar latency), which matters here
        // more than most call sites since getWeather() sits directly in the
        // critical path of every weather-grounded AI feature (ItineraryPlanner,
        // TravelRecommender, TripCopilot) as well as the Explore Mode Weather tab.
        $aqUrl = 'https://air-quality-api.open-meteo.com/v1/air-quality?' . http_build_query([
            'latitude'  => $lat,
            'longitude' => $lng,
            'current'   => 'us_aqi,pm2_5,pm10',
            'timezone'  => 'auto',
        ]);
        $both = $this->httpRequestMulti([
            'forecast'     => ['url' => $weatherUrl],
            'air_quality'  => ['url' => $aqUrl],
        ]);
        $weatherRes = $both['forecast'];
        if (!$weatherRes['success']) return $weatherRes;
        $w = $weatherRes['data'];

        $aqRes = $both['air_quality'];
        $aq    = $aqRes['success'] ? ($aqRes['data']['current'] ?? null) : null;

        // FIX (Round 14): this took the FIRST 24 hourly entries. Open-Meteo's
        // hourly array starts at 00:00 local on the current day, so a panel
        // headed "next 24h" actually showed midnight to 23:00 today — at 8pm,
        // twenty of the twenty-four chips were hours that had already passed.
        // Start from the first entry at or after the destination's local "now".
        $hourly = [];
        if (!empty($w['hourly']['time'])) {
            // Open-Meteo returns hourly timestamps as LOCAL time with no offset
            // ("2026-09-03T14:00") plus the destination's offset in seconds.
            // Building the destination's current local hour in the same format
            // makes this a plain string comparison — no timezone juggling, and
            // no dependency on the server's own date.timezone setting.
            $startIdx = 0;
            $offset   = (int)($w['utc_offset_seconds'] ?? 0);
            $nowLocal = gmdate('Y-m-d\TH:i', time() + $offset);
            foreach ($w['hourly']['time'] as $k => $t) {
                if (strcmp((string)$t, $nowLocal) >= 0) { $startIdx = $k; break; }
            }

            $total = count($w['hourly']['time']);
            $count = min(24, max(0, $total - $startIdx));
            for ($n = 0; $n < $count; $n++) {
                $i = $startIdx + $n;
                $hourly[] = [
                    'time' => $w['hourly']['time'][$i],
                    'temp' => $w['hourly']['temperature_2m'][$i] ?? null,
                    'code' => $w['hourly']['weather_code'][$i] ?? null,
                    'pop'  => $w['hourly']['precipitation_probability'][$i] ?? null,
                ];
            }
        }

        $daily = [];
        if (!empty($w['daily']['time'])) {
            foreach ($w['daily']['time'] as $i => $date) {
                $daily[] = [
                    'date'    => $date,
                    'code'    => $w['daily']['weather_code'][$i] ?? null,
                    'tmax'    => $w['daily']['temperature_2m_max'][$i] ?? null,
                    'tmin'    => $w['daily']['temperature_2m_min'][$i] ?? null,
                    'pop'     => $w['daily']['precipitation_probability_max'][$i] ?? null,
                    'sunrise' => $w['daily']['sunrise'][$i] ?? null,
                    'sunset'  => $w['daily']['sunset'][$i] ?? null,
                    'wind'    => $w['daily']['wind_speed_10m_max'][$i] ?? null,
                ];
            }
        }

        $current = $w['current'] ?? [];
        $code    = (int)($current['weather_code'] ?? 0);

        $result = [
            'current' => [
                'temp'        => $current['temperature_2m'] ?? null,
                'feels_like'  => $current['apparent_temperature'] ?? null,
                'humidity'    => $current['relative_humidity_2m'] ?? null,
                'wind_speed'  => $current['wind_speed_10m'] ?? null,
                'wind_dir'    => $current['wind_direction_10m'] ?? null,
                'is_day'      => $current['is_day'] ?? 1,
                'code'        => $code,
                'description' => $this->weatherCodeToText($code),
                'icon'        => $this->weatherCodeToIcon($code, (bool)($current['is_day'] ?? 1)),
            ],
            // FIX (Round 14): aqiLabel(0) returns "Good", so a location whose
            // air-quality call returned nothing was shown as "— · Good" — a
            // health claim about a value we never received. No reading, no label.
            'air_quality' => ($aq && isset($aq['us_aqi']) && $aq['us_aqi'] !== null) ? [
                'us_aqi' => $aq['us_aqi'],
                'pm2_5'  => $aq['pm2_5']  ?? null,
                'pm10'   => $aq['pm10']   ?? null,
                'label'  => $this->aqiLabel((int)$aq['us_aqi']),
            ] : null,
            'hourly'   => $hourly,
            'daily'    => $daily,
            'alerts'   => $this->buildWeatherAlerts($daily, $current),
            'timezone' => $w['timezone'] ?? 'UTC',
        ];

        $this->cacheSet($cacheKey, $result, EXPLORE_CACHE_TTL_WEATHER);
        return ['success' => true, 'data' => $result, 'cached' => false];
    }

    private function buildWeatherAlerts(array $daily, array $current): array {
        $alerts = [];
        if (!empty($current['wind_speed_10m']) && $current['wind_speed_10m'] >= 45) {
            $alerts[] = ['severity' => 'warning', 'message' => 'High winds currently reported — take care with outdoor activities.'];
        }
        foreach (array_slice($daily, 0, 3) as $d) {
            if (($d['pop'] ?? 0) >= 70) {
                $alerts[] = ['severity' => 'info', 'message' => "High chance of rain on {$d['date']} ({$d['pop']}%). Pack a rain jacket."];
            }
            if (($d['tmax'] ?? 0) >= 38) {
                $alerts[] = ['severity' => 'warning', 'message' => "Extreme heat expected on {$d['date']} (up to {$d['tmax']}°C). Stay hydrated."];
            }
        }
        return $alerts;
    }

    private function weatherCodeToText(int $code): string {
        // WMO weather interpretation codes used by Open-Meteo
        $map = [
            0=>'Clear sky',1=>'Mainly clear',2=>'Partly cloudy',3=>'Overcast',
            45=>'Fog',48=>'Depositing rime fog',
            51=>'Light drizzle',53=>'Moderate drizzle',55=>'Dense drizzle',
            56=>'Light freezing drizzle',57=>'Dense freezing drizzle',
            61=>'Slight rain',63=>'Moderate rain',65=>'Heavy rain',
            66=>'Light freezing rain',67=>'Heavy freezing rain',
            71=>'Slight snow fall',73=>'Moderate snow fall',75=>'Heavy snow fall',77=>'Snow grains',
            80=>'Slight rain showers',81=>'Moderate rain showers',82=>'Violent rain showers',
            85=>'Slight snow showers',86=>'Heavy snow showers',
            95=>'Thunderstorm',96=>'Thunderstorm with hail',99=>'Thunderstorm with heavy hail',
        ];
        return $map[$code] ?? 'Unknown';
    }

    private function weatherCodeToIcon(int $code, bool $isDay): string {
        if (in_array($code, [0,1])) return $isDay ? 'fa-sun' : 'fa-moon';
        if ($code === 2) return $isDay ? 'fa-cloud-sun' : 'fa-cloud-moon';
        if ($code === 3) return 'fa-cloud';
        if (in_array($code, [45,48])) return 'fa-smog';
        if (in_array($code, [51,53,55,56,57,61,63,65,66,67,80,81,82])) return 'fa-cloud-showers-heavy';
        if (in_array($code, [71,73,75,77,85,86])) return 'fa-snowflake';
        if (in_array($code, [95,96,99])) return 'fa-bolt';
        return 'fa-cloud';
    }

    private function aqiLabel(int $aqi): string {
        if ($aqi <= 50) return 'Good';
        if ($aqi <= 100) return 'Moderate';
        if ($aqi <= 150) return 'Unhealthy for Sensitive Groups';
        if ($aqi <= 200) return 'Unhealthy';
        if ($aqi <= 300) return 'Very Unhealthy';
        return 'Hazardous';
    }

    // ════════════════════════════════════════════════════════════════════
    // NEARBY PLACES — Google Places (if key configured) else Overpass/OSM
    // ════════════════════════════════════════════════════════════════════

    private const CATEGORY_MAP = [
        'hotel'          => ['osm' => '["tourism"~"hotel|guest_house|hostel|motel"]',           'google' => 'lodging'],
        'restaurant'     => ['osm' => '["amenity"="restaurant"]',                               'google' => 'restaurant'],
        'hospital'       => ['osm' => '["amenity"="hospital"]',                                 'google' => 'hospital'],
        'pharmacy'       => ['osm' => '["amenity"="pharmacy"]',                                 'google' => 'pharmacy'],
        'police'         => ['osm' => '["amenity"="police"]',                                   'google' => 'police'],
        'atm'            => ['osm' => '["amenity"~"atm|bank"]',                                 'google' => 'atm'],
        'gas_station'    => ['osm' => '["amenity"="fuel"]',                                     'google' => 'gas_station'],
        'shopping_mall'  => ['osm' => '["shop"="mall"]',                                        'google' => 'shopping_mall'],
        'tourist_info'   => ['osm' => '["tourism"="information"]',                              'google' => 'tourist_information_center'],
        'attraction'     => ['osm' => '["tourism"~"attraction|museum|viewpoint|artwork|gallery"]','google' => 'tourist_attraction'],
    ];

    /**
     * Public Overpass servers, raced in parallel (first valid answer wins). Previously a single mirror
     * (overpass-api.de) was used with a 25 s timeout: when it was slow or rate-limiting, "Nearby Places"
     * spun forever. Override with TMS_OVERPASS_MIRRORS (comma-separated interpreter URLs).
     */
    private const OVERPASS_MIRRORS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];
    /** Total wall-clock budget for one live places lookup (seconds). */
    private const PLACES_BUDGET_SEC = 9;
    /** After a failed live lookup for a key, skip the network for this long and answer from stale/local data. */
    private const PLACES_FAIL_TTL = 90;
    /** A second, long-lived copy of every successful lookup, served (flagged stale) when live lookups fail. */
    private const PLACES_STALE_TTL = 60 * 60 * 24 * 30;

    private function placesKey(float $lat, float $lng, string $category, int $radius): string {
        return "places_{$category}_" . round($lat, 3) . '_' . round($lng, 3) . "_$radius";
    }

    /**
     * Nearby places, never hangs and degrades in this order:
     *   fresh cache -> live lookup (Google if keyed, else Overpass mirrors raced) -> stale cache -> local database.
     * 'origin' tells the UI which one answered: live | cached | stale | local.
     */
    public function getNearbyPlaces(float $lat, float $lng, string $category, int $radius = 4000): array {
        if (!isset(self::CATEGORY_MAP[$category])) {
            return ['success' => false, 'error' => 'Unknown place category.'];
        }

        $cacheKey = $this->placesKey($lat, $lng, $category, $radius);
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'results' => $cached, 'cached' => true, 'origin' => 'cached', 'stale' => false, 'source' => 'cache'];
        }

        // A lookup for this exact area failed moments ago: do not make the visitor wait through it again.
        if ($this->cacheGet('fail_' . $cacheKey) !== null) {
            return $this->placesFallback($lat, $lng, $category, $radius, $cacheKey, 'Live place data is temporarily unavailable.');
        }

        $result = (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '')
            ? $this->placesFromGoogle($lat, $lng, $category, $radius)
            : $this->placesFromOverpass($lat, $lng, $category, $radius);

        if ($result['success']) {
            $this->storePlaces($cacheKey, $result['results']);
            $result['origin'] = 'live';
            $result['stale']  = false;
            return $result;
        }

        $this->cacheSet('fail_' . $cacheKey, 1, self::PLACES_FAIL_TTL);
        return $this->placesFallback($lat, $lng, $category, $radius, $cacheKey, $result['error'] ?? 'Live place data is temporarily unavailable.');
    }

    private function storePlaces(string $cacheKey, array $places): void {
        $this->cacheSet($cacheKey, $places, EXPLORE_CACHE_TTL_PLACES);
        $this->cacheSet('stale_' . $cacheKey, $places, self::PLACES_STALE_TTL);
    }

    /** Stale copy first (real data, older), then the app's own catalogue, else a clear failure. */
    private function placesFallback(float $lat, float $lng, string $category, int $radius, string $cacheKey, string $error): array {
        $stale = $this->cacheGet('stale_' . $cacheKey);
        if (is_array($stale) && $stale) {
            return ['success' => true, 'results' => $stale, 'cached' => true, 'origin' => 'stale', 'stale' => true, 'source' => 'cache',
                    'notice' => 'Live data is unavailable right now. Showing results saved earlier; they may be out of date.'];
        }
        $local = $this->localNearby($lat, $lng, $category, $radius);
        if ($local) {
            return ['success' => true, 'results' => $local, 'origin' => 'local', 'stale' => false, 'source' => 'local',
                    'notice' => 'Live map data is unavailable right now. Showing places from our own catalogue.'];
        }
        return ['success' => false, 'error' => $error, 'origin' => 'none', 'stale' => false, 'source' => 'none'];
    }

    /**
     * Places from this application's own database. Only tour packages carry coordinates (MapLat/MapLng); hotels
     * have none, so hotels are matched by the country/city of the nearest packaged destination and returned
     * without coordinates (list only, never plotted). Sample rows are flagged is_sample, never presented as live.
     */
    protected function localNearby(float $lat, float $lng, string $category, int $radius): array {
        $out = [];
        // Bounding box ~ max(radius, 25 km): rows outside it cannot match, so the haversine below runs on few rows.
        $reach = max($radius, 25000);
        $dLat  = $reach / 111320.0;
        $dLng  = $reach / (111320.0 * max(0.2, cos(deg2rad($lat))));
        try {
            $st = $this->db->prepare(
                "SELECT PackageId, PackageName, PackageLocation, Country, MapLat, MapLng, PackagePrice
                 FROM tbltourpackages
                 WHERE Status = 1 AND MapLat IS NOT NULL AND MapLat <> '' AND MapLng IS NOT NULL AND MapLng <> ''
                   AND CAST(MapLat AS DECIMAL(9,6)) BETWEEN :la1 AND :la2
                   AND CAST(MapLng AS DECIMAL(9,6)) BETWEEN :lo1 AND :lo2
                 LIMIT 200"
            );
            $st->execute([':la1' => $lat - $dLat, ':la2' => $lat + $dLat, ':lo1' => $lng - $dLng, ':lo2' => $lng + $dLng]);
            $pkgs = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $near = [];
        foreach ($pkgs as $p) {
            $d = (int)$this->haversine($lat, $lng, (float)$p['MapLat'], (float)$p['MapLng']);
            $near[] = ['row' => $p, 'd' => $d];
        }
        usort($near, fn($a, $b) => $a['d'] <=> $b['d']);

        if (in_array($category, ['attraction', 'tourist_info'], true)) {
            foreach (array_slice($near, 0, 12) as $n) {
                $p = $n['row'];
                $out[] = $this->localItem((string)$p['PackageName'], (float)$p['MapLat'], (float)$p['MapLng'], (int)$n['d'],
                    trim((string)$p['PackageLocation']) ?: null, 'package', 'package-details.php?pkgid=' . (int)$p['PackageId']);
            }
            return $out;
        }

        if ($category === 'hotel' && $near) {
            $country = trim((string)($near[0]['row']['Country'] ?? ''));
            if ($country !== '') {
                try {
                    $h = $this->db->prepare("SELECT HotelId, HotelName, City, StarRating, IsSampleData FROM tblhotels WHERE Status=1 AND Country=? ORDER BY AvgRating DESC, HotelId DESC LIMIT 10");
                    $h->execute([$country]);
                    foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $out[] = $this->localItem((string)$r['HotelName'], null, null, null, trim((string)$r['City']) ?: null, 'hotel',
                            'hotel-details.php?id=' . (int)$r['HotelId'], ((int)($r['IsSampleData'] ?? 0)) === 1);
                    }
                } catch (Throwable $e) { /* optional table */ }
            }
        }
        return $out;
    }

    private function localItem(string $name, ?float $lat, ?float $lng, ?int $distance, ?string $address, string $kind, string $url, bool $sample = false): array {
        return [
            'name' => $name, 'lat' => $lat, 'lng' => $lng, 'address' => $address, 'phone' => null, 'opening_hours' => null,
            'website' => null, 'rating' => null, 'photo' => null, 'photo_attribution' => null, 'distance_m' => $distance,
            'is_open_now' => null, 'source' => 'local', 'local_kind' => $kind, 'local_url' => $url, 'is_sample' => $sample,
        ];
    }

    private function overpassQl(string $category, float $lat, float $lng, int $radius): string {
        $filter = self::CATEGORY_MAP[$category]['osm'];
        return "[out:json][timeout:10];(node$filter(around:$radius,$lat,$lng);way$filter(around:$radius,$lat,$lng););out center 25;";
    }

    /** @return string[] */
    protected function overpassMirrors(): array {
        $env = function_exists('tms_env') ? (string)tms_env('TMS_OVERPASS_MIRRORS', '') : '';
        $list = array_values(array_filter(array_map('trim', explode(',', $env)), fn($u) => preg_match('#^https://#', $u)));
        return $list ?: self::OVERPASS_MIRRORS;
    }

    /**
     * Ask every Overpass mirror at once and take the first valid answer. 'valid' = HTTP 2xx, JSON with an
     * 'elements' array (Overpass can answer 200 with a runtime-error remark and no elements when it is overloaded).
     * @return array{success:bool,data?:array,error?:string,mirror?:string}
     */
    protected function fetchOverpass(string $ql): array {
        $reqs = [];
        foreach ($this->overpassMirrors() as $i => $url) {
            $reqs['m' . $i] = ['url' => $url, 'method' => 'POST', 'body' => 'data=' . urlencode($ql), 'formEncoded' => true];
        }
        $validate = fn($data) => is_array($data) && isset($data['elements']) && is_array($data['elements']);
        $res = $this->raceRequests($reqs, self::PLACES_BUDGET_SEC, $validate);
        foreach ($reqs as $key => $r) {
            if (!empty($res[$key]['success']) && $validate($res[$key]['data'] ?? null)) {
                return ['success' => true, 'data' => $res[$key]['data'], 'mirror' => parse_url($r['url'], PHP_URL_HOST) ?: ''];
            }
        }
        return ['success' => false, 'error' => 'Nearby places service is temporarily unavailable. Please try again shortly.'];
    }

    /**
     * Fire all $requests concurrently and return as soon as one passes $validate (the rest are cancelled), or when
     * $budgetSec elapses. Connect timeout 3 s; each request is also capped at the budget. One result per key.
     * Protected so tests can replace the network with a fake.
     * @param array<string,array{url:string,method?:string,body?:?string,formEncoded?:bool}> $requests
     * @return array<string,array{success:bool,data?:mixed,error?:string}>
     */
    protected function raceRequests(array $requests, int $budgetSec, callable $validate): array {
        $results = [];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => $r) {
            $ch = curl_init($r['url']);
            $headers = ['User-Agent: ' . $this->ua, 'Accept: application/json'];
            if (($r['body'] ?? null) !== null && empty($r['formEncoded'])) $headers[] = 'Content-Type: application/json';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => $budgetSec,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => '',
            ]);
            if (($r['method'] ?? 'GET') === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $r['body'] ?? '');
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $deadline = microtime(true) + $budgetSec;
        $won = false;
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $key = array_search($ch, $handles, true);
                if ($key === false || isset($results[$key])) continue;
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $host = parse_url($requests[$key]['url'], PHP_URL_HOST) ?: 'unknown';
                if ($info['result'] !== CURLE_OK) {
                    $results[$key] = ['success' => false, 'error' => 'Network error: ' . curl_error($ch)];
                } elseif ($code < 200 || $code >= 300) {
                    $results[$key] = ['success' => false, 'error' => "HTTP $code"];   // 429 / 504 land here: just try the other mirrors
                } else {
                    $data = json_decode((string)curl_multi_getcontent($ch), true);
                    $results[$key] = $validate($data) ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => 'Invalid or empty response'];
                }
                if (function_exists('tms_log_system_event')) {
                    try { tms_log_system_event($this->db, 'maps', $host, $results[$key]['success'], $results[$key]['error'] ?? ''); } catch (Throwable $e) {}
                }
                if ($results[$key]['success']) { $won = true; }
            }
            if ($won || !$running) break;
            if (microtime(true) >= $deadline) break;
            curl_multi_select($mh, 0.25);
        } while ($running);

        foreach ($handles as $key => $ch) {
            if (!isset($results[$key])) $results[$key] = ['success' => false, 'error' => $won ? 'Cancelled (another mirror answered first)' : 'Timed out'];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    /**
     * Round 113: extracted from placesFromOverpass() (single-category path)
     * unchanged, so getNearbyPlacesMulti() below can share the exact same
     * parsing for the concurrent (multi-category) path instead of a second,
     * separately-maintained copy that could quietly drift from this one.
     */
    private function parseOverpassPlaces(array $elements, float $lat, float $lng): array {
        $places = [];
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $plat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $plng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if (!$plat || !$plng || empty($tags['name'])) continue;

            $addrParts = array_filter([
                $tags['addr:housenumber'] ?? '', $tags['addr:street'] ?? '',
                $tags['addr:city'] ?? '', $tags['addr:postcode'] ?? '',
            ]);

            $openingHours = $tags['opening_hours'] ?? null;
            $places[] = [
                'name'          => $tags['name'],
                'lat'           => (float)$plat,
                'lng'           => (float)$plng,
                'address'       => $addrParts ? implode(', ', $addrParts) : null,
                'phone'         => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                'opening_hours' => $openingHours,
                'website'       => $tags['website'] ?? $tags['contact:website'] ?? null,
                'rating'        => null,
                'photo'         => null,
                'photo_attribution' => null,
                'distance_m'    => (int)$this->haversine($lat, $lng, (float)$plat, (float)$plng),
                // FIX (Round 114, package/places/map upgrade -- finding #12):
                // this list previously carried the raw opening_hours string
                // but nothing derived from it, so every place card had to be
                // opened individually (triggering a place_details call) just
                // to learn whether it was open right now, even though the
                // real OSM hours were already sitting right here. Reuses
                // PlaceDetailsService's own OSM-hours parser rather than a
                // second copy of that logic.
                'is_open_now'   => PlaceDetailsService::openNowFromOsm($openingHours),
            ];
        }
        usort($places, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        return array_slice($places, 0, 20);
    }

    private function placesFromOverpass(float $lat, float $lng, string $category, int $radius): array {
        $ql  = $this->overpassQl($category, $lat, $lng, $radius);
        $res = $this->fetchOverpass($ql);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'] ?? 'Nearby places service is temporarily unavailable. Please try again shortly.'];
        }

        $places = $this->parseOverpassPlaces($res['data']['elements'] ?? [], $lat, $lng);
        return ['success' => true, 'results' => $places, 'source' => 'openstreetmap'];
    }

    private function googlePlacesUrl(string $category, float $lat, float $lng, int $radius): string {
        $type = self::CATEGORY_MAP[$category]['google'];
        return 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query([
            'location' => "$lat,$lng",
            'radius'   => $radius,
            'type'     => $type,
            'key'      => GOOGLE_PLACES_API_KEY,
        ]);
    }

    /**
     * Round 113: extracted from placesFromGoogle() unchanged (same photo-proxy
     * security fix, same attribution handling), so the concurrent multi-category
     * path shares this exact parsing instead of a second copy.
     */
    private function parseGooglePlaces(array $results, float $lat, float $lng): array {
        $places = [];
        foreach ($results as $p) {
            $photo = null;
            $photoAttribution = null;
            if (!empty($p['photos'][0]['photo_reference'])) {
                // SECURITY FIX (Round 14): this used to embed GOOGLE_PLACES_API_KEY
                // in a URL that was sent to the browser and rendered in an <img src>,
                // exposing the server's billing key to every visitor. Hand out the
                // opaque reference and let api/explore.php?action=place_photo stream
                // the image server-side instead.
                $photo = 'api/explore.php?action=place_photo&ref=' . urlencode($p['photos'][0]['photo_reference']);
                // PROVENANCE FIX (Round 73, master prompt item 65): Google's Places
                // API terms require displaying each photo's attribution whenever it
                // is shown. `html_attributions[0]` is real data straight from
                // Google's own response (typically a small "<a href=...>Photo
                // credit</a>" snippet) -- never fabricated, and only ever attached
                // to a place that genuinely has a Google photo.
                if (!empty($p['photos'][0]['html_attributions'][0]) && is_string($p['photos'][0]['html_attributions'][0])) {
                    $photoAttribution = $p['photos'][0]['html_attributions'][0];
                }
            }
            $plat = $p['geometry']['location']['lat'] ?? null;
            $plng = $p['geometry']['location']['lng'] ?? null;
            $places[] = [
                'name'          => $p['name'] ?? '',
                'lat'           => $plat,
                'lng'           => $plng,
                'address'       => $p['vicinity'] ?? null,
                'phone'         => null,
                'opening_hours' => isset($p['opening_hours']['open_now']) ? ($p['opening_hours']['open_now'] ? 'Open now' : 'Closed now') : null,
                'website'       => null,
                'rating'        => $p['rating'] ?? null,
                'photo'         => $photo,
                'photo_attribution' => $photoAttribution,
                'distance_m'    => ($plat && $plng) ? (int)$this->haversine($lat, $lng, $plat, $plng) : null,
                // FIX (Round 114, package/places/map upgrade -- finding #12):
                // Google's real open_now boolean was already being received
                // above, just to be flattened into the 'opening_hours' display
                // string one line up -- that string then could never be
                // reliably turned back into a boolean by anything downstream
                // (PlaceDetailsService::openNowFromOsm() parses OSM hours
                // syntax, not "Open now"/"Closed now"). Attach the real
                // boolean directly instead of discarding it.
                'is_open_now'   => isset($p['opening_hours']['open_now']) ? (bool)$p['opening_hours']['open_now'] : null,
            ];
        }
        usort($places, fn($a, $b) => ($a['distance_m'] ?? 999999) <=> ($b['distance_m'] ?? 999999));
        return array_slice($places, 0, 20);
    }

    private function placesFromGoogle(float $lat, float $lng, string $category, int $radius): array {
        $res = $this->httpGetJson($this->googlePlacesUrl($category, $lat, $lng, $radius));
        if (!$res['success'] || ($res['data']['status'] ?? '') !== 'OK') {
            return $this->placesFromOverpass($lat, $lng, $category, $radius);
        }

        $places = $this->parseGooglePlaces($res['data']['results'] ?? [], $lat, $lng);
        return ['success' => true, 'results' => $places, 'source' => 'google_places'];
    }

    /**
     * Round 113 (AI-upgrade priority, speed pass): fetch several place
     * categories at once (e.g. hotels + restaurants for ItineraryPlanner's
     * nearby-places grounding) with one concurrent batch of network calls
     * instead of one getNearbyPlaces() call per category run sequentially.
     * Cache is still checked/written per-category exactly as
     * getNearbyPlaces() already does, so a category that's already cached
     * costs nothing extra and only genuine cache misses go out over the
     * network -- and those go out together instead of one after another.
     *
     * @param string[] $categories e.g. ['hotel', 'restaurant']
     * @return array<string,array> category => same shape getNearbyPlaces() returns
     */
    public function getNearbyPlacesMulti(float $lat, float $lng, array $categories, int $radius = 4000): array {
        $categories = array_values(array_unique(array_filter($categories, fn($c) => isset(self::CATEGORY_MAP[$c]))));
        $final   = [];
        $pending = []; // category => request spec for httpRequestMulti

        $mirrors = $this->overpassMirrors();
        $i = 0;
        foreach ($categories as $cat) {
            $cacheKey = $this->placesKey($lat, $lng, $cat, $radius);
            $cached   = $this->cacheGet($cacheKey);
            if ($cached !== null) {
                $final[$cat] = ['success' => true, 'results' => $cached, 'cached' => true, 'origin' => 'cached', 'stale' => false];
                continue;
            }
            // A recent failure for this area: go straight to stale/local data instead of waiting on the network again.
            if ($this->cacheGet('fail_' . $cacheKey) !== null) {
                $final[$cat] = $this->placesFallback($lat, $lng, $cat, $radius, $cacheKey, 'Live place data is temporarily unavailable.');
                continue;
            }

            $useGoogle = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '';
            // Spread the categories over the mirrors so one public server does not receive every query at once.
            $pending[$cat] = $useGoogle
                ? ['url' => $this->googlePlacesUrl($cat, $lat, $lng, $radius), 'method' => 'GET']
                : ['url' => $mirrors[$i++ % count($mirrors)], 'method' => 'POST', 'body' => 'data=' . urlencode($this->overpassQl($cat, $lat, $lng, $radius)), 'formEncoded' => true];
        }

        if ($pending) {
            $responses = $this->httpRequestMulti($pending, self::PLACES_BUDGET_SEC, 3);
            foreach ($pending as $cat => $req) {
                $res = $responses[$cat];
                $usedGoogle = $req['method'] === 'GET';

                if ($usedGoogle && (!$res['success'] || ($res['data']['status'] ?? '') !== 'OK')) {
                    // Same graceful degrade placesFromGoogle() already does on a
                    // Google failure -- fall back to Overpass for just this
                    // category, sequentially (a single fallback request, not
                    // worth another concurrent round for one category).
                    $final[$cat] = $this->placesFromOverpass($lat, $lng, $cat, $radius);
                } elseif (!$res['success'] || (!$usedGoogle && !is_array($res['data']['elements'] ?? null))) {
                    // This mirror failed or answered with an Overpass runtime error: take the resilient single-category
                    // path (all mirrors raced, then stale cache, then local data) rather than reporting an error.
                    $final[$cat] = $this->getNearbyPlaces($lat, $lng, $cat, $radius);
                    continue;
                } else {
                    $places = $usedGoogle
                        ? $this->parseGooglePlaces($res['data']['results'] ?? [], $lat, $lng)
                        : $this->parseOverpassPlaces($res['data']['elements'] ?? [], $lat, $lng);
                    $final[$cat] = ['success' => true, 'results' => $places, 'source' => $usedGoogle ? 'google_places' : 'openstreetmap', 'origin' => 'live', 'stale' => false];
                }

                if ($final[$cat]['success'] && ($final[$cat]['origin'] ?? 'live') === 'live') {
                    $this->storePlaces($this->placesKey($lat, $lng, $cat, $radius), $final[$cat]['results']);
                }
            }
        }

        return $final;
    }

    // ════════════════════════════════════════════════════════════════════
    // ROUTING — Google Directions (if key configured) else OSRM
    // ════════════════════════════════════════════════════════════════════

    public function getRoute(float $fromLat, float $fromLng, float $toLat, float $toLng, string $mode = 'driving'): array {
        $mode = in_array($mode, ['driving', 'walking', 'cycling', 'transit']) ? $mode : 'driving';

        if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '') {
            return $this->routeFromGoogle($fromLat, $fromLng, $toLat, $toLng, $mode);
        }
        if ($mode === 'transit') {
            // FIX (Round 14): this returned an error whose text promised
            // "Showing driving/walking directions instead" — and then showed
            // nothing at all, leaving the user parked on a mode that can never
            // work. Actually fall back to driving, and say so honestly.
            $fallback = $this->routeFromOsrm($fromLat, $fromLng, $toLat, $toLng, 'driving');
            if ($fallback['success']) {
                $fallback['data']['mode'] = 'driving';
                $fallback['data']['notice'] = 'Public transport routing needs a Google Maps API key, '
                    . 'which is not configured — showing the driving route instead.';
            }
            return $fallback;
        }

        return $this->routeFromOsrm($fromLat, $fromLng, $toLat, $toLng, $mode);
    }

    private function routeFromOsrm(float $fromLat, float $fromLng, float $toLat, float $toLng, string $mode): array {
        $profile = ['driving' => 'driving', 'walking' => 'foot', 'cycling' => 'bike'][$mode] ?? 'driving';
        $fossgis = ['driving' => 'car', 'walking' => 'foot', 'cycling' => 'bike'][$mode] ?? 'car';
        $q = http_build_query(['overview' => 'full', 'geometries' => 'geojson']);
        $coords = "$fromLng,$fromLat;$toLng,$toLat";
        // FOSSGIS serves real foot/bike profiles (the project-osrm demo server only routes by car whatever the profile
        // says), so try it first for those modes; both get short timeouts so a dead server cannot stall the page.
        $urls = [
            "https://routing.openstreetmap.de/routed-$fossgis/route/v1/driving/$coords?$q",
            "https://router.project-osrm.org/route/v1/$profile/$coords?$q",
        ];
        if ($mode === 'driving') $urls = array_reverse($urls);

        $res = ['success' => false];
        foreach ($urls as $url) {
            $res = $this->httpRequest($url, 'GET', null, false, 8, 3);
            if ($res['success'] && ($res['data']['code'] ?? '') === 'Ok' && !empty($res['data']['routes'][0])) break;
            $res = ['success' => false];
        }
        if (!$res['success']) {
            return ['success' => false, 'error' => 'The routing service did not answer in time. Showing the straight-line distance only; please try again shortly.'];
        }

        $route  = $res['data']['routes'][0];
        $coords = array_map(fn($c) => [$c[1], $c[0]], $route['geometry']['coordinates'] ?? []);

        return ['success' => true, 'data' => [
            'mode'          => $mode,
            'distance_m'    => (int)$route['distance'],
            'duration_s'    => (int)$route['duration'],
            'distance_text' => $this->formatDistance($route['distance']),
            'duration_text' => $this->formatDuration($route['duration']),
            'geometry'      => $coords,
            'source'        => 'osrm',
        ]];
    }

    private function routeFromGoogle(float $fromLat, float $fromLng, float $toLat, float $toLng, string $mode): array {
        $modeMap = ['driving' => 'driving', 'walking' => 'walking', 'cycling' => 'bicycling', 'transit' => 'transit'];
        $url = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query([
            'origin'      => "$fromLat,$fromLng",
            'destination' => "$toLat,$toLng",
            'mode'        => $modeMap[$mode] ?? 'driving',
            'key'         => GOOGLE_MAPS_API_KEY,
        ]);

        $res = $this->httpGetJson($url);
        if (!$res['success'] || ($res['data']['status'] ?? '') !== 'OK' || empty($res['data']['routes'][0])) {
            return $mode === 'transit'
                ? ['success' => false, 'error' => 'No public transit route found between these points.']
                : $this->routeFromOsrm($fromLat, $fromLng, $toLat, $toLng, $mode);
        }

        $leg = $res['data']['routes'][0]['legs'][0];
        // FIX (Round 14): 'geometry' was hardcoded to [] so the client never drew
        // a line for a Google route — configuring a Maps key silently made the
        // map WORSE than the keyless OSRM path, which does return geometry.
        // Google supplies an encoded polyline; decode it.
        $encoded  = $res['data']['routes'][0]['overview_polyline']['points'] ?? '';
        $geometry = $encoded !== '' ? $this->decodePolyline($encoded) : [];
        return ['success' => true, 'data' => [
            'mode'          => $mode,
            'distance_m'    => $leg['distance']['value'] ?? 0,
            'duration_s'    => $leg['duration']['value'] ?? 0,
            'distance_text' => $leg['distance']['text'] ?? '',
            'duration_text' => $leg['duration']['text'] ?? '',
            'geometry'      => $geometry,
            'source'        => 'google_directions',
        ]];
    }

    /**
     * Decode Google's encoded-polyline format into [[lat,lng], ...] pairs,
     * which is the shape the Leaflet layer on the client already expects.
     */
    private function decodePolyline(string $encoded): array {
        $points = [];
        $index = 0; $lat = 0; $lng = 0; $len = strlen($encoded);
        while ($index < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = 0; $result = 0;
                do {
                    if ($index >= $len) return $points;
                    $b = ord($encoded[$index++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                if ($axis === 'lat') { $lat += $delta; } else { $lng += $delta; }
            }
            $points[] = [$lat / 1e5, $lng / 1e5];
        }
        return $points;
    }

    private function formatDistance(float $meters): string {
        return $meters >= 1000 ? round($meters / 1000, 1) . ' km' : round($meters) . ' m';
    }

    private function formatDuration(float $seconds): string {
        $mins = (int)round($seconds / 60);
        if ($mins < 60) return "$mins min";
        return floor($mins / 60) . ' hr ' . ($mins % 60) . ' min';
    }

    /** Public (Phase 2i): SustainabilityCalculator reuses this exact distance
     *  math for carbon-footprint estimation instead of reimplementing it. */
    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
    }

    // ════════════════════════════════════════════════════════════════════
    // LOCAL INVENTORY (master prompt item 6, "Travel" tier) -- real,
    // already-bookable packages/hotels/activities from this system's own
    // catalog for the country being explored. Everything above this method
    // (overview, attractions, costs, safety tips) comes from the AI or a
    // dedicated emergency-contacts table; none of it was ever connected to
    // this site's actual inventory, so a visitor exploring "Bali" had no way
    // to see the real packages TourBuddy sells there without leaving Explore Mode
    // and running a separate search. Matches on exact Country only (not a
    // LIKE against free-text location strings) to avoid a false positive
    // like "France" matching "French Polynesia".
    // ════════════════════════════════════════════════════════════════════

    public function getLocalInventory(string $country): array {
        $country = trim($country);
        $out = ['packages' => [], 'hotels' => [], 'activities' => []];
        if ($country === '') return $out;

        try {
            $stmt = $this->db->prepare(
                "SELECT PackageId, PackageName, PackageLocation, PackagePrice, PackageImage, AvgRating
                 FROM tbltourpackages WHERE Status=1 AND Country=?
                 ORDER BY AvgRating DESC, PackageId DESC LIMIT 6"
            );
            $stmt->execute([$country]);
            $out['packages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        try {
            $stmt = $this->db->prepare(
                "SELECT HotelId, HotelName, City, StarRating, PricePerNight, HotelImage, AvgRating, IsSampleData
                 FROM tblhotels WHERE Status=1 AND Country=?
                 ORDER BY AvgRating DESC, HotelId DESC LIMIT 6"
            );
            $stmt->execute([$country]);
            $out['hotels'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        try {
            $stmt = $this->db->prepare(
                "SELECT ActivityId, ActivityName, City, Category, PricePerPerson, ActivityImage, AvgRating, IsSampleData
                 FROM tblactivities WHERE Status=1 AND Country=?
                 ORDER BY AvgRating DESC, ActivityId DESC LIMIT 6"
            );
            $stmt->execute([$country]);
            $out['activities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        return $out;
    }

    // ════════════════════════════════════════════════════════════════════
    // AI-GENERATED DESTINATION INFO (overview, attractions, history, etc.)
    // ════════════════════════════════════════════════════════════════════

    public function getDestinationInfo(AIClient $ai, string $name, float $lat, float $lng, string $country, string $userEmail): array {
        $placeKey = 'info_' . md5(strtolower($name) . '_' . round($lat, 2) . '_' . round($lng, 2));

        // Computed fresh on every call, whatever happens with the AI-content
        // cache below -- unlike the AI-written overview text (safe to reuse
        // for a while) or emergency_contacts (near-static), which package or
        // hotel is actually bookable can change at any time, so this must
        // never be served from the cached info_json blob.
        $localInventory = $this->getLocalInventory($country);

        $row = null;
        try {
            $stmt = $this->db->prepare("SELECT info_json FROM tbl_explore_destinations WHERE place_key=? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$placeKey]);
            $row = $stmt->fetchColumn();
        } catch (Exception $e) {}

        if ($row) {
            $decoded = json_decode($row, true);
            if (is_array($decoded)) return ['success' => true, 'data' => $decoded, 'cached' => true, 'local_inventory' => $localInventory];
        }

        $system = "You are an expert travel guide and researcher. Given a destination, respond with ONLY a single valid JSON object "
                . "(no markdown fences, no commentary) with exactly these keys: "
                . "overview (2-3 sentence string), "
                . "attractions (array of up to 6 objects: {name, description}), "
                . "history (2-3 sentence string on historical background), "
                . "best_time_to_visit (string naming best months/season and why), "
                . "local_culture (2-3 sentence string on customs, etiquette, language notes), "
                . "famous_foods (array of up to 6 objects: {name, description}), "
                . "estimated_costs (object: {budget_per_day_usd, midrange_per_day_usd, luxury_per_day_usd, currency_note}), "
                . "safety_tips (array of up to 6 short string tips), "
                . "emergency_note (1-2 sentence string with general emergency guidance for this country). "
                . "Be accurate, specific to the destination, and concise. Do not invent fake statistics.";

        $userMsg = "Destination: {$name}" . ($country ? ", {$country}" : "") . " (lat {$lat}, lng {$lng}).";

        // FIX (Round 14): the "Recently Explored" history INSERT used to live at
        // the very bottom of this method, after two early returns — so if the AI
        // call failed (which it does for every install without a Gemini key) the
        // visit was never recorded and the Recently Explored strip stayed empty
        // forever, with nothing to indicate why. Recording that someone opened a
        // destination has nothing to do with whether the AI wrote a description,
        // so it happens here, first.
        $this->recordVisit($userEmail, $name, $lat, $lng, $country);

        $res = $ai->ask($system, $userMsg, '', $userEmail);
        if (!$res['success']) {
            // Real, independently-fetched catalog data (packages/hotels/
            // activities) has nothing to do with whether the AI overview
            // call succeeded -- an install with no AI key configured (the
            // documented common case, see the FIX above) shouldn't hide a
            // visitor's own bookable inventory just because the AI text
            // failed. See the same reasoning on the parse-failure branch below.
            // No AI summary (no provider, runtime down, quota, bad key): show verified catalogue information instead of an
            // error banner. Never cached, so the real AI overview returns as soon as a provider works again.
            return $this->catalogueOverview($name, $lat, $lng, $country, $localInventory, (string)($res['error'] ?? ''));
        }

        $data = $ai->parseJson($res['content']);
        if (!$data) {
            return $this->catalogueOverview($name, $lat, $lng, $country, $localInventory, 'unparseable');
        }

        $data['emergency_contacts'] = $this->lookupEmergencyContacts($country);

        try {
            $expires = (int)EXPLORE_CACHE_TTL_INFO; // seconds; expiry is computed by the DB clock below (PHP/MySQL timezone skew safe)
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_explore_destinations (place_key, display_name, country, lat, lng, info_json, expires_at)
                 VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE info_json=VALUES(info_json), expires_at=VALUES(expires_at)"
            );
            $stmt->execute([$placeKey, $name, $country, $lat, $lng, json_encode($data, JSON_UNESCAPED_UNICODE), $expires]);
        } catch (Exception $e) {}

        return ['success' => true, 'data' => $data, 'cached' => false, 'local_inventory' => $localInventory];
    }

    /** Overview built from the catalogue and curated guides when no AI text is available (see backend/ai/OverviewFallback.php). */
    private function catalogueOverview(string $name, float $lat, float $lng, string $country, array $localInventory, string $reason): array {
        require_once __DIR__ . '/ai/OverviewFallback.php';
        try {
            $data = OverviewFallback::fromDb($this->db, $name, $country, $localInventory, $this->lookupEmergencyContacts($country));
        } catch (Throwable $e) {
            error_log('[TourBuddy Explore] catalogue overview failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Destination information is not available right now.', 'local_inventory' => $localInventory];
        }
        if ($reason !== '') error_log('[TourBuddy Explore] AI overview unavailable, using catalogue overview: ' . substr(preg_replace('/\s+/', ' ', strip_tags($reason)), 0, 160));
        return ['success' => true, 'data' => $data, 'cached' => false, 'local_inventory' => $localInventory, 'ai_unavailable' => true];
    }

    /** Record that this user opened a destination (see the note above). */
    private function recordVisit(string $userEmail, string $name, float $lat, float $lng, string $country = ''): void {
        if ($userEmail === 'guest' || $userEmail === '') return;
        try {
            $this->db->prepare("INSERT INTO tbl_explore_history (user_email, display_name, lat, lng) VALUES (?,?,?,?)")
                ->execute([$userEmail, $name, $lat, $lng]);
        } catch (Exception $e) {
            error_log('[TourBuddy Explore] history insert failed: ' . $e->getMessage());
        }
    }

    private function lookupEmergencyContacts(string $country): ?array {
        if ($country === '') return null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM tblemergencycontacts WHERE Country LIKE ? LIMIT 1");
            $stmt->execute(['%' . $country . '%']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'police'    => $row['PoliceNumber'] ?? null,
                    'ambulance' => $row['AmbulanceNumber'] ?? null,
                    'fire'      => $row['FireNumber'] ?? null,
                    'helpline'  => $row['TouristHelpline'] ?? null,
                    'embassy'   => $row['Embassy'] ?? null,
                ];
            }
        } catch (Exception $e) {}
        return null;
    }

    public function getRecentHistory(string $userEmail, int $limit = 8): array {
        if ($userEmail === '' || $userEmail === 'guest') return [];
        try {
            $stmt = $this->db->prepare(
                // FIX (Round 14): lat/lng were neither grouped nor aggregated, which
                // MySQL 5.7+/8.0 reject outright under the default ONLY_FULL_GROUP_BY
                // (error 1055). The catch below swallowed it, so on any standard MySQL
                // host "Recently Explored" silently never appeared. MariaDB (XAMPP)
                // happens to allow it, which is why it looked fine locally.
                "SELECT display_name, MAX(lat) AS lat, MAX(lng) AS lng, MAX(created_at) as last_visited
                 FROM tbl_explore_history WHERE user_email=?
                 GROUP BY display_name ORDER BY last_visited DESC LIMIT " . (int)$limit
            );
            $stmt->bindValue(1, $userEmail);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[TourBuddy Explore] getRecentHistory failed: ' . $e->getMessage());
            return [];
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // GENERIC CACHE (Phase 1d: delegates to the shared CacheService/tblcache
    // instead of this class's own tbl_explore_cache table — same behavior,
    // one implementation instead of two. Keys are namespaced 'explore_' so
    // they can't collide with AIClient's 'ai_'-prefixed keys in the same
    // shared table.)
    // ════════════════════════════════════════════════════════════════════

    private function cacheGet(string $key): mixed {
        return $this->cache->get('explore_' . $key);
    }

    private function cacheSet(string $key, mixed $value, int $ttlSeconds): void {
        $this->cache->set('explore_' . $key, $value, $ttlSeconds);
    }

    // ════════════════════════════════════════════════════════════════════
    // HTTP HELPERS
    // ════════════════════════════════════════════════════════════════════

    private function httpGetJson(string $url): array {
        return $this->httpRequest($url, 'GET', null);
    }

    private function httpPostJson(string $url, string $body, bool $formEncoded = false): array {
        return $this->httpRequest($url, 'POST', $body, $formEncoded);
    }

    /**
     * Round 113 (AI-upgrade priority, speed pass): concurrent version of
     * httpRequest() for the common case of 2+ independent upstream calls that
     * don't depend on each other's result — e.g. getWeather()'s forecast +
     * air-quality calls, or fetching several nearby-place categories at once.
     * Before this, every multi-call path in this class ran each request one
     * after another, paying the full round-trip latency of each in sequence;
     * this fires them all at once with curl_multi and returns once the
     * slowest one finishes, instead of waiting for the sum of all of them.
     *
     * Same per-request behavior as httpRequest() (headers, timeouts, JSON
     * decoding, per-host system-event logging) — this is not a different
     * code path with different failure modes, just the same one run in
     * parallel. A single slow/failed request never blocks or fails the
     * others; each key in the returned array gets its own independent
     * ['success'=>bool, 'data'=>..] or ['success'=>false,'error'=>..] result,
     * exactly like calling httpRequest() for each individually would.
     *
     * @param array<string,array{url:string,method?:string,body?:?string,formEncoded?:bool}> $requests
     *   Keyed by caller-chosen key (e.g. 'forecast','air_quality') so results
     *   can be matched back up without relying on array order.
     * @return array<string,array{success:bool,data?:mixed,error?:string}>
     */
    private function httpRequestMulti(array $requests, int $timeout = 25, int $connectTimeout = 8): array {
        if (count($requests) === 1) {
            // No concurrency to gain for exactly one request — skip the
            // curl_multi machinery entirely and reuse the plain path.
            $key = array_key_first($requests);
            $r   = $requests[$key];
            return [$key => $this->httpRequest($r['url'], $r['method'] ?? 'GET', $r['body'] ?? null, $r['formEncoded'] ?? false, $timeout, $connectTimeout)];
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($requests as $key => $r) {
            $method      = $r['method'] ?? 'GET';
            $body        = $r['body'] ?? null;
            $formEncoded = $r['formEncoded'] ?? false;

            $ch = curl_init($r['url']);
            $headers = ['User-Agent: ' . $this->ua, 'Accept: application/json'];
            if ($body !== null && !$formEncoded) $headers[] = 'Content-Type: application/json';

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING       => '',
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 5);
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            $host     = parse_url($requests[$key]['url'], PHP_URL_HOST) ?: 'unknown';

            if ($err) {
                tms_log_system_event($this->db, 'maps', $host, false, 'curl error: ' . $err);
                $results[$key] = ['success' => false, 'error' => 'Network error while contacting live data service.'];
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                tms_log_system_event($this->db, 'maps', $host, false, "HTTP $httpCode");
                $results[$key] = ['success' => false, 'error' => "Live data service returned an error (HTTP $httpCode)."];
            } else {
                $data = json_decode((string)$response, true);
                if ($data === null && $response !== 'null') {
                    tms_log_system_event($this->db, 'maps', $host, false, 'invalid JSON response');
                    $results[$key] = ['success' => false, 'error' => 'Received an invalid response from live data service.'];
                } else {
                    tms_log_system_event($this->db, 'maps', $host, true);
                    $results[$key] = ['success' => true, 'data' => $data];
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    }

    private function httpRequest(string $url, string $method, ?string $body, bool $formEncoded = false, int $timeout = 25, int $connectTimeout = 8): array {
        $ch = curl_init($url);
        $headers = ['User-Agent: ' . $this->ua, 'Accept: application/json'];
        if ($body !== null && !$formEncoded) $headers[] = 'Content-Type: application/json';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // FIX (Round 14): this was 15s while the Overpass query itself asks
            // for [timeout:20] — so any query taking 15-20s (routine in dense
            // areas) was aborted client-side and reported to the user as
            // "temporarily unavailable", even though the server would have
            // answered. Give the slowest upstream room to finish.
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        // MONITORING (Round 72, master prompt item 56): this is the single
        // low-level HTTP call every Explore Mode feature funnels through --
        // Nominatim geocoding, Overpass nearby places, routing -- so it's
        // the one safe, low-risk place to log real success/failure for the
        // "Maps" signal admin/system-health.php previously reported as "not
        // tracked" (Leaflet/OSM tiles themselves need no key/tracking, but
        // these are the actual live external calls behind the map features).
        // Logged with the request host, not the full URL/query, so no
        // coordinates or place-search text ever land in this log.
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        if ($err) {
            tms_log_system_event($this->db, 'maps', $host, false, 'curl error: ' . $err);
            return ['success' => false, 'error' => 'Network error while contacting live data service.'];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            tms_log_system_event($this->db, 'maps', $host, false, "HTTP $httpCode");
            return ['success' => false, 'error' => "Live data service returned an error (HTTP $httpCode)."];
        }

        $data = json_decode($response, true);
        if ($data === null && $response !== 'null') {
            tms_log_system_event($this->db, 'maps', $host, false, 'invalid JSON response');
            return ['success' => false, 'error' => 'Received an invalid response from live data service.'];
        }

        tms_log_system_event($this->db, 'maps', $host, true);
        return ['success' => true, 'data' => $data];
    }
}
