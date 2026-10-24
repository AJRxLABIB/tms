<?php
/**
 * MapPlaces — tourism points of interest for the map: category registry, validation, search, bounding-box and nearby
 * queries, duplicate detection and the CRUD used by the admin tools. See migration 041_map_places.sql.
 *
 * Rules enforced here:
 *  - nothing is invented: optional facts (hours, fee, rating, accessibility, family-friendly) are null when unknown;
 *  - coordinates are validated (lat -90..90, lng -180..180, not the null island 0,0) and stored as latitude, longitude;
 *  - the public readers only ever return status = 'active';
 *  - a missing value is never a negative: unknown family-friendly is null, not "no".
 *
 * The pure helpers are static (no database) so they can be unit tested; the data access methods use the PDO handle.
 */
class MapPlaces
{
    public const EARTH_RADIUS_M = 6371008.8;
    public const MAX_LIMIT = 300;
    public const MAX_NEARBY_M = 50000;
    public const MIN_NEARBY_M = 100;
    /** Bangladesh bounding box [west, south, east, north], used to flag (not forbid) coordinates outside the main market. */
    public const BD_BOUNDS = [88.0, 20.5, 92.8, 26.7];

    /** Category registry. Extend by adding a row: nothing else in the map code needs to change. */
    private const CATEGORIES = [
        'attraction'   => ['label' => 'Attraction',            'bn' => 'দর্শনীয় স্থান',       'group' => 'culture',   'icon' => 'fa-camera',        'color' => '#c2410c'],
        'historic'     => ['label' => 'Historic site',         'bn' => 'ঐতিহাসিক স্থান',      'group' => 'heritage',  'icon' => 'fa-university',    'color' => '#92400e'],
        'museum'       => ['label' => 'Museum',                'bn' => 'জাদুঘর',              'group' => 'culture',   'icon' => 'fa-landmark',      'color' => '#7c3aed'],
        'religious'    => ['label' => 'Religious site',        'bn' => 'ধর্মীয় স্থান',        'group' => 'heritage',  'icon' => 'fa-place-of-worship', 'color' => '#a16207'],
        'beach'        => ['label' => 'Beach',                 'bn' => 'সৈকত',               'group' => 'nature',    'icon' => 'fa-umbrella-beach', 'color' => '#0e7490'],
        'mountain'     => ['label' => 'Hill or viewpoint',     'bn' => 'পাহাড় / ভিউপয়েন্ট',  'group' => 'nature',    'icon' => 'fa-mountain',      'color' => '#15803d'],
        'waterfall'    => ['label' => 'Waterfall, lake or river', 'bn' => 'ঝর্না / হ্রদ / নদী', 'group' => 'nature',    'icon' => 'fa-water',         'color' => '#0369a1'],
        'park'         => ['label' => 'Park, forest or wildlife', 'bn' => 'উদ্যান / বন',       'group' => 'nature',    'icon' => 'fa-tree',          'color' => '#166534'],
        'trail'        => ['label' => 'Trail or outdoor activity', 'bn' => 'ট্রেইল / অ্যাডভেঞ্চার', 'group' => 'adventure', 'icon' => 'fa-hiking',       'color' => '#be123c'],
        'hotel'        => ['label' => 'Hotel or resort',       'bn' => 'হোটেল / রিসোর্ট',      'group' => 'stay',      'icon' => 'fa-bed',           'color' => '#1d4ed8'],
        'restaurant'   => ['label' => 'Restaurant or cafe',    'bn' => 'রেস্টুরেন্ট / ক্যাফে', 'group' => 'food',      'icon' => 'fa-utensils',      'color' => '#b91c1c'],
        'market'       => ['label' => 'Market or shopping',    'bn' => 'বাজার / শপিং',        'group' => 'culture',   'icon' => 'fa-shopping-basket', 'color' => '#a21caf'],
        'transport'    => ['label' => 'Transport',             'bn' => 'যাতায়াত',            'group' => 'transport', 'icon' => 'fa-bus',           'color' => '#475569'],
        'visitor_info' => ['label' => 'Visitor information',   'bn' => 'পর্যটক তথ্য',          'group' => 'services',  'icon' => 'fa-info-circle',   'color' => '#0f766e'],
        'hospital'     => ['label' => 'Hospital',              'bn' => 'হাসপাতাল',            'group' => 'services',  'icon' => 'fa-hospital',      'color' => '#dc2626'],
        'police'       => ['label' => 'Police',                'bn' => 'পুলিশ',               'group' => 'services',  'icon' => 'fa-shield-alt',    'color' => '#1e3a8a'],
        'other'        => ['label' => 'Other place',           'bn' => 'অন্যান্য',             'group' => 'culture',   'icon' => 'fa-map-marker-alt', 'color' => '#52525b'],
    ];

    private const GROUPS = [
        'nature' => 'Nature', 'heritage' => 'Heritage', 'culture' => 'Culture', 'food' => 'Food', 'adventure' => 'Adventure',
        'stay' => 'Accommodation', 'transport' => 'Transport', 'services' => 'Services',
    ];

    private const EDITABLE_TEXT = ['name' => 200, 'name_bn' => 200, 'subcategory' => 60, 'summary' => 500, 'locality' => 150,
        'district' => 100, 'region' => 100, 'country' => 100];

    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    // ───────────────────────── pure helpers ─────────────────────────

