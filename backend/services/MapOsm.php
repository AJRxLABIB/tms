<?php
/**
 * MapOsm — pure helpers for importing tourism points of interest from OpenStreetMap (Overpass API output).
 * OSM data is licensed ODbL: imported rows keep their source reference and the attribution "OpenStreetMap contributors (ODbL)".
 * Nothing here talks to the network, so it can be unit tested. The network part lives in scripts/map_import_osm.php.
 */
class MapOsm
{
    public const ATTRIBUTION = 'OpenStreetMap contributors (ODbL)';

    /** Approximate search areas [south, west, north, east]. They only limit where we ask OSM, they are not place coordinates. */
    public const REGIONS = [
        'dhaka'     => [23.68, 90.30, 23.90, 90.50],
        'chattogram' => [22.20, 91.70, 22.50, 91.95],
        'sylhet'    => [24.80, 91.80, 24.98, 91.95],
        'coxsbazar' => [21.30, 91.90, 21.65, 92.10],
        'bandarban' => [21.95, 92.15, 22.35, 92.35],
        'rangamati' => [22.55, 92.10, 22.80, 92.30],
        'khulna'    => [22.75, 89.45, 22.92, 89.65],
        'sundarbans' => [21.50, 89.00, 22.30, 89.90],
    ];

    /** Overpass QL for named tourism-relevant features inside the bbox [south, west, north, east]. */
    public static function query(array $bbox, int $timeout = 90, int $limit = 4000): string
    {
        [$s, $w, $n, $e] = array_map('floatval', $bbox);
        $b = sprintf('(%.5F,%.5F,%.5F,%.5F)', $s, $w, $n, $e);
        $parts = [
            'nwr["name"]["tourism"~"^(attraction|museum|viewpoint|hotel|guest_house|resort|hostel|motel|information|gallery|zoo|theme_park|picnic_site)$"]',
            'nwr["name"]["historic"~"^(monument|castle|ruins|archaeological_site|fort|manor|palace|temple|church|mosque|memorial|building|tomb)$"]',
            'nwr["name"]["natural"~"^(beach|waterfall|peak|hot_spring|cave_entrance)$"]',
            'nwr["name"]["amenity"~"^(restaurant|cafe|hospital|police|bus_station|ferry_terminal|marketplace)$"]',
            'nwr["name"]["railway"="station"]',
            'nwr["name"]["aeroway"="aerodrome"]',
            'nwr["name"]["leisure"~"^(park|nature_reserve|garden)$"]',
            'nwr["name"]["boundary"="national_park"]',
        ];
        $body = '';
        foreach ($parts as $p) $body .= $p . $b . ';';
        return '[out:json][timeout:' . max(10, min(180, $timeout)) . '];(' . $body . ');out center tags ' . max(1, min(20000, $limit)) . ';';
    }

    /** Map OSM tags to a TMS map category id plus a subcategory label, or null when the feature is not tourism relevant. */
    public static function classify(array $t): ?array
    {
        $v = static fn(string $k) => strtolower((string)($t[$k] ?? ''));
        $tourism = $v('tourism'); $historic = $v('historic'); $natural = $v('natural'); $amenity = $v('amenity');
        if (in_array($tourism, ['hotel', 'guest_house', 'resort', 'hostel', 'motel'], true)) return ['hotel', $tourism];
        if ($tourism === 'museum' || $tourism === 'gallery') return ['museum', $tourism];
        if ($tourism === 'viewpoint') return ['mountain', 'viewpoint'];
        if ($tourism === 'information') return ['visitor_info', 'information'];
        if ($historic !== '') return [$v('religion') !== '' ? 'religious' : 'historic', $historic];
        if ($tourism === 'attraction' || $tourism === 'zoo' || $tourism === 'theme_park' || $tourism === 'picnic_site') return [$tourism === 'zoo' ? 'park' : 'attraction', $tourism];
        if ($natural === 'beach') return ['beach', 'beach'];
        if ($natural === 'waterfall') return ['waterfall', 'waterfall'];
        if (in_array($natural, ['peak', 'hot_spring', 'cave_entrance'], true)) return ['mountain', $natural];
        if (in_array($amenity, ['restaurant', 'cafe'], true)) return ['restaurant', $amenity];
        if ($amenity === 'hospital') return ['hospital', 'hospital'];
        if ($amenity === 'police') return ['police', 'police'];
        if ($amenity === 'bus_station') return ['transport', 'bus_station'];
        if ($amenity === 'ferry_terminal') return ['transport', 'ferry_terminal'];
        if ($amenity === 'marketplace') return ['market', 'marketplace'];
        if ($v('railway') === 'station') return ['transport', 'railway_station'];
        if ($v('aeroway') === 'aerodrome') return ['transport', 'airport'];
        if (in_array($v('leisure'), ['park', 'nature_reserve', 'garden'], true) || $v('boundary') === 'national_park') return ['park', $v('leisure') ?: 'national_park'];
        return null;
    }

