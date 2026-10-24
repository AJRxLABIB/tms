<?php
/**
 * MapAdmin — the administrator side of the tourism map: audit trail, place listing, duplicate detection and merge,
 * review of suggested corrections, CSV import and the coverage report.
 *
 * Principles: nothing here invents data. The CSV import only creates NEW unverified 'pending' places and never overwrites
 * an existing row. The coverage report only counts what is in the database. Every mutation writes an audit row.
 * The pure functions (CSV validation, duplicate pairing, target matching) need no database and are unit-tested.
 */
require_once __DIR__ . '/MapPlaces.php';

class MapAdmin
{
    public const CSV_MAX_BYTES = 524288;   // 512 KB
    public const CSV_MAX_ROWS  = 500;
    public const CSV_COLUMNS   = ['name', 'name_bn', 'category', 'lat', 'lng', 'locality'];
    public const CSV_REQUIRED  = ['name', 'category', 'lat', 'lng'];

    /** Destinations the Bangladesh coverage report checks. alias list is matched against district, region and locality. */
    public const TARGETS = [
        'Dhaka'        => ['Dhaka', 'Dacca'],
        'Chattogram'   => ['Chattogram', 'Chittagong'],
        'Sylhet'       => ['Sylhet'],
        "Cox's Bazar"  => ["Cox's Bazar", 'Coxs Bazar', "Cox’s Bazar", 'Cox Bazar'],
        'Bandarban'    => ['Bandarban'],
        'Rangamati'    => ['Rangamati'],
        'Khulna'       => ['Khulna'],
        'Sundarbans'   => ['Sundarbans', 'Sundarban', 'Mongla', 'Koyra', 'Shyamnagar'],
    ];
    /** Categories a visitor expects to find around a destination. */
    public const EXPECTED = ['attraction', 'hotel', 'restaurant', 'transport', 'hospital'];

    // ───────────────────────── audit ─────────────────────────

    /** Never throws: an audit failure must not block the admin action, but it is logged. */
    public static function audit(PDO $db, string $actor, string $action, ?int $placeId, string $detail = '', $before = null, $after = null): void
    {
        try {
            $db->prepare('INSERT INTO tbl_map_audit (Actor, Action, PlaceId, Detail, BeforeJson, AfterJson) VALUES (?,?,?,?,?,?)')->execute([
                mb_substr($actor, 0, 100), mb_substr($action, 0, 40), $placeId, mb_substr($detail, 0, 255),
                $before === null ? null : mb_substr((string)json_encode($before, JSON_UNESCAPED_UNICODE), 0, 60000),
                $after === null ? null : mb_substr((string)json_encode($after, JSON_UNESCAPED_UNICODE), 0, 60000),
            ]);
        } catch (Throwable $e) { error_log('[MapAdmin] audit failed: ' . $e->getMessage()); }
    }