    /** @return list<array{id:string,label:string,label_bn:string,group:string,icon:string,color:string}> */
    public static function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $id => $c) {
            $out[] = ['id' => $id, 'label' => $c['label'], 'label_bn' => $c['bn'], 'group' => $c['group'], 'icon' => $c['icon'], 'color' => $c['color']];
        }
        return $out;
    }

    /** @return array<string,string> group id => label */
    public static function groups(): array { return self::GROUPS; }

    public static function isCategory(string $id): bool { return isset(self::CATEGORIES[$id]); }

    public static function categoryLabel(string $id): string { return self::CATEGORIES[$id]['label'] ?? self::CATEGORIES['other']['label']; }

    /** Category ids belonging to a group (nature, heritage, ...). */
    public static function categoriesOfGroup(string $group): array
    {
        $o = [];
        foreach (self::CATEGORIES as $id => $c) if ($c['group'] === $group) $o[] = $id;
        return $o;
    }

    /**
     * Keep only known category ids (a group id such as "nature" expands to its categories).
     * @return list<string>
     */
    public static function parseCategories(string $csv): array
    {
        $out = [];
        foreach (explode(',', strtolower($csv)) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            if (isset(self::CATEGORIES[$c])) $out[$c] = true;
            elseif (isset(self::GROUPS[$c])) foreach (self::categoriesOfGroup($c) as $id) $out[$id] = true;
        }
        return array_keys($out);
    }

    /**
     * Unicode-safe normalisation for matching: lower case, Bangla digits to ASCII, apostrophes removed, zero-width marks removed,
     * everything that is not a letter, mark or digit becomes a space. Bengali vowel signs (marks) are kept.
     */
    public static function normalise(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (class_exists('Normalizer')) { $n = Normalizer::normalize($s, Normalizer::FORM_KC); if (is_string($n)) $s = $n; }
        if (class_exists('BanglaNormalizer')) $s = BanglaNormalizer::digits($s);
        else $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(["'", "’", "`", "‘", "\u{200C}", "\u{200D}", "\u{00AD}"], '', $s);
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** @return list<string> */
    public static function tokens(string $normalised): array
    {
        return $normalised === '' ? [] : array_values(array_filter(explode(' ', $normalised), static fn($t) => $t !== ''));
    }

    public static function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = str_replace(["'", "’"], '', $s);
        $ascii = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $ascii = trim($ascii, '-');
        if ($ascii === '') $ascii = 'place-' . substr(sha1($name), 0, 8);
        return substr($ascii, 0, 120);
    }

    public static function validCoords($lat, $lng): bool
    {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat = (float)$lat; $lng = (float)$lng;
        if (!is_finite($lat) || !is_finite($lng)) return false;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) return false;   // "null island": almost always a missing value
        return true;
    }

    public static function inBangladesh(float $lat, float $lng): bool
    {
        [$w, $s, $e, $n] = self::BD_BOUNDS;
        return $lng >= $w && $lng <= $e && $lat >= $s && $lat <= $n;
    }

    public static function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * Parse "w,s,e,n" and clamp it to the world and to a sane maximum span. Returns null if it is not four finite numbers or is empty/inverted.
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public static function parseBbox(string $s, float $maxSpanDeg = 60.0): ?array
    {
        $p = array_map('trim', explode(',', $s));
        if (count($p) !== 4) return null;
        foreach ($p as $v) if (!is_numeric($v) || !is_finite((float)$v)) return null;
        [$w, $so, $e, $n] = array_map('floatval', $p);
        $w = max(-180.0, min(180.0, $w)); $e = max(-180.0, min(180.0, $e));
        $so = max(-90.0, min(90.0, $so)); $n = max(-90.0, min(90.0, $n));
        if ($e <= $w || $n <= $so) return null;
        if ($e - $w > $maxSpanDeg) { $c = ($e + $w) / 2; $w = $c - $maxSpanDeg / 2; $e = $c + $maxSpanDeg / 2; }
        if ($n - $so > $maxSpanDeg) { $c = ($n + $so) / 2; $so = $c - $maxSpanDeg / 2; $n = $c + $maxSpanDeg / 2; }
        return [max(-180.0, $w), max(-90.0, $so), min(180.0, $e), min(90.0, $n)];
    }

    /**
     * Bounding box around a point for a radius in metres (a cheap prefilter before the exact haversine check).
     * @return array{0:float,1:float,2:float,3:float} [west, south, east, north]
     */
    public static function radiusBox(float $lat, float $lng, float $radiusM): array
    {
        $dLat = rad2deg($radiusM / self::EARTH_RADIUS_M);
        $cos = max(0.01, cos(deg2rad($lat)));
        $dLng = rad2deg($radiusM / (self::EARTH_RADIUS_M * $cos));
        return [$lng - $dLng, max(-90.0, $lat - $dLat), $lng + $dLng, min(90.0, $lat + $dLat)];
    }

    /** Character-based Levenshtein distance (multibyte safe, no 255 limit). */
    public static function editDistance(string $a, string $b): int
    {
        if ($a === $b) return 0;
        $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($x); $m = count($y);
        if ($n === 0) return $m;
        if ($m === 0) return $n;
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$m];
    }

    /** 0..1 similarity of two normalised strings. */
    public static function similarity(string $a, string $b): float
    {
        $len = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
        if ($len === 0) return 1.0;
        return 1.0 - self::editDistance($a, $b) / $len;
    }

    /**
     * Score how well a normalised query matches one normalised candidate string.
     * 100 exact, 85 candidate starts with query, 70 every query token is a token prefix, 55 query contained, 40 fuzzy token match, 0 none.
     */
    public static function scoreMatch(string $q, string $cand): int
    {
        if ($q === '' || $cand === '') return 0;
        if ($q === $cand) return 100;
        if (str_starts_with($cand, $q)) return 85;
        $qt = self::tokens($q); $ct = self::tokens($cand);
        $allPrefix = true;
        foreach ($qt as $t) {
            $hit = false;
            foreach ($ct as $c) if (str_starts_with($c, $t)) { $hit = true; break; }
            if (!$hit) { $allPrefix = false; break; }
        }
        if ($allPrefix && $qt) return 70;
        if (mb_strpos($cand, $q, 0, 'UTF-8') !== false) return 55;
        // fuzzy: every query token has a candidate token within a small edit distance (only for tokens of 4+ letters)
        $ok = $qt !== [];
        foreach ($qt as $t) {
            $best = PHP_INT_MAX;
            foreach ($ct as $c) $best = min($best, self::editDistance($t, $c));
            $limit = mb_strlen($t, 'UTF-8') >= 7 ? 2 : (mb_strlen($t, 'UTF-8') >= 4 ? 1 : 0);
            if ($best > $limit) { $ok = false; break; }
        }
        return $ok ? 40 : 0;
    }

    /** Two places are probably the same when their names are alike and they are close together. */
    public static function isLikelyDuplicate(string $nameA, float $latA, float $lngA, string $nameB, float $latB, float $lngB, float $maxMetres = 150.0): bool
    {
        if (self::haversineM($latA, $lngA, $latB, $lngB) > $maxMetres) return false;
        return self::similarity(self::normalise($nameA), self::normalise($nameB)) >= 0.8;
    }

    private static function jsonOrNull(?string $s)
    {
        if ($s === null || trim($s) === '') return null;
        $d = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && $d !== null && $d !== [] && $d !== '') return $d;
        return json_last_error() === JSON_ERROR_NONE ? null : trim($s);
    }

    /** Map a package type such as "Beach" or "Heritage/Religious" (first part wins) to a map category id. */
    public static function categoryForPackageType(string $type): string
    {
        $first = strtolower(trim(explode('/', $type)[0]));
        return [
            'beach' => 'beach', 'island' => 'beach', 'heritage' => 'historic', 'religious' => 'religious', 'city' => 'attraction',
            'adventure' => 'trail', 'wildlife' => 'park', 'nature' => 'park', 'mountain' => 'mountain', 'wetland' => 'waterfall',
            'scenic drive' => 'attraction', 'border' => 'attraction', 'culture' => 'attraction',
        ][$first] ?? 'attraction';
    }

    /** Escape a string for use inside LIKE. */
    public static function likeEscape(string $s): string { return addcslashes($s, '%_\\'); }

    // ───────────────────────── validation ─────────────────────────

    /**
     * Validate and clean admin input. Returns ['ok'=>bool,'errors'=>[field=>msg],'data'=>[column=>value],'warnings'=>[]].
     * $partial = true only validates the keys that are present (for updates).
     */
    public static function validate(array $in, bool $partial = false): array
    {
        $err = []; $d = []; $warn = [];
        $has = static fn(string $k) => array_key_exists($k, $in);

        foreach (self::EDITABLE_TEXT as $k => $max) {
            if (!$has($k)) continue;
            $v = trim((string)$in[$k]);
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
            if (mb_strlen($v, 'UTF-8') > $max) { $err[$k] = "Too long (max $max)."; continue; }
            $d[$k] = $v === '' ? null : $v;
        }
        if (!$partial || $has('name')) {
            if (empty($d['name'])) $err['name'] = 'Name is required.';
        }
        if ($has('category') || !$partial) {
            $c = strtolower(trim((string)($in['category'] ?? 'attraction')));
            if (!isset(self::CATEGORIES[$c])) $err['category'] = 'Unknown category.'; else $d['category'] = $c;
        }
        if ($has('lat') || $has('lng') || !$partial) {
            if (!self::validCoords($in['lat'] ?? null, $in['lng'] ?? null)) $err['coordinates'] = 'Latitude (-90..90) and longitude (-180..180) are required and must not be 0,0.';
            else {
                $d['lat'] = round((float)$in['lat'], 6); $d['lng'] = round((float)$in['lng'], 6);
                if (!self::inBangladesh($d['lat'], $d['lng'])) $warn[] = 'Coordinates are outside Bangladesh. Check that latitude and longitude are not swapped.';
            }
        }
        if ($has('description')) { $d['description'] = trim((string)$in['description']) === '' ? null : mb_substr(trim((string)$in['description']), 0, 20000, 'UTF-8'); }
        if ($has('website')) {
            $w = trim((string)$in['website']);
            if ($w === '') $d['website'] = null;
            elseif (!preg_match('#^https?://[^\s<>"]{3,240}$#i', $w)) $err['website'] = 'Website must start with http:// or https://.';
            else $d['website'] = $w;
        }
        if ($has('phone')) {
            $p = trim((string)$in['phone']);
            if ($p === '') $d['phone'] = null;
            elseif (!preg_match('/^[0-9+\-()\s]{5,40}$/', $p)) $err['phone'] = 'Phone may contain digits, spaces, + - ( ) only.';
            else $d['phone'] = $p;
        }
        foreach (['opening_hours', 'fee_info', 'accessibility'] as $jk) {
            if (!$has($jk)) continue;
            $v = $in[$jk];
            if ($v === null || $v === '' || $v === []) { $d[$jk] = null; continue; }
            if (is_string($v)) {
                $j = json_decode($v, true);
                if (json_last_error() !== JSON_ERROR_NONE) { if ($jk === 'fee_info' || $jk === 'opening_hours') { $d[$jk] = mb_substr(trim($v), 0, 2000, 'UTF-8'); continue; } $err[$jk] = 'Must be valid JSON.'; continue; }
                $v = $j;
            }
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($enc === false || strlen($enc) > 4000) { $err[$jk] = 'Too large or invalid.'; continue; }
            $d[$jk] = $enc;
        }
        if ($has('facilities')) {
            $f = $in['facilities'];
            if (is_string($f)) $f = preg_split('/\s*,\s*/u', trim($f), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $clean = [];
            foreach ((array)$f as $x) { $x = trim((string)$x); if ($x !== '' && mb_strlen($x, 'UTF-8') <= 60) $clean[] = $x; }
            $clean = array_slice(array_values(array_unique($clean)), 0, 30);
            $d['facilities'] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($has('family_friendly')) {
            $v = $in['family_friendly'];
            $d['family_friendly'] = ($v === null || $v === '' || $v === 'unknown') ? null : (in_array($v, [1, '1', true, 'yes', 'true'], true) ? 1 : 0);
        }
        return ['ok' => !$err, 'errors' => $err, 'data' => $d, 'warnings' => $warn];
    }

    // ───────────────────────── reading ─────────────────────────

    /** Build the public Place array for a set of DB rows (batch loads images, package ratings and details links). */
    public function toPlaces(array $rows, ?array $origin = null): array
    {
        if (!$rows) return [];
        $ids = array_map(static fn($r) => (int)$r['PlaceId'], $rows);
        $images = $this->primaryImages($ids);
        $pkgIds = [];
        foreach ($rows as $r) if (!empty($r['PackageId'])) $pkgIds[(int)$r['PackageId']] = true;
        $pk = $this->packageInfo(array_keys($pkgIds));
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['PlaceId'];
            $img = $images[$id] ?? null;
            $rating = null; $details = null;
            $pid = (int)($r['PackageId'] ?? 0);
            if ($pid && isset($pk[$pid])) {
                $details = 'package-details.php?pkgid=' . $pid;
                $rating = $pk[$pid]['rating'];
                if (!$img && $pk[$pid]['image']) $img = $pk[$pid]['image'];
            }
            $cat = isset(self::CATEGORIES[$r['Category']]) ? $r['Category'] : 'other';
            $fam = $r['FamilyFriendly'];
            $p = [
                'id' => $id, 'slug' => $r['Slug'], 'name' => $r['Name'], 'name_bn' => $r['NameBn'] ?: null,
                'category' => $cat, 'category_label' => self::CATEGORIES[$cat]['label'], 'subcategory' => $r['Subcategory'] ?: null,
                'summary' => $r['Summary'] ?: null, 'locality' => $r['Locality'] ?: null, 'district' => $r['District'] ?: null, 'region' => $r['Region'] ?: null,
                'lat' => (float)$r['Lat'], 'lng' => (float)$r['Lng'],
                'image' => $img, 'verified' => (bool)$r['IsVerified'], 'source' => $r['Source'], 'is_demo' => (bool)$r['IsDemo'],
                'rating' => $rating,
                'opening_hours' => self::jsonOrNull($r['OpeningHours'] ?? null),
                'fee' => self::jsonOrNull($r['FeeInfo'] ?? null),
                'facilities' => (array)(self::jsonOrNull($r['Facilities'] ?? null) ?? []),
                'accessibility' => self::jsonOrNull($r['Accessibility'] ?? null),
                'family_friendly' => $fam === null ? null : (bool)$fam,
                'links' => ['details' => $details, 'map' => 'tour-map.php?place=' . rawurlencode($r['Slug'])],
            ];
            if ($r['Source'] === 'osm') $p['attribution'] = $r['Attribution'] ?: 'OpenStreetMap contributors (ODbL)';
            if ($origin) $p['distance_m'] = (int)round(self::haversineM($origin[0], $origin[1], $p['lat'], $p['lng']));
            elseif (isset($r['_distance'])) $p['distance_m'] = (int)round($r['_distance']);
            if (isset($r['_match'])) $p['match'] = $r['_match'];
            $out[] = $p;
        }
        return $out;
    }

    /** @return array<int,array{thumb:string,url:string,alt:string,credit:?string}> */
    private function primaryImages(array $ids): array
    {
        $out = [];
        if (!$ids) return $out;
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT PlaceId, File, Thumb, Alt, Credit, IsPrimary, Sort FROM tbl_map_place_images WHERE PlaceId IN ($in) AND Status = 'active' ORDER BY IsPrimary DESC, Sort ASC, ImageId ASC");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $im) {
                $pid = (int)$im['PlaceId'];
                if (isset($out[$pid])) continue;
                $out[$pid] = ['thumb' => $im['Thumb'] ?: $im['File'], 'url' => $im['File'], 'alt' => $im['Alt'] ?: '', 'credit' => $im['Credit'] ?: null];
            }
        } catch (Throwable $e) { error_log('[MapPlaces] images: ' . $e->getMessage()); }
        return $out;
    }

    /** Real rating data only: package AvgRating plus the count of approved reviews. Own (non-stock) package photos only. */
    private function packageInfo(array $pkgIds): array
    {
        $out = [];
        if (!$pkgIds) return $out;
        try {
            $in = implode(',', array_fill(0, count($pkgIds), '?'));
            $st = $this->db->prepare("SELECT PackageId, PackageName, PackageType, PackageLocation, PackageImage, AvgRating FROM tbltourpackages WHERE PackageId IN ($in)");
            $st->execute($pkgIds);
            $pk = $st->fetchAll(PDO::FETCH_ASSOC);
            $cnt = [];
            try {
                $c = $this->db->prepare("SELECT PackageId, COUNT(*) AS c FROM tblreviews WHERE PackageId IN ($in) AND Status = 1 GROUP BY PackageId");
                $c->execute($pkgIds);
                foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $x) $cnt[(int)$x['PackageId']] = (int)$x['c'];
            } catch (Throwable $e) { /* reviews table shape differs: no counts, so no rating is shown */ }
            foreach ($pk as $r) {
                $pid = (int)$r['PackageId'];
                $n = $cnt[$pid] ?? 0; $avg = (float)$r['AvgRating'];
                $rating = ($n > 0 && $avg > 0) ? ['avg' => round($avg, 1), 'count' => $n] : null;
                $image = null;
                if (function_exists('tms_card_image')) {
                    $ci = tms_card_image((string)$r['PackageImage'], (string)$r['PackageName'], (string)$r['PackageType'], (string)$r['PackageLocation']);
                    if (($ci['kind'] ?? '') === 'own') $image = ['thumb' => $ci['src'], 'url' => $ci['src'], 'alt' => (string)$r['PackageName'], 'credit' => null];
                }
                $out[$pid] = ['rating' => $rating, 'image' => $image];
            }
        } catch (Throwable $e) { error_log('[MapPlaces] packageInfo: ' . $e->getMessage()); }
        return $out;
    }

    public function find(int $id, bool $publicOnly = true): ?array
    {
        $sql = 'SELECT * FROM tbl_map_places WHERE PlaceId = ?' . ($publicOnly ? " AND Status = 'active'" : '');
        $st = $this->db->prepare($sql);
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function findBySlug(string $slug, bool $publicOnly = true): ?array
    {
        $sql = 'SELECT * FROM tbl_map_places WHERE Slug = ?' . ($publicOnly ? " AND Status = 'active'" : '');
        $st = $this->db->prepare($sql);
        $st->execute([$slug]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return list<array{thumb:string,url:string,alt:string,credit:?string,license:?string,source_url:?string}> */
    public function gallery(int $placeId): array
    {
        $out = [];
        try {
            $st = $this->db->prepare("SELECT File, Thumb, Alt, Credit, License, SourceUrl FROM tbl_map_place_images WHERE PlaceId = ? AND Status = 'active' ORDER BY IsPrimary DESC, Sort ASC, ImageId ASC LIMIT 20");
            $st->execute([$placeId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $im) {
                $out[] = ['thumb' => $im['Thumb'] ?: $im['File'], 'url' => $im['File'], 'alt' => $im['Alt'] ?: '', 'credit' => $im['Credit'] ?: null, 'license' => $im['License'] ?: null, 'source_url' => $im['SourceUrl'] ?: null];
            }
        } catch (Throwable $e) { error_log('[MapPlaces] gallery: ' . $e->getMessage()); }
        return $out;
    }

    /**
     * Places inside a bounding box with optional filters. Always bounded by $limit (max 300).
     * Filters: categories (list), q (name text), verified, family, accessible, free. Unknown data never matches a positive filter.
     * @return array{total:int,items:list<array>}
     */
    public function inBbox(array $bbox, array $f = [], int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit)); $offset = max(0, min(100000, $offset));
        [$where, $args] = $this->filterSql($f);
        $where[] = 'p.Lat BETWEEN ? AND ?'; $args[] = $bbox[1]; $args[] = $bbox[3];
        $where[] = 'p.Lng BETWEEN ? AND ?'; $args[] = $bbox[0]; $args[] = $bbox[2];
        $w = ' WHERE ' . implode(' AND ', $where);
        $cnt = $this->db->prepare('SELECT COUNT(*) FROM tbl_map_places p' . $w);
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();
        $st = $this->db->prepare('SELECT p.* FROM tbl_map_places p' . $w . ' ORDER BY p.IsVerified DESC, p.IsDemo ASC, p.Name ASC, p.PlaceId ASC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
        $st->execute($args);
        return ['total' => $total, 'items' => $this->toPlaces($st->fetchAll(PDO::FETCH_ASSOC))];
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function filterSql(array $f): array
    {
        $where = ["p.Status = 'active'"]; $args = [];
        if (!empty($f['categories'])) {
            $cats = array_values(array_filter((array)$f['categories'], 'is_string'));
            if ($cats) { $where[] = 'p.Category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')'; array_push($args, ...$cats); }
        }
        if (!empty($f['verified'])) $where[] = 'p.IsVerified = 1';
        if (!empty($f['family']))   $where[] = 'p.FamilyFriendly = 1';
        if (!empty($f['accessible'])) $where[] = "p.Accessibility LIKE '%\"wheelchair\":true%'";
        if (!empty($f['free']))     $where[] = "p.FeeInfo LIKE '%\"free\":true%'";
        if (isset($f['q']) && is_string($f['q']) && ($qn = self::normalise($f['q'])) !== '') {
            $where[] = 'p.NameNorm LIKE ?'; $args[] = '%' . self::likeEscape($qn) . '%';
        }
        return [$where, $args];
    }

    /**
     * Places within a radius of a point, nearest first. Excludes $excludeId.
     * @return list<array>
     */
    public function nearby(float $lat, float $lng, float $radiusM, array $f = [], int $limit = 30, ?int $excludeId = null): array
    {
        $radiusM = max(self::MIN_NEARBY_M, min(self::MAX_NEARBY_M, $radiusM));
        $limit = max(1, min(100, $limit));
        $box = self::radiusBox($lat, $lng, $radiusM);
        [$where, $args] = $this->filterSql($f);
        $where[] = 'p.Lat BETWEEN ? AND ?'; $args[] = $box[1]; $args[] = $box[3];
        $where[] = 'p.Lng BETWEEN ? AND ?'; $args[] = $box[0]; $args[] = $box[2];
        if ($excludeId) { $where[] = 'p.PlaceId <> ?'; $args[] = $excludeId; }
        $st = $this->db->prepare('SELECT p.* FROM tbl_map_places p WHERE ' . implode(' AND ', $where) . ' LIMIT 1000');
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = self::haversineM($lat, $lng, (float)$r['Lat'], (float)$r['Lng']);
            if ($d > $radiusM) continue;
            $r['_distance'] = $d; $rows[] = $r;
        }
        usort($rows, static fn($a, $b) => $a['_distance'] <=> $b['_distance']);
        return $this->toPlaces(array_slice($rows, 0, $limit));
    }

    /** A few related places: same category and nearby first, then same district. */
    public function related(array $row, int $limit = 6): array
    {
        $near = $this->nearby((float)$row['Lat'], (float)$row['Lng'], 25000, ['categories' => [$row['Category']]], $limit, (int)$row['PlaceId']);
        return $near;
    }

    /**
     * Search by name, alias, Bangla/English name or locality. Ranked exact > prefix > tokens > contains > fuzzy, with a small bonus for
     * verified places and for places near the given context point.
     * @return list<array> places with 'match' = name|alias|locality
     */
    public function search(string $q, ?array $near = null, int $limit = 10, array $f = []): array
    {
        $qn = self::normalise($q);
        if ($qn === '' || mb_strlen($qn, 'UTF-8') < 1) return [];
        $limit = max(1, min(50, $limit));
        $tokens = array_slice(self::tokens($qn), 0, 5);
        // each token may also stand for Banglish/alternative spellings (sylet -> sylhet)
        $groups = [];
        foreach ($tokens as $t) {
            $alts = [$t];
            if (class_exists('Banglish')) { $x = Banglish::tokens($t); if (is_array($x)) foreach ($x as $a) $alts[] = self::normalise($a); }
            $groups[] = array_values(array_unique(array_filter($alts)));
        }
        [$where, $args] = $this->filterSql(array_diff_key($f, ['q' => 1]));
        foreach ($groups as $alts) {
            $parts = [];
            foreach ($alts as $a) {
                $like = '%' . self::likeEscape($a) . '%';
                $parts[] = '(p.NameNorm LIKE ? OR LOWER(IFNULL(p.Locality, \'\')) LIKE ? OR LOWER(IFNULL(p.District, \'\')) LIKE ? OR p.PlaceId IN (SELECT a.PlaceId FROM tbl_map_place_aliases a WHERE a.Normalised LIKE ?))';
                array_push($args, $like, $like, $like, $like);
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $st = $this->db->prepare('SELECT p.* FROM tbl_map_places p WHERE ' . implode(' AND ', $where) . ' LIMIT 400');
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // fuzzy fallback for typos: only when the strict search found too little
        if (count($rows) < $limit && mb_strlen($qn, 'UTF-8') >= 4) {
            $seen = array_column($rows, 'PlaceId');
            [$w2, $a2] = $this->filterSql(array_diff_key($f, ['q' => 1]));
            $first = mb_substr($qn, 0, 1, 'UTF-8');
            $w2[] = 'p.NameNorm LIKE ?'; $a2[] = self::likeEscape($first) . '%';
            $s2 = $this->db->prepare('SELECT p.* FROM tbl_map_places p WHERE ' . implode(' AND ', $w2) . ' LIMIT 600');
            $s2->execute($a2);
            foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) if (!in_array($r['PlaceId'], $seen, true)) $rows[] = $r;
        }
        if (!$rows) return [];

        $aliases = $this->aliasesFor(array_map(static fn($r) => (int)$r['PlaceId'], $rows));
        $scored = [];
        foreach ($rows as $r) {
            $id = (int)$r['PlaceId'];
            $best = self::scoreMatch($qn, (string)$r['NameNorm']); $how = 'name';
            $nameOnly = self::scoreMatch($qn, self::normalise((string)$r['Name']));
            if ($nameOnly > $best) $best = $nameOnly;
            foreach ($aliases[$id] ?? [] as $al) {
                $s = self::scoreMatch($qn, $al);
                if ($s > $best) { $best = $s; $how = 'alias'; }
            }
            foreach ([(string)$r['Locality'], (string)$r['District']] as $loc) {
                $s = (int)(self::scoreMatch($qn, self::normalise($loc)) * 0.6);
                if ($s > $best) { $best = $s; $how = 'locality'; }
            }
            if ($best <= 0) continue;
            $score = $best + ($r['IsVerified'] ? 3 : 0) - ($r['IsDemo'] ? 2 : 0);
            if ($near) {
                $d = self::haversineM($near[0], $near[1], (float)$r['Lat'], (float)$r['Lng']);
                $score += max(0, 10 - $d / 20000);     // up to +10 for places within a few km, fading to 0 at 200 km
                $r['_distance'] = $d;
            }
            $r['_match'] = $how; $r['_score'] = $score;
            $scored[] = $r;
        }
        usort($scored, static fn($a, $b) => [$b['_score'], $a['Name']] <=> [$a['_score'], $b['Name']]);
        return $this->toPlaces(array_slice($scored, 0, $limit));
    }

    /** @return array<int,list<string>> place id => normalised aliases */
    private function aliasesFor(array $ids): array
    {
        $out = [];
        if (!$ids) return $out;
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->db->prepare("SELECT PlaceId, Normalised FROM tbl_map_place_aliases WHERE PlaceId IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$pid, $n]) $out[(int)$pid][] = (string)$n;
        }
        return $out;
    }

    /** @return list<array{id:int,name:string,distance_m:int,similarity:float}> existing places that look like the candidate */
    public function findDuplicates(string $name, float $lat, float $lng, ?int $excludeId = null, float $maxMetres = 150.0): array
    {
        $box = self::radiusBox($lat, $lng, $maxMetres);
        $st = $this->db->prepare("SELECT PlaceId, Name, Lat, Lng FROM tbl_map_places WHERE Status <> 'archived' AND Lat BETWEEN ? AND ? AND Lng BETWEEN ? AND ? LIMIT 200");
        $st->execute([$box[1], $box[3], $box[0], $box[2]]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($excludeId && (int)$r['PlaceId'] === $excludeId) continue;
            $d = self::haversineM($lat, $lng, (float)$r['Lat'], (float)$r['Lng']);
            if ($d > $maxMetres) continue;
            $sim = self::similarity(self::normalise($name), self::normalise((string)$r['Name']));
            if ($sim >= 0.8) $out[] = ['id' => (int)$r['PlaceId'], 'name' => $r['Name'], 'distance_m' => (int)round($d), 'similarity' => round($sim, 2)];
        }
        return $out;
    }

    // ───────────────────────── writing (admin and import) ─────────────────────────

    private function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = self::slugify($name); $slug = $base; $i = 2;
        $chk = $this->db->prepare('SELECT PlaceId FROM tbl_map_places WHERE Slug = ?');
        while (true) {
            $chk->execute([$slug]);
            $id = $chk->fetchColumn();
            if ($id === false || ($exceptId !== null && (int)$id === $exceptId)) return $slug;
            $slug = substr($base, 0, 110) . '-' . $i++;
            if ($i > 200) return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
    }

    /**
     * Create a place. Admin-created places are 'pending' until published unless 'status' => 'active' is passed by an authorised caller.
     * @return array{ok:bool,id?:int,errors?:array,warnings?:array,duplicates?:array}
     */
    public function create(array $in, string $by = 'system', array $opts = []): array
    {
        $v = self::validate($in, false);
        if (!$v['ok']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];
        $dups = $this->findDuplicates($d['name'], $d['lat'], $d['lng']);
        if ($dups && empty($opts['allow_duplicates'])) return ['ok' => false, 'errors' => ['duplicate' => 'A similar place already exists nearby.'], 'duplicates' => $dups];
        $source = in_array($in['source'] ?? 'admin', ['admin', 'osm', 'package', 'guide', 'user'], true) ? $in['source'] : 'admin';
        $status = in_array($in['status'] ?? 'pending', ['active', 'pending', 'archived'], true) ? $in['status'] : 'pending';
        $slug = $this->uniqueSlug($d['name']);
        $norm = trim(self::normalise($d['name']) . ' ' . self::normalise((string)($d['name_bn'] ?? '')));
        $st = $this->db->prepare('INSERT INTO tbl_map_places (Slug, Name, NameBn, NameNorm, Category, Subcategory, Summary, Description, Locality, District, Region, Country, Lat, Lng,
              Website, Phone, OpeningHours, FeeInfo, Facilities, Accessibility, FamilyFriendly, IsVerified, Source, SourceRef, Attribution, IsDemo, Status, PackageId)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?)');
        $st->execute([$slug, $d['name'], $d['name_bn'] ?? null, $norm, $d['category'] ?? 'attraction', $d['subcategory'] ?? null, $d['summary'] ?? null, $d['description'] ?? null,
            $d['locality'] ?? null, $d['district'] ?? null, $d['region'] ?? null, $d['country'] ?? null, $d['lat'], $d['lng'],
            $d['website'] ?? null, $d['phone'] ?? null, $d['opening_hours'] ?? null, $d['fee_info'] ?? null, $d['facilities'] ?? null, $d['accessibility'] ?? null,
            $d['family_friendly'] ?? null, $source, isset($in['source_ref']) ? mb_substr((string)$in['source_ref'], 0, 100) : null,
            isset($in['attribution']) ? mb_substr((string)$in['attribution'], 0, 255) : null, !empty($in['is_demo']) ? 1 : 0, $status,
            !empty($in['package_id']) ? (int)$in['package_id'] : null]);
        $id = (int)$this->db->lastInsertId();
        if (!empty($d['name_bn'])) $this->addAlias($id, $d['name_bn'], 'bn');
        return ['ok' => true, 'id' => $id, 'warnings' => $v['warnings'], 'duplicates' => $dups];
    }

    /** Update only the keys given. Changing coordinates or text never changes verification, which must be re-confirmed by an admin. */
    public function update(int $id, array $in, string $by = 'system'): array
    {
        $row = $this->find($id, false);
        if (!$row) return ['ok' => false, 'errors' => ['id' => 'Place not found.']];
        $v = self::validate($in, true);
        if (!$v['ok']) return ['ok' => false, 'errors' => $v['errors']];
        $d = $v['data'];
        $map = ['name' => 'Name', 'name_bn' => 'NameBn', 'category' => 'Category', 'subcategory' => 'Subcategory', 'summary' => 'Summary', 'description' => 'Description',
            'locality' => 'Locality', 'district' => 'District', 'region' => 'Region', 'country' => 'Country', 'lat' => 'Lat', 'lng' => 'Lng', 'website' => 'Website',
            'phone' => 'Phone', 'opening_hours' => 'OpeningHours', 'fee_info' => 'FeeInfo', 'facilities' => 'Facilities', 'accessibility' => 'Accessibility', 'family_friendly' => 'FamilyFriendly'];
        $set = []; $args = [];
        foreach ($map as $k => $col) if (array_key_exists($k, $d)) { $set[] = "$col = ?"; $args[] = $d[$k]; }
        if (array_key_exists('name', $d) || array_key_exists('name_bn', $d)) {
            $name = $d['name'] ?? $row['Name']; $bn = array_key_exists('name_bn', $d) ? $d['name_bn'] : $row['NameBn'];
            $set[] = 'NameNorm = ?'; $args[] = trim(self::normalise((string)$name) . ' ' . self::normalise((string)$bn));
        }
        if (isset($in['is_demo'])) { $set[] = 'IsDemo = ?'; $args[] = $in['is_demo'] ? 1 : 0; }
        if (!$set) return ['ok' => true, 'warnings' => []];
        $args[] = $id;
        $this->db->prepare('UPDATE tbl_map_places SET ' . implode(', ', $set) . ' WHERE PlaceId = ?')->execute($args);
        if (!empty($d['name_bn'])) $this->addAlias($id, $d['name_bn'], 'bn');
        return ['ok' => true, 'warnings' => $v['warnings']];
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'pending', 'archived'], true)) return false;
        return $this->db->prepare('UPDATE tbl_map_places SET Status = ? WHERE PlaceId = ?')->execute([$status, $id]);
    }

    public function setVerified(int $id, bool $verified, string $by): bool
    {
        if ($verified) return $this->db->prepare("UPDATE tbl_map_places SET IsVerified = 1, VerifiedBy = ?, VerifiedAt = NOW() WHERE PlaceId = ?")->execute([mb_substr($by, 0, 100), $id]);
        return $this->db->prepare('UPDATE tbl_map_places SET IsVerified = 0, VerifiedBy = NULL, VerifiedAt = NULL WHERE PlaceId = ?')->execute([$id]);
    }

    public function addAlias(int $placeId, string $alias, string $lang = 'en'): bool
    {
        $alias = trim($alias); $n = self::normalise($alias);
        if ($n === '' || mb_strlen($alias, 'UTF-8') > 200) return false;
        $lang = preg_match('/^[a-z]{2,8}$/', $lang) ? $lang : 'en';
        $verb = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        return $this->db->prepare($verb . ' INTO tbl_map_place_aliases (PlaceId, Alias, Lang, Normalised) VALUES (?,?,?,?)')->execute([$placeId, $alias, $lang, $n]);
    }

    public function removeAlias(int $aliasId): bool { return $this->db->prepare('DELETE FROM tbl_map_place_aliases WHERE AliasId = ?')->execute([$aliasId]); }

    /** @return list<array{id:int,alias:string,lang:string}> */
    public function aliases(int $placeId): array
    {
        $st = $this->db->prepare('SELECT AliasId, Alias, Lang FROM tbl_map_place_aliases WHERE PlaceId = ? ORDER BY AliasId');
        $st->execute([$placeId]);
        return array_map(static fn($r) => ['id' => (int)$r['AliasId'], 'alias' => $r['Alias'], 'lang' => $r['Lang']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Record an image row (the file is stored and validated by the upload handler). */
    public function addImage(int $placeId, string $file, ?string $thumb, string $alt, ?string $credit, ?string $license, ?string $sourceUrl, bool $primary = false): int
    {
        if ($primary) $this->db->prepare('UPDATE tbl_map_place_images SET IsPrimary = 0 WHERE PlaceId = ?')->execute([$placeId]);
        $this->db->prepare('INSERT INTO tbl_map_place_images (PlaceId, File, Thumb, Alt, Credit, License, SourceUrl, IsPrimary) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$placeId, $file, $thumb, mb_substr($alt, 0, 200), $credit ? mb_substr($credit, 0, 200) : null, $license ? mb_substr($license, 0, 100) : null, $sourceUrl ? mb_substr($sourceUrl, 0, 255) : null, $primary ? 1 : 0]);
        return (int)$this->db->lastInsertId();
    }

    // ───────────────────────── suggested corrections ─────────────────────────

    public const CORRECTION_FIELDS = ['name', 'name_bn', 'category', 'location', 'summary', 'locality', 'website', 'phone', 'opening_hours', 'fee_info', 'closed', 'other'];

    /** Queue a correction for review. Never changes a place. */
    public function submitCorrection(?int $placeId, ?string $email, string $ip, string $field, $proposed, string $note): array
    {
        if (!in_array($field, self::CORRECTION_FIELDS, true)) return ['ok' => false, 'error' => 'Unknown field.'];
        if ($placeId !== null && !$this->find($placeId, true)) return ['ok' => false, 'error' => 'Place not found.'];
        $note = trim($note);
        if (mb_strlen($note, 'UTF-8') > 1000) return ['ok' => false, 'error' => 'Note is too long.'];
        if ($field === 'location') {
            if (!is_array($proposed) || !self::validCoords($proposed['lat'] ?? null, $proposed['lng'] ?? null)) return ['ok' => false, 'error' => 'Invalid coordinates.'];
            $proposed = ['lat' => round((float)$proposed['lat'], 6), 'lng' => round((float)$proposed['lng'], 6)];
        }
        $enc = json_encode($proposed, JSON_UNESCAPED_UNICODE);
        if ($enc === false || strlen($enc) > 4000) return ['ok' => false, 'error' => 'Proposal is too large.'];
        if ($note === '' && ($proposed === null || $proposed === '')) return ['ok' => false, 'error' => 'Please describe the correction.'];
        $this->db->prepare('INSERT INTO tbl_map_corrections (PlaceId, SubmittedBy, SubmitterIp, Field, Proposed, Note) VALUES (?,?,?,?,?,?)')
            ->execute([$placeId, $email ? mb_substr($email, 0, 100) : null, mb_substr($ip, 0, 64), $field, $enc, $note !== '' ? $note : null]);
        return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
    }
}