    /**
     * Convert one Overpass element to a MapPlaces::create() input, or null (unnamed, no coordinates, not tourism relevant).
     * Only tags that OSM actually carries are used. Missing tags stay missing.
     */
    public static function parseElement(array $el): ?array
    {
        $t = $el['tags'] ?? null;
        if (!is_array($t)) return null;
        $name = trim((string)($t['name'] ?? ''));
        if ($name === '' || !isset($el['type'], $el['id'])) return null;
        if (!in_array($el['type'], ['node', 'way', 'relation'], true)) return null;
        $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $lng = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $cls = self::classify($t);
        if (!$cls) return null;
        [$cat, $sub] = $cls;

        $in = [
            'name' => $name, 'category' => $cat, 'subcategory' => $sub, 'lat' => round((float)$lat, 6), 'lng' => round((float)$lng, 6),
            'source' => 'osm', 'source_ref' => $el['type'] . '/' . (int)$el['id'], 'attribution' => self::ATTRIBUTION,
        ];
        $bn = trim((string)($t['name:bn'] ?? ''));
        if ($bn !== '') $in['name_bn'] = $bn;
        foreach (['addr:city', 'addr:suburb', 'addr:town', 'addr:village'] as $k) if (!empty($t[$k])) { $in['locality'] = (string)$t[$k]; break; }
        if (!empty($t['addr:district'])) $in['district'] = (string)$t['addr:district'];
        if (!empty($t['addr:country']) && strlen((string)$t['addr:country']) <= 100) $in['country'] = (string)$t['addr:country'];
        $web = trim((string)($t['website'] ?? $t['contact:website'] ?? ''));
        if (preg_match('#^https?://[^\s<>"]{3,240}$#i', $web)) $in['website'] = $web;
        $phone = trim((string)($t['phone'] ?? $t['contact:phone'] ?? ''));
        if ($phone !== '' && preg_match('/^[0-9+\-()\s]{5,40}$/', $phone)) $in['phone'] = $phone;
        $oh = trim((string)($t['opening_hours'] ?? ''));
        if ($oh !== '' && strlen($oh) <= 300) $in['opening_hours'] = ['osm' => $oh];          // raw OSM syntax, unverified
        if (isset($t['fee'])) {
            $fee = strtolower((string)$t['fee']);
            if ($fee === 'no') $in['fee_info'] = ['free' => true];
            elseif ($fee === 'yes') $in['fee_info'] = ['free' => false];
        }
        if (isset($t['wheelchair'])) {
            $w = strtolower((string)$t['wheelchair']);
            if ($w === 'yes') $in['accessibility'] = ['wheelchair' => true];
            elseif ($w === 'no') $in['accessibility'] = ['wheelchair' => false];
            elseif ($w === 'limited') $in['accessibility'] = ['wheelchair' => 'limited'];
        }
        return $in;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null parse "s,w,n,e" */
    public static function parseBbox(string $s): ?array
    {
        $p = array_map('trim', explode(',', $s));
        if (count($p) !== 4) return null;
        foreach ($p as $x) if (!is_numeric($x)) return null;
        [$so, $w, $n, $e] = array_map('floatval', $p);
        if ($so < -90 || $n > 90 || $w < -180 || $e > 180 || $n <= $so || $e <= $w) return null;
        return [$so, $w, $n, $e];
    }

    public static function areaSqDeg(array $bbox): float { return ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]); }
}
