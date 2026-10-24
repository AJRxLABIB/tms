<?php
/**
 * OverviewFallback — a destination overview built ONLY from data the site really holds, used whenever no AI provider can
 * write one (none configured, runtime down, quota, invalid key). It replaces the old "The AI assistant is temporarily
 * unavailable" error banner with something useful and honest:
 *
 *   - curated, sourced guide text (tbl_bd_destination_guides, migration 011) when a guide matches the destination;
 *   - the packages, hotels and activities the catalogue really lists for the place / country (demo rows labelled, never priced);
 *   - emergency contacts from tblemergencycontacts, and the live weather the page already shows.
 *
 * Nothing is invented: a field with no source is left empty, and the front end hides empty cards. The result has the
 * same shape as the AI overview (overview, attractions, history, ...) so the existing renderer works, plus:
 *   source = 'catalogue', ai_note (the honest one-liner), guide_sources, researched_at, how_to_get_there.
 *
 * build() is pure (fixture rows in, array out) so it is unit-tested without a database.
 */
final class OverviewFallback
{
    public const NOTE = 'AI summary unavailable — showing verified catalogue information.';

    /** "Cox's Bazar, Bangladesh" -> "coxs bazar": lower case, no apostrophes/punctuation, single spaces, first comma segment only. */
    public static function normalizeName(string $s): string
    {
        $s = trim(explode(',', $s)[0]);
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace(["'", "’", "`"], '', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim((string)$s);
    }

    /**
     * @param array<int,array<string,mixed>> $guides rows of tbl_bd_destination_guides
     * @return ?array<string,mixed>
     */
    public static function matchGuide(array $guides, string $destination): ?array
    {
        $d = self::normalizeName($destination);
        if ($d === '') return null;
        $best = null; $bestScore = 0;
        foreach ($guides as $g) {
            $n = self::normalizeName((string)($g['name'] ?? ''));
            $slug = str_replace('-', ' ', mb_strtolower((string)($g['slug'] ?? ''), 'UTF-8'));
            $score = 0;
            if ($n !== '' && $n === $d) $score = 100;
            elseif ($slug !== '' && $slug === $d) $score = 95;
            elseif ($n !== '' && (strpos($n, $d) !== false || strpos($d, $n) !== false) && min(strlen($n), strlen($d)) >= 4) $score = 60;
            elseif ($slug !== '' && (strpos($slug, $d) !== false || strpos($d, $slug) !== false) && min(strlen($slug), strlen($d)) >= 4) $score = 55;
            if ($score > $bestScore) { $best = $g; $bestScore = $score; }
        }
        return $best;
    }

    private static function taka($n): string { return '৳ ' . number_format((int)$n); }

    /**
     * @param ?array $guide            matched guide row or null
     * @param array  $inventory        ['packages'=>[],'hotels'=>[],'activities'=>[]] as ExploreService::getLocalInventory()
     * @param array  $placePackages    packages whose location mentions the destination (rows: PackageName, PackageLocation, PackagePrice, PackageDuration?)
     * @param ?array $emergency        police/ambulance/fire/helpline/embassy or null
     * @return array<string,mixed>
     */
    public static function build(?array $guide, array $inventory, array $placePackages, ?array $emergency, string $name, string $country, ?array $weather = null): array
    {
        $place = trim(explode(',', $name)[0]);
        $nPkg = count($inventory['packages'] ?? []);
        $nHotel = count($inventory['hotels'] ?? []);
        $nAct = count($inventory['activities'] ?? []);

        $attractions = [];
        $overview = '';
        $bestTime = '';
        $how = '';
        $safety = [];
        $sources = [];
        $researched = '';

        if ($guide) {
            $known = array_values(array_filter(array_map('trim', explode('|', (string)($guide['known_for'] ?? '')))));
            foreach (array_slice($known, 0, 6) as $k) $attractions[] = ['name' => $k, 'description' => ''];
            $overview = $place . ': ' . implode('; ', array_slice($known, 0, 3)) . '.';
            $bestTime = trim((string)($guide['best_time'] ?? ''));
            $how = trim((string)($guide['how_to_get_there'] ?? ''));
            $pn = trim((string)($guide['practical_note'] ?? ''));
            if ($pn !== '') $safety[] = $pn;
            $sources = array_values(array_filter(array_map('trim', explode('|', (string)($guide['sources'] ?? '')))));
            $researched = (string)($guide['researched_at'] ?? '');
        } else {
            $overview = $place . ($country !== '' ? ", {$country}" : '') . '. We do not have a curated guide for this place yet.';
        }

        // Catalogue facts in plain words (counts are real; nothing is estimated).
        $bits = [];
        if ($nPkg) $bits[] = $nPkg . ' tour package' . ($nPkg === 1 ? '' : 's');
        if ($nHotel) $bits[] = $nHotel . ' hotel' . ($nHotel === 1 ? '' : 's');
        if ($nAct) $bits[] = $nAct . ' activit' . ($nAct === 1 ? 'y' : 'ies');
        if ($bits && $country !== '') $overview .= ' Our catalogue lists ' . implode(', ', $bits) . ' in ' . $country . '.';
        elseif (!$bits && !$guide) $overview .= ' Nothing is listed in our catalogue for it at the moment.';

        // Real tours at this place become "things to do" entries (name, price, length from the catalogue).
        foreach (array_slice($placePackages, 0, 6 - count($attractions)) as $p) {
            $desc = 'Tour package';
            if (!empty($p['PackageDuration'])) $desc .= ' · ' . $p['PackageDuration'];
            if (!empty($p['PackagePrice'])) $desc .= ' · from ' . self::taka($p['PackagePrice']);
            $attractions[] = ['name' => (string)($p['PackageName'] ?? ''), 'description' => $desc];
        }

        if ($weather && isset($weather['current']['temp'])) {
            $overview .= ' Right now it is ' . round((float)$weather['current']['temp']) . '°C'
                . (!empty($weather['current']['description']) ? ' (' . $weather['current']['description'] . ')' : '') . '.';
        }

        return [
            'overview'           => trim($overview),
            'attractions'        => $attractions,
            'history'            => '',
            'best_time_to_visit' => $bestTime,
            'how_to_get_there'   => $how,
            'local_culture'      => '',
            'famous_foods'       => [],
            'estimated_costs'    => null,
            'safety_tips'        => $safety,
            'emergency_note'     => '',
            'emergency_contacts' => $emergency,
            'source'             => 'catalogue',
            'ai_note'            => self::NOTE,
            'guide_sources'      => $sources,
            'researched_at'      => $researched,
        ];
    }

    /** Database wrapper: every query is optional and failure-tolerant. */
    public static function fromDb(PDO $db, string $name, string $country, array $inventory, ?array $emergency, ?array $weather = null): array
    {
        $guide = null;
        try {
            $rows = $db->query('SELECT slug, name, known_for, best_time, how_to_get_there, practical_note, sources, researched_at FROM tbl_bd_destination_guides')->fetchAll(PDO::FETCH_ASSOC);
            $guide = self::matchGuide($rows ?: [], $name);
        } catch (Throwable $e) { /* table missing: no curated guide */ }

        $placePackages = [];
        $place = trim(explode(',', $name)[0]);
        if ($place !== '') {
            try {
                $st = $db->prepare("SELECT PackageName, PackageLocation, PackagePrice, PackageDuration FROM tbltourpackages WHERE Status = 1 AND PackageLocation LIKE ? ORDER BY AvgRating DESC, PackageId DESC LIMIT 6");
                $st->execute(['%' . str_replace(['%', '_'], ['\\%', '\\_'], $place) . '%']);
                $placePackages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) { /* optional */ }
        }
        return self::build($guide, $inventory, $placePackages, $emergency, $name, $country, $weather);
    }
}