    /** @return list<array<string,mixed>> */
    public static function auditFor(PDO $db, int $placeId, int $limit = 15): array
    {
        try {
            $st = $db->prepare('SELECT Actor, Action, Detail, CreatedAt FROM tbl_map_audit WHERE PlaceId = ? ORDER BY AuditId DESC LIMIT ' . max(1, min(100, $limit)));
            $st->execute([$placeId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    // ───────────────────────── listing ─────────────────────────

    /**
     * @param array{status?:string,category?:string,verified?:string,source?:string,demo?:string,q?:string} $f
     * @return array{total:int,rows:list<array<string,mixed>>}
     */
    public static function listPlaces(PDO $db, array $f, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1']; $args = [];
        if (in_array($f['status'] ?? '', ['active', 'pending', 'archived'], true)) { $where[] = 'Status = ?'; $args[] = $f['status']; }
        if (($f['category'] ?? '') !== '' && MapPlaces::isCategory((string)$f['category'])) { $where[] = 'Category = ?'; $args[] = $f['category']; }
        if (($f['verified'] ?? '') === '1' || ($f['verified'] ?? '') === '0') { $where[] = 'IsVerified = ?'; $args[] = (int)$f['verified']; }
        if (in_array($f['source'] ?? '', ['admin', 'osm', 'package', 'guide', 'user'], true)) { $where[] = 'Source = ?'; $args[] = $f['source']; }
        if (($f['demo'] ?? '') === '1' || ($f['demo'] ?? '') === '0') { $where[] = 'IsDemo = ?'; $args[] = (int)$f['demo']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . MapPlaces::likeEscape(mb_substr($q, 0, 80, 'UTF-8')) . '%';
            $norm = '%' . MapPlaces::likeEscape(MapPlaces::normalise($q)) . '%';
            $where[] = '(Name LIKE ? OR NameBn LIKE ? OR NameNorm LIKE ? OR Locality LIKE ? OR District LIKE ?)';
            array_push($args, $like, $like, $norm, $like, $like);
        }
        $w = implode(' AND ', $where);
        $c = $db->prepare("SELECT COUNT(*) FROM tbl_map_places WHERE $w");
        $c->execute($args);
        $total = (int)$c->fetchColumn();
        $perPage = max(5, min(100, $perPage));
        $page = max(1, min($page, max(1, (int)ceil($total / $perPage))));
        $off = ($page - 1) * $perPage;
        $st = $db->prepare("SELECT PlaceId, Slug, Name, NameBn, Category, Locality, District, Lat, Lng, IsVerified, Source, IsDemo, Status, UpdatedAt FROM tbl_map_places WHERE $w ORDER BY UpdatedAt DESC, PlaceId DESC LIMIT $perPage OFFSET $off");
        $st->execute($args);
        return ['total' => $total, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'page' => $page, 'per_page' => $perPage];
    }

    public static function pendingCorrectionCount(PDO $db): int
    {
        try { return (int)$db->query("SELECT COUNT(*) FROM tbl_map_corrections WHERE Status = 'pending'")->fetchColumn(); } catch (Throwable $e) { return 0; }
    }

    // ───────────────────────── duplicates ─────────────────────────

    /** @return array{0:int,1:int} ordered pair key */
    public static function pairKey(int $a, int $b): array { return $a <= $b ? [$a, $b] : [$b, $a]; }

    /**
     * Pure: find suspected duplicate pairs in a list of rows (PlaceId, Name, Lat, Lng, Status...).
     * Two places are suspects when they are within $maxMetres and their names are alike (similarity >= 0.8).
     * Rows are sorted by latitude and only neighbours inside the latitude window are compared, so this is near-linear.
     * @param list<array<string,mixed>> $rows
     * @param array<string,bool> $dismissed keys "low:high"
     * @return list<array{a:array<string,mixed>,b:array<string,mixed>,distance_m:int,similarity:float}>
     */
    public static function findDuplicatePairs(array $rows, array $dismissed = [], int $limit = 100, float $maxMetres = 150.0): array
    {
        usort($rows, static fn($x, $y) => ((float)$x['Lat']) <=> ((float)$y['Lat']));
        $latWindow = $maxMetres / 111000.0 * 1.2;
        $out = []; $n = count($rows);
        for ($i = 0; $i < $n && count($out) < $limit; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (((float)$rows[$j]['Lat']) - ((float)$rows[$i]['Lat']) > $latWindow) break;
                $a = $rows[$i]; $b = $rows[$j];
                [$lo, $hi] = self::pairKey((int)$a['PlaceId'], (int)$b['PlaceId']);
                if (isset($dismissed[$lo . ':' . $hi])) continue;
                $d = MapPlaces::haversineM((float)$a['Lat'], (float)$a['Lng'], (float)$b['Lat'], (float)$b['Lng']);
                if ($d > $maxMetres) continue;
                $sim = MapPlaces::similarity(MapPlaces::normalise((string)$a['Name']), MapPlaces::normalise((string)$b['Name']));
                if ($sim < 0.8) continue;
                $out[] = ['a' => $a, 'b' => $b, 'distance_m' => (int)round($d), 'similarity' => round($sim, 2)];
                if (count($out) >= $limit) break;
            }
        }
        usort($out, static fn($x, $y) => [$y['similarity'], $x['distance_m']] <=> [$x['similarity'], $y['distance_m']]);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function duplicateSuspects(PDO $db, int $limit = 100): array
    {
        $rows = $db->query("SELECT PlaceId, Name, NameBn, Category, Locality, District, Lat, Lng, IsVerified, Source, IsDemo, Status FROM tbl_map_places WHERE Status <> 'archived' ORDER BY Lat LIMIT 20000")->fetchAll(PDO::FETCH_ASSOC);
        $dismissed = [];
        try {
            foreach ($db->query('SELECT PlaceLow, PlaceHigh FROM tbl_map_duplicate_dismissals')->fetchAll(PDO::FETCH_NUM) as [$l, $h]) $dismissed[(int)$l . ':' . (int)$h] = true;
        } catch (Throwable $e) { /* table missing: nothing dismissed */ }
        return self::findDuplicatePairs($rows, $dismissed, $limit);
    }

    public static function dismissDuplicate(PDO $db, int $a, int $b, string $actor): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) return false;
        [$lo, $hi] = self::pairKey($a, $b);
        $verb = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $db->prepare($verb . ' INTO tbl_map_duplicate_dismissals (PlaceLow, PlaceHigh, DismissedBy) VALUES (?,?,?)')->execute([$lo, $hi, mb_substr($actor, 0, 100)]);
        self::audit($db, $actor, 'dup_dismiss', $lo, "Not a duplicate of #$hi");
        return true;
    }

    /**
     * Merge $dropId into $keepId: aliases (including the dropped names) and photographs move to the kept place, the dropped
     * place is archived (never deleted) and the action is audited. The kept place's own data is not overwritten.
     * @return array{ok:bool,error?:string}
     */
    public static function mergePlaces(PDO $db, MapPlaces $places, int $keepId, int $dropId, string $actor): array
    {
        if ($keepId <= 0 || $dropId <= 0 || $keepId === $dropId) return ['ok' => false, 'error' => 'Choose two different places.'];
        $keep = $places->find($keepId, false); $drop = $places->find($dropId, false);
        if (!$keep || !$drop) return ['ok' => false, 'error' => 'One of the places no longer exists.'];
        if ($keep['Status'] === 'archived') return ['ok' => false, 'error' => 'The place to keep is archived.'];
        $own = !$db->inTransaction();
        try {
            if ($own) $db->beginTransaction();
            foreach ($places->aliases($dropId) as $a) $places->addAlias($keepId, $a['alias'], $a['lang']);
            $places->addAlias($keepId, (string)$drop['Name'], 'en');
            if (!empty($drop['NameBn'])) $places->addAlias($keepId, (string)$drop['NameBn'], 'bn');
            $hasPrimary = $db->prepare('SELECT COUNT(*) FROM tbl_map_place_images WHERE PlaceId = ? AND IsPrimary = 1');
            $hasPrimary->execute([$keepId]);
            $keepHasPrimary = (int)$hasPrimary->fetchColumn() > 0;
            if ($keepHasPrimary) $db->prepare('UPDATE tbl_map_place_images SET IsPrimary = 0 WHERE PlaceId = ?')->execute([$dropId]);
            $db->prepare('UPDATE tbl_map_place_images SET PlaceId = ? WHERE PlaceId = ?')->execute([$keepId, $dropId]);
            $db->prepare('DELETE FROM tbl_map_place_aliases WHERE PlaceId = ?')->execute([$dropId]);
            $places->setStatus($dropId, 'archived');
            self::audit($db, $actor, 'merge', $keepId, "Merged #$dropId into #$keepId", ['dropped' => $drop], ['kept_id' => $keepId]);
            if ($own) $db->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            error_log('[MapAdmin] merge failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The merge failed and nothing was changed.'];
        }
    }

    // ───────────────────────── corrections ─────────────────────────

    /** @return list<array<string,mixed>> pending corrections with the current place name */
    public static function pendingCorrections(PDO $db, int $limit = 100): array
    {
        $st = $db->prepare("SELECT c.*, p.Name AS PlaceName, p.Slug, p.IsVerified, p.Name AS CurName, p.NameBn AS CurNameBn, p.Category AS CurCategory, p.Summary AS CurSummary,
                p.Locality AS CurLocality, p.Website AS CurWebsite, p.Phone AS CurPhone, p.Lat AS CurLat, p.Lng AS CurLng, p.OpeningHours AS CurOpeningHours, p.FeeInfo AS CurFeeInfo, p.Status AS CurStatus
            FROM tbl_map_corrections c LEFT JOIN tbl_map_places p ON p.PlaceId = c.PlaceId WHERE c.Status = 'pending' ORDER BY c.CorrectionId ASC LIMIT " . max(1, min(200, $limit)));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Current value of the field a correction targets (for the side-by-side view). */
    public static function currentValue(array $c): string
    {
        switch ($c['Field']) {
            case 'name': return (string)($c['CurName'] ?? '');
            case 'name_bn': return (string)($c['CurNameBn'] ?? '');
            case 'category': return (string)($c['CurCategory'] ?? '');
            case 'summary': return (string)($c['CurSummary'] ?? '');
            case 'locality': return (string)($c['CurLocality'] ?? '');
            case 'website': return (string)($c['CurWebsite'] ?? '');
            case 'phone': return (string)($c['CurPhone'] ?? '');
            case 'opening_hours': return (string)($c['CurOpeningHours'] ?? '');
            case 'fee_info': return (string)($c['CurFeeInfo'] ?? '');
            case 'location': return isset($c['CurLat']) ? $c['CurLat'] . ', ' . $c['CurLng'] : '';
            case 'closed': return (string)($c['CurStatus'] ?? '');
            default: return '';
        }
    }

    /** Human-readable proposed value. */
    public static function proposedText(array $c): string
    {
        $v = json_decode((string)($c['Proposed'] ?? ''), true);
        if (is_array($v)) {
            if (isset($v['lat'], $v['lng'])) return $v['lat'] . ', ' . $v['lng'];
            return (string)json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return is_scalar($v) ? (string)$v : '';
    }

    /**
     * Review one correction. Accepting applies it through MapPlaces::update() inside the same transaction as the status change.
     * Fields that cannot be applied automatically ('other') are only marked reviewed. Verification is NOT touched: the
     * administrator re-verifies the place on its edit page after checking the change.
     * @return array{ok:bool,error?:string,applied?:bool}
     */
    public static function reviewCorrection(PDO $db, MapPlaces $places, int $id, bool $accept, string $actor, string $note = ''): array
    {
        $own = !$db->inTransaction();
        try {
            if ($own) $db->beginTransaction();
            $lock = $db->prepare("SELECT * FROM tbl_map_corrections WHERE CorrectionId = ? AND Status = 'pending' FOR UPDATE");
            $lock->execute([$id]);
            $c = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$c) { if ($own) $db->rollBack(); return ['ok' => false, 'error' => 'This correction was already reviewed.']; }
            $applied = false;
            if ($accept) {
                $pid = (int)($c['PlaceId'] ?? 0);
                $place = $pid ? $places->find($pid, false) : null;
                $v = json_decode((string)$c['Proposed'], true);
                if ($c['Field'] === 'other') { $applied = false; }
                elseif (!$place) { if ($own) $db->rollBack(); return ['ok' => false, 'error' => 'The place for this correction no longer exists.']; }
                elseif ($c['Field'] === 'closed') { $places->setStatus($pid, 'archived'); $applied = true; }
                else {
                    $in = $c['Field'] === 'location' ? (is_array($v) ? ['lat' => $v['lat'] ?? null, 'lng' => $v['lng'] ?? null] : []) : [$c['Field'] => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v];
                    $r = $places->update($pid, $in, $actor);
                    if (empty($r['ok'])) { if ($own) $db->rollBack(); return ['ok' => false, 'error' => 'The proposal is not valid: ' . implode(' ', array_map('strval', $r['errors'] ?? []))]; }
                    $applied = true;
                }
                if ($applied) self::audit($db, $actor, 'correction_accept', $pid, 'Correction #' . $id . ' (' . $c['Field'] . ')', ['current' => self::currentValueFromPlace($place, (string)$c['Field'])], ['proposed' => $v]);
            } else {
                self::audit($db, $actor, 'correction_reject', $c['PlaceId'] !== null ? (int)$c['PlaceId'] : null, 'Correction #' . $id . ': ' . mb_substr($note, 0, 150));
            }
            $db->prepare('UPDATE tbl_map_corrections SET Status = ?, ReviewedBy = ?, ReviewedAt = NOW() WHERE CorrectionId = ?')->execute([$accept ? 'accepted' : 'rejected', mb_substr($actor, 0, 100), $id]);
            if ($own) $db->commit();
            return ['ok' => true, 'applied' => $applied];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            error_log('[MapAdmin] review failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The review could not be saved.'];
        }
    }

    private static function currentValueFromPlace(?array $p, string $field): string
    {
        if (!$p) return '';
        $m = ['name' => 'Name', 'name_bn' => 'NameBn', 'category' => 'Category', 'summary' => 'Summary', 'locality' => 'Locality', 'website' => 'Website', 'phone' => 'Phone', 'opening_hours' => 'OpeningHours', 'fee_info' => 'FeeInfo'];
        if ($field === 'location') return $p['Lat'] . ', ' . $p['Lng'];
        return isset($m[$field]) ? (string)($p[$m[$field]] ?? '') : '';
    }

    // ───────────────────────── CSV import ─────────────────────────

    /**
     * Pure: parse and validate CSV text. Returns the header problems or per-row results. Nothing is written.
     * @return array{ok:bool,error?:string,rows:list<array{line:int,status:string,errors:list<string>,warnings:list<string>,data:array<string,mixed>}>}
     */
    public static function parseCsv(string $text): array
    {
        if (strlen($text) > self::CSV_MAX_BYTES) return ['ok' => false, 'error' => 'The file is larger than 512 KB.', 'rows' => []];
        if (!mb_check_encoding($text, 'UTF-8')) return ['ok' => false, 'error' => 'The file must be UTF-8 encoded (needed for Bangla names).', 'rows' => []];
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $h = fopen('php://memory', 'r+');
        fwrite($h, $text); rewind($h);
        $header = fgetcsv($h, 4000, ',', '"', '\\');
        if (!$header) { fclose($h); return ['ok' => false, 'error' => 'The file is empty.', 'rows' => []]; }
        $header = array_map(static fn($x) => strtolower(trim((string)$x)), $header);
        $missing = array_values(array_diff(self::CSV_REQUIRED, $header));
        if ($missing) { fclose($h); return ['ok' => false, 'error' => 'Missing required column(s): ' . implode(', ', $missing) . '. Expected: ' . implode(',', self::CSV_COLUMNS), 'rows' => []]; }
        $unknown = array_values(array_diff($header, self::CSV_COLUMNS));
        if ($unknown) { fclose($h); return ['ok' => false, 'error' => 'Unknown column(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(',', self::CSV_COLUMNS), 'rows' => []]; }
        $rows = []; $line = 1; $seen = [];
        while (($r = fgetcsv($h, 4000, ',', '"', '\\')) !== false) {
            $line++;
            if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
            if (count($rows) >= self::CSV_MAX_ROWS) { fclose($h); return ['ok' => false, 'error' => 'More than ' . self::CSV_MAX_ROWS . ' rows. Split the file.', 'rows' => []]; }
            $assoc = [];
            foreach ($header as $i => $col) $assoc[$col] = isset($r[$i]) ? trim((string)$r[$i]) : '';
            $errors = [];
            if (count($r) > count($header)) $errors[] = 'More cells than columns (a comma inside a value must be quoted).';
            $v = MapPlaces::validate(['name' => $assoc['name'] ?? '', 'name_bn' => $assoc['name_bn'] ?? '', 'category' => strtolower($assoc['category'] ?? ''), 'locality' => $assoc['locality'] ?? '',
                'lat' => $assoc['lat'] ?? '', 'lng' => $assoc['lng'] ?? '']);
            foreach ($v['errors'] as $f => $m) $errors[] = $f . ': ' . $m;
            if (isset($assoc['lat'], $assoc['lng']) && (!is_numeric($assoc['lat']) || !is_numeric($assoc['lng']))) $errors[] = 'lat and lng must be plain decimal numbers.';
            $key = isset($v['data']['name'], $v['data']['lat']) ? MapPlaces::normalise($v['data']['name']) . '|' . round((float)$v['data']['lat'], 3) . '|' . round((float)($v['data']['lng'] ?? 0), 3) : '';
            if ($key !== '' && isset($seen[$key])) $errors[] = 'Duplicate of line ' . $seen[$key] . ' in this file.';
            elseif ($key !== '') $seen[$key] = $line;
            $rows[] = ['line' => $line, 'status' => $errors ? 'error' : 'ok', 'errors' => $errors, 'warnings' => $v['warnings'], 'data' => $v['data'] + ['name_bn' => $assoc['name_bn'] ?? '']];
        }
        fclose($h);
        if (!$rows) return ['ok' => false, 'error' => 'The file has a header but no data rows.', 'rows' => []];
        return ['ok' => true, 'rows' => $rows];
    }

    /**
     * Import validated rows as NEW pending, unverified places. A row that looks like an existing place is skipped, never merged
     * into it. $apply=false only reports what would happen.
     * @param list<array<string,mixed>> $rows output of parseCsv()['rows']
     * @return array{created:int,skipped_duplicate:int,invalid:int,lines:list<array{line:int,result:string}>}
     */
    public static function importRows(PDO $db, MapPlaces $places, array $rows, string $actor, bool $apply): array
    {
        $out = ['created' => 0, 'skipped_duplicate' => 0, 'invalid' => 0, 'lines' => []];
        foreach ($rows as $r) {
            if ($r['status'] !== 'ok') { $out['invalid']++; $out['lines'][] = ['line' => $r['line'], 'result' => 'invalid']; continue; }
            $d = $r['data'];
            $dups = $places->findDuplicates((string)$d['name'], (float)$d['lat'], (float)$d['lng']);
            if ($dups) { $out['skipped_duplicate']++; $out['lines'][] = ['line' => $r['line'], 'result' => 'skipped: similar to place #' . $dups[0]['id']]; continue; }
            if (!$apply) { $out['created']++; $out['lines'][] = ['line' => $r['line'], 'result' => 'would create']; continue; }
            $res = $places->create(['name' => $d['name'], 'name_bn' => $d['name_bn'] ?? '', 'category' => $d['category'], 'locality' => $d['locality'] ?? '', 'lat' => $d['lat'], 'lng' => $d['lng'],
                'source' => 'admin', 'status' => 'pending'], $actor);
            if (!empty($res['ok'])) { $out['created']++; $out['lines'][] = ['line' => $r['line'], 'result' => 'created #' . $res['id']]; self::audit($db, $actor, 'csv_import', (int)$res['id'], 'Line ' . $r['line']); }
            else { $out['invalid']++; $out['lines'][] = ['line' => $r['line'], 'result' => 'failed']; }
        }
        return $out;
    }

    // ───────────────────────── coverage report ─────────────────────────

    /** Pure: the SQL LIKE patterns for a target destination. @return list<string> */
    public static function targetPatterns(string $target): array
    {
        $out = [];
        foreach (self::TARGETS[$target] ?? [$target] as $alias) $out[] = '%' . MapPlaces::likeEscape($alias) . '%';
        return $out;
    }

    /**
     * Read-only gap report from the database. Reports what exists and what is missing. It never creates records.
     * @return array{by_category:array<string,int>,empty_categories:list<string>,by_district:list<array{district:string,total:int}>,targets:list<array<string,mixed>>,archaeological:int,totals:array<string,int>}
     */
    public static function coverage(PDO $db): array
    {
        $byCat = array_fill_keys(array_column(MapPlaces::categories(), 'id'), 0);
        foreach ($db->query("SELECT Category, COUNT(*) FROM tbl_map_places WHERE Status = 'active' GROUP BY Category")->fetchAll(PDO::FETCH_NUM) as [$c, $n]) if (isset($byCat[$c])) $byCat[$c] = (int)$n;
        $empty = array_keys(array_filter($byCat, static fn($n) => $n === 0));
        $dist = [];
        foreach ($db->query("SELECT COALESCE(NULLIF(District,''), NULLIF(Region,''), '(no district recorded)') AS d, COUNT(*) AS n FROM tbl_map_places WHERE Status = 'active' GROUP BY d ORDER BY n DESC LIMIT 25")->fetchAll(PDO::FETCH_NUM) as [$d, $n]) $dist[] = ['district' => (string)$d, 'total' => (int)$n];
        $targets = [];
        foreach (array_keys(self::TARGETS) as $t) {
            $pats = self::targetPatterns($t);
            $cond = implode(' OR ', array_fill(0, count($pats), '(District LIKE ? OR Region LIKE ? OR Locality LIKE ?)'));
            $args = [];
            foreach ($pats as $p) array_push($args, $p, $p, $p);
            $st = $db->prepare("SELECT Category, COUNT(*) FROM tbl_map_places WHERE Status = 'active' AND ($cond) GROUP BY Category");
            $st->execute($args);
            $cats = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$c, $n]) $cats[(string)$c] = (int)$n;
            $targets[] = ['target' => $t, 'total' => array_sum($cats), 'categories' => $cats, 'missing' => array_values(array_diff(self::EXPECTED, array_keys($cats)))];
        }
        $arch = (int)$db->query("SELECT COUNT(*) FROM tbl_map_places WHERE Status = 'active' AND (Category = 'historic' OR Subcategory LIKE '%archaeolog%')")->fetchColumn();
        $totals = [];
        foreach ($db->query('SELECT Status, COUNT(*) FROM tbl_map_places GROUP BY Status')->fetchAll(PDO::FETCH_NUM) as [$s, $n]) $totals[(string)$s] = (int)$n;
        return ['by_category' => $byCat, 'empty_categories' => $empty, 'by_district' => $dist, 'targets' => $targets, 'archaeological' => $arch, 'totals' => $totals];
    }
}
