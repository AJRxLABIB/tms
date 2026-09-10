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
        $this->ua = 'TMS-Travel-Explorer/1.0 (+http://' . $host . '; contact: '
                  . ($contact !== '' ? $contact : 'admin@' . $host) . ')';
        $this->db    = $db;
        // ensureTable=false: includes/config.php already migrates the shared
        // tblcache table once per request via its own $tmsCache instance.
        $this->cache = $cache ?? new CacheService($db, false);
    }

    // ════════════════════════════════════════════════════════════════════
    // DESTINATION SEARCH (autocomplete)
    // ════════════════════════════════════════════════════════════════════

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

        $res = $this->httpGetJson($url);
        if (!$res['success']) return $res;

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

        $weatherRes = $this->httpGetJson($weatherUrl);
        if (!$weatherRes['success']) return $weatherRes;
        $w = $weatherRes['data'];

        // Air quality is a separate Open-Meteo endpoint
        $aqUrl = 'https://air-quality-api.open-meteo.com/v1/air-quality?' . http_build_query([
            'latitude'  => $lat,
            'longitude' => $lng,
            'current'   => 'us_aqi,pm2_5,pm10',
            'timezone'  => 'auto',
        ]);
        $aqRes = $this->httpGetJson($aqUrl);
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

    public function getNearbyPlaces(float $lat, float $lng, string $category, int $radius = 4000): array {
        if (!isset(self::CATEGORY_MAP[$category])) {
            return ['success' => false, 'error' => 'Unknown place category.'];
        }

        $cacheKey = "places_{$category}_" . round($lat, 3) . '_' . round($lng, 3) . "_$radius";
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'results' => $cached, 'cached' => true];
        }

        $result = (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== '')
            ? $this->placesFromGoogle($lat, $lng, $category, $radius)
            : $this->placesFromOverpass($lat, $lng, $category, $radius);

        if ($result['success']) {
            $this->cacheSet($cacheKey, $result['results'], EXPLORE_CACHE_TTL_PLACES);
        }
        return $result;
    }

    private function placesFromOverpass(float $lat, float $lng, string $category, int $radius): array {
        $filter = self::CATEGORY_MAP[$category]['osm'];
        $ql = "[out:json][timeout:20];(node$filter(around:$radius,$lat,$lng);way$filter(around:$radius,$lat,$lng););out center 25;";

        $res = $this->httpPostJson('https://overpass-api.de/api/interpreter', 'data=' . urlencode($ql), true);
        if (!$res['success']) {
            return ['success' => false, 'error' => 'Nearby places service is temporarily unavailable. Please try again shortly.'];
        }

        $places = [];
        foreach (($res['data']['elements'] ?? []) as $el) {
            $tags = $el['tags'] ?? [];
            $plat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $plng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if (!$plat || !$plng || empty($tags['name'])) continue;

            $addrParts = array_filter([
                $tags['addr:housenumber'] ?? '', $tags['addr:street'] ?? '',
                $tags['addr:city'] ?? '', $tags['addr:postcode'] ?? '',
            ]);

            $places[] = [
                'name'          => $tags['name'],
                'lat'           => (float)$plat,
                'lng'           => (float)$plng,
                'address'       => $addrParts ? implode(', ', $addrParts) : null,
                'phone'         => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                'opening_hours' => $tags['opening_hours'] ?? null,
                'website'       => $tags['website'] ?? $tags['contact:website'] ?? null,
                'rating'        => null,
                'photo'         => null,
                'distance_m'    => (int)$this->haversine($lat, $lng, (float)$plat, (float)$plng),
            ];
        }

        usort($places, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        return ['success' => true, 'results' => array_slice($places, 0, 20), 'source' => 'openstreetmap'];
    }

    private function placesFromGoogle(float $lat, float $lng, string $category, int $radius): array {
        $type = self::CATEGORY_MAP[$category]['google'];
        $url  = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query([
            'location' => "$lat,$lng",
            'radius'   => $radius,
            'type'     => $type,
            'key'      => GOOGLE_PLACES_API_KEY,
        ]);

        $res = $this->httpGetJson($url);
        if (!$res['success'] || ($res['data']['status'] ?? '') !== 'OK') {
            return $this->placesFromOverpass($lat, $lng, $category, $radius);
        }

        $places = [];
        foreach (($res['data']['results'] ?? []) as $p) {
            $photo = null;
            if (!empty($p['photos'][0]['photo_reference'])) {
                // SECURITY FIX (Round 14): this used to embed GOOGLE_PLACES_API_KEY
                // in a URL that was sent to the browser and rendered in an <img src>,
                // exposing the server's billing key to every visitor. Hand out the
                // opaque reference and let api/explore.php?action=place_photo stream
                // the image server-side instead.
                $photo = 'api/explore.php?action=place_photo&ref=' . urlencode($p['photos'][0]['photo_reference']);
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
                'distance_m'    => ($plat && $plng) ? (int)$this->haversine($lat, $lng, $plat, $plng) : null,
            ];
        }
        usort($places, fn($a, $b) => ($a['distance_m'] ?? 999999) <=> ($b['distance_m'] ?? 999999));
        return ['success' => true, 'results' => array_slice($places, 0, 20), 'source' => 'google_places'];
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
        $url = "https://router.project-osrm.org/route/v1/$profile/$fromLng,$fromLat;$toLng,$toLat?"
             . http_build_query(['overview' => 'full', 'geometries' => 'geojson']);

        $res = $this->httpGetJson($url);
        if (!$res['success'] || ($res['data']['code'] ?? '') !== 'Ok' || empty($res['data']['routes'][0])) {
            return ['success' => false, 'error' => 'Routing service is temporarily unavailable.'];
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
    // AI-GENERATED DESTINATION INFO (overview, attractions, history, etc.)
    // ════════════════════════════════════════════════════════════════════

    public function getDestinationInfo(AIClient $ai, string $name, float $lat, float $lng, string $country, string $userEmail): array {
        $placeKey = 'info_' . md5(strtolower($name) . '_' . round($lat, 2) . '_' . round($lng, 2));

        $row = null;
        try {
            $stmt = $this->db->prepare("SELECT info_json FROM tbl_explore_destinations WHERE place_key=? AND expires_at > NOW() LIMIT 1");
            $stmt->execute([$placeKey]);
            $row = $stmt->fetchColumn();
        } catch (Exception $e) {}

        if ($row) {
            $decoded = json_decode($row, true);
            if (is_array($decoded)) return ['success' => true, 'data' => $decoded, 'cached' => true];
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
            return ['success' => false, 'error' => $res['error'] ?? 'AI service is temporarily unavailable.'];
        }

        $data = $ai->parseJson($res['content']);
        if (!$data) {
            return ['success' => false, 'error' => 'Could not parse destination information. Please try again.'];
        }

        $data['emergency_contacts'] = $this->lookupEmergencyContacts($country);

        try {
            $expires = date('Y-m-d H:i:s', time() + EXPLORE_CACHE_TTL_INFO);
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_explore_destinations (place_key, display_name, country, lat, lng, info_json, expires_at)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE info_json=VALUES(info_json), expires_at=VALUES(expires_at)"
            );
            $stmt->execute([$placeKey, $name, $country, $lat, $lng, json_encode($data, JSON_UNESCAPED_UNICODE), $expires]);
        } catch (Exception $e) {}

        return ['success' => true, 'data' => $data, 'cached' => false];
    }

    /** Record that this user opened a destination (see the note above). */
    private function recordVisit(string $userEmail, string $name, float $lat, float $lng, string $country = ''): void {
        if ($userEmail === 'guest' || $userEmail === '') return;
        try {
            $this->db->prepare("INSERT INTO tbl_explore_history (user_email, display_name, lat, lng) VALUES (?,?,?,?)")
                ->execute([$userEmail, $name, $lat, $lng]);
        } catch (Exception $e) {
            error_log('[TMS Explore] history insert failed: ' . $e->getMessage());
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
            error_log('[TMS Explore] getRecentHistory failed: ' . $e->getMessage());
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

    private function httpRequest(string $url, string $method, ?string $body, bool $formEncoded = false): array {
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
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
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

        if ($err) {
            return ['success' => false, 'error' => 'Network error while contacting live data service.'];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "Live data service returned an error (HTTP $httpCode)."];
        }

        $data = json_decode($response, true);
        if ($data === null && $response !== 'null') {
            return ['success' => false, 'error' => 'Received an invalid response from live data service.'];
        }

        return ['success' => true, 'data' => $data];
    }
}
