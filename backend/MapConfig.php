<?php
/**
 * MapConfig — the one place that knows which map style, routing and geocoding providers the site uses.
 * Nothing else should hard-code a provider URL, so changing provider is an .env change, not a code change.
 *
 * Free-first: the defaults need no API key. OpenFreeMap is a free public service without an uptime guarantee, so every
 * style URL can be overridden (MAP_STYLE_LIBERTY, MAP_STYLE_POSITRON, MAP_STYLE_BRIGHT, MAP_STYLE_DARK) and the front end
 * must show an error state when a style fails to load. A 'dark' style is only offered when MAP_STYLE_DARK is set,
 * because no verified official dark style URL is shipped here.
 */
class MapConfig
{
    /** Attribution required by OpenFreeMap / OpenMapTiles / OpenStreetMap. Always shown on the map. */
    public const ATTRIBUTION = '<a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a> '
        . '&copy; <a href="https://www.openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> '
        . 'Data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';

    private const STYLE_DEFAULTS = [
        'liberty'  => ['label' => 'Standard', 'url' => 'https://tiles.openfreemap.org/styles/liberty',  'env' => 'MAP_STYLE_LIBERTY'],
        'positron' => ['label' => 'Light',    'url' => 'https://tiles.openfreemap.org/styles/positron', 'env' => 'MAP_STYLE_POSITRON'],
        'bright'   => ['label' => 'Colourful', 'url' => 'https://tiles.openfreemap.org/styles/bright',   'env' => 'MAP_STYLE_BRIGHT'],
        'dark'     => ['label' => 'Dark',     'url' => '',                                               'env' => 'MAP_STYLE_DARK'],
    ];

    /** Routing profile => default public endpoint (OSRM API compatible). Public endpoints are fair-use, not an SLA. */
    private const ROUTE_DEFAULTS = [
        'driving' => ['label' => 'Driving', 'url' => 'https://routing.openstreetmap.de/routed-car',  'profile' => 'driving', 'env' => 'MAP_ROUTE_DRIVING'],
        'walking' => ['label' => 'Walking', 'url' => 'https://routing.openstreetmap.de/routed-foot', 'profile' => 'driving', 'env' => 'MAP_ROUTE_WALKING'],
        'cycling' => ['label' => 'Cycling', 'url' => 'https://routing.openstreetmap.de/routed-bike', 'profile' => 'driving', 'env' => 'MAP_ROUTE_CYCLING'],
    ];

    /** MapTiler built-in map ids used for the four style buttons when MAPTILER_API_KEY is set (the key is a browser key: restrict it by origin in the MapTiler dashboard). */
    private const MAPTILER_STYLES = ['liberty' => 'streets-v2', 'positron' => 'dataviz-light', 'bright' => 'bright-v2', 'dark' => 'dataviz-dark'];

    public static function maptilerKey(): string
    {
        $k = self::env('MAPTILER_API_KEY');
        return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k) ? $k : '';
    }

    /** Attribution HTML for the active basemap. */
    public static function attribution(): string
    {
        if (self::maptilerKey() !== '') {
            return '<a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener">&copy; MapTiler</a> '
                . '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">&copy; OpenStreetMap contributors</a>';
        }
        return self::ATTRIBUTION;
    }

    private static function env(string $key, string $default = ''): string
    {
        if (function_exists('tms_env')) {
            $v = tms_env($key, $default);
            return is_string($v) ? trim($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $default);
        }
        $v = getenv($key);
        return $v === false ? $default : trim((string)$v);
    }

    /** Only https URLs are accepted from configuration. */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        return (strlen($url) <= 500 && preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%\-{}]*)?$#', $url)) ? $url : '';
    }

    /** @return list<array{id:string,label:string,url:string}> only styles that have a URL */
    public static function styles(): array
    {
        $out = [];
        $mt = self::maptilerKey();
        foreach (self::STYLE_DEFAULTS as $id => $d) {
            $override = self::safeUrl(self::env($d['env']));
            $url = $override !== '' ? $override : $d['url'];
            if ($override === '' && $mt !== '' && isset(self::MAPTILER_STYLES[$id])) $url = 'https://api.maptiler.com/maps/' . self::MAPTILER_STYLES[$id] . '/style.json?key=' . rawurlencode($mt);
            if ($url === '') continue;
            $out[] = ['id' => $id, 'label' => $d['label'], 'url' => $url];
        }
        return $out;
    }

    public static function defaultStyle(): string
    {
        $want = strtolower(self::env('MAP_STYLE_DEFAULT', 'liberty'));
        foreach (self::styles() as $s) if ($s['id'] === $want) return $want;
        $all = self::styles();
        return $all ? $all[0]['id'] : 'liberty';
    }

    /** @return array{0:float,1:float} [lng, lat] */
    public static function center(): array
    {
        $lat = (float)self::env('MAP_DEFAULT_LAT', '23.685');
        $lng = (float)self::env('MAP_DEFAULT_LNG', '90.3563');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { $lat = 23.685; $lng = 90.3563; }
        return [$lng, $lat];
    }

    public static function zoom(): float
    {
        $z = (float)self::env('MAP_DEFAULT_ZOOM', '6.4');
        return ($z >= 1 && $z <= 18) ? $z : 6.4;
    }

    /** Where the map may fit when it first opens, [west, south, east, north]. Bangladesh by default. */
    public static function homeBounds(): array
    {
        return [88.0, 20.6, 92.7, 26.7];
    }

    /** @return array<string,array{label:string,url:string,profile:string}> enabled routing modes only */
    public static function routeEndpoints(): array
    {
        $enabled = self::env('MAP_ROUTE_MODES', 'driving,walking,cycling');
        $allowed = array_filter(array_map('trim', explode(',', strtolower($enabled))));
        $out = [];
        foreach (self::ROUTE_DEFAULTS as $mode => $d) {
            if (!in_array($mode, $allowed, true)) continue;
            $override = self::env($d['env']);
            if (strtolower($override) === 'off') continue;
            $url = $override !== '' ? self::safeUrl($override) : $d['url'];
            if ($url === '') continue;
            $out[$mode] = ['label' => $d['label'], 'url' => rtrim($url, '/'), 'profile' => $d['profile']];
        }
        return $out;
    }

    /** @return list<string> */
    public static function routingModes(): array { return array_keys(self::routeEndpoints()); }

    public static function geocoderUrl(): string
    {
        $u = self::safeUrl(self::env('MAP_GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'));
        return $u !== '' ? $u : 'https://nominatim.openstreetmap.org/search';
    }

    /** Identifies this installation to OSM services (their policies require a real contact). */
    public static function userAgent(): string
    {
        $contact = self::env('MAP_CONTACT_EMAIL', self::env('SMTP_FROM', ''));
        $ua = 'TourBuddy-TourismManagement/1.0';
        return $contact !== '' ? $ua . ' (' . $contact . ')' : $ua;
    }

    /** @return list<string> Overpass mirrors used by the import script only (never per page view). */
    public static function overpassMirrors(): array
    {
        $env = self::env('MAP_OVERPASS_URLS', '');
        $list = $env !== '' ? array_map('trim', explode(',', $env)) : [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ];
        $out = [];
        foreach ($list as $u) { $s = self::safeUrl($u); if ($s !== '') $out[] = $s; }
        return $out;
    }

    /** Public settings for the front end (no secrets are ever put here). */
    public static function publicConfig(): array
    {
        return [
            'styles'        => self::styles(),
            'default_style' => self::defaultStyle(),
            'attribution'   => self::attribution(),
            'center'        => self::center(),
            'zoom'          => self::zoom(),
            'home_bounds'   => self::homeBounds(),
            'routing_modes' => self::routingModes(),
        ];
    }
}
