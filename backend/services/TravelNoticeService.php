<?php
/**
 * TravelNoticeService — FOUNDATION for travel-risk information (disruptions, entry requirements,
 * sustainability notes). Honest by design:
 *
 *  - It holds ONLY what a human administrator typed in, each with a mandatory source link and the
 *    date they last checked it. Nothing here is fetched, scraped, scored or simulated.
 *  - Every notice shows its source, its "checked on" date and a visible "may be out of date" flag once it
 *    is older than STALE_DAYS for its kind. Expired disruption notices disappear.
 *  - There is NO sustainability score and NO live alert feed. Those need licensed data sources; connecting one
 *    later means writing into this table (or replacing the reader), not changing the pages.
 */
final class TravelNoticeService
{
    public const KINDS = [
        'disruption'     => 'Disruption or advisory',
        'entry'          => 'Entry requirements',
        'sustainability' => 'Sustainability information',
    ];
    /** Days after which a notice is flagged as possibly out of date. */
    public const STALE_DAYS = ['disruption' => 7, 'entry' => 90, 'sustainability' => 365];
    public const MAX_DISRUPTION_DAYS = 60;

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_travel_notices` (
  `NoticeId` int(11) NOT NULL AUTO_INCREMENT,
  `Kind` varchar(20) NOT NULL,
  `Country` varchar(100) NOT NULL,
  `Title` varchar(160) NOT NULL,
  `Body` text NOT NULL,
  `SourceName` varchar(120) NOT NULL,
  `SourceUrl` varchar(255) NOT NULL,
  `VerifiedOn` date NOT NULL,
  `ExpiresOn` date DEFAULT NULL,
  `Status` varchar(12) NOT NULL DEFAULT 'active',
  `CreatedBy` varchar(60) DEFAULT NULL,
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`NoticeId`), KEY `idx_notice_country` (`Country`,`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @return array{ok:bool,errors:array<string,string>,id:?int} */
    public static function save(PDO $dbh, ?int $id, array $in, string $actor): array
    {
        $e = [];
        $kind = (string)($in['kind'] ?? '');
        $country = trim((string)($in['country'] ?? ''));
        $title = trim((string)($in['title'] ?? ''));
        $body = trim((string)($in['body'] ?? ''));
        $srcName = trim((string)($in['source_name'] ?? ''));
        $srcUrl = trim((string)($in['source_url'] ?? ''));
        $ver = (string)($in['verified_on'] ?? '');
        $exp = (string)($in['expires_on'] ?? '');
        $today = new DateTime('today');
        if (!isset(self::KINDS[$kind])) $e['kind'] = 'Choose a type.';
        if ($country === '' || mb_strlen($country) > 100) $e['country'] = 'Enter the country (up to 100 characters).';
        if (mb_strlen($title) < 3 || mb_strlen($title) > 160) $e['title'] = 'Enter a title (3-160 characters).';
        if ($body === '' || mb_strlen($body) > 2000) $e['body'] = 'Enter the details (up to 2000 characters).';
        if ($srcName === '' || mb_strlen($srcName) > 120) $e['source_name'] = 'Name the source (for example the government website).';
        if (!preg_match('~^https?://[^\s<>"]+$~i', $srcUrl) || mb_strlen($srcUrl) > 255) $e['source_url'] = 'Enter the source link (must start with http:// or https://).';
        $v = DateTime::createFromFormat('!Y-m-d', $ver);
        if (!$v || $v->format('Y-m-d') !== $ver) $e['verified_on'] = 'Enter the date you checked this against the source.';
        elseif ($v > $today) $e['verified_on'] = 'The checked-on date cannot be in the future.';
        $x = null;
        if ($exp !== '') {
            $x = DateTime::createFromFormat('!Y-m-d', $exp);
            if (!$x || $x->format('Y-m-d') !== $exp) $e['expires_on'] = 'Enter a valid expiry date.';
            elseif ($v && $x < $v) $e['expires_on'] = 'Expiry cannot be before the checked-on date.';
        }
        if ($kind === 'disruption' && !isset($e['expires_on'])) {
            if ($x === null) $e['expires_on'] = 'Disruption notices must have an expiry date, so they cannot go stale silently.';
            elseif ($v && $v->diff($x)->days > self::MAX_DISRUPTION_DAYS) $e['expires_on'] = 'Disruption notices can last at most ' . self::MAX_DISRUPTION_DAYS . ' days from the checked-on date.';
        }
        if ($e) return ['ok' => false, 'errors' => $e, 'id' => null];
        $args = [$kind, $country, $title, $body, $srcName, $srcUrl, $ver, $exp !== '' ? $exp : null];
        try {
            if ($id) {
                $args[] = $id;
                $st = $dbh->prepare("UPDATE tbl_travel_notices SET Kind=?, Country=?, Title=?, Body=?, SourceName=?, SourceUrl=?, VerifiedOn=?, ExpiresOn=? WHERE NoticeId=?");
                $st->execute($args);
                return ['ok' => true, 'errors' => [], 'id' => $id];
            }
            $args[] = mb_substr($actor, 0, 60);
            $dbh->prepare("INSERT INTO tbl_travel_notices (Kind, Country, Title, Body, SourceName, SourceUrl, VerifiedOn, ExpiresOn, CreatedBy) VALUES (?,?,?,?,?,?,?,?,?)")->execute($args);
            return ['ok' => true, 'errors' => [], 'id' => (int)$dbh->lastInsertId()];
        } catch (Throwable $ex) {
            error_log('[TourBuddy TravelNotices] save: ' . $ex->getMessage());
            return ['ok' => false, 'errors' => ['form' => 'Could not save the notice.'], 'id' => null];
        }
    }

    public static function setStatus(PDO $dbh, int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'archived'], true)) return false;
        $st = $dbh->prepare('UPDATE tbl_travel_notices SET Status=? WHERE NoticeId=?');
        $st->execute([$status, $id]);
        return $st->rowCount() === 1;
    }

    public static function find(PDO $dbh, int $id): ?array
    {
        $st = $dbh->prepare('SELECT * FROM tbl_travel_notices WHERE NoticeId=?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Admin list, newest first, all statuses. @return array<int,array> */
    public static function all(PDO $dbh): array
    {
        return self::decorate($dbh->query('SELECT * FROM tbl_travel_notices ORDER BY Status, NoticeId DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Customer-facing: active, not expired, optionally one country (case-insensitive exact match).
     * Each row gets `stale` (bool) and `age_days`. @return array<int,array>
     */
    public static function visible(PDO $dbh, ?string $country = null): array
    {
        try {
            $sql = "SELECT * FROM tbl_travel_notices WHERE Status='active' AND (ExpiresOn IS NULL OR ExpiresOn >= CURDATE())";
            $args = [];
            if ($country !== null && trim($country) !== '') { $sql .= ' AND LOWER(Country) = LOWER(?)'; $args[] = trim($country); }
            $st = $dbh->prepare($sql . ' ORDER BY FIELD(Kind,\'disruption\',\'entry\',\'sustainability\'), VerifiedOn DESC LIMIT 100');
            $st->execute($args);
            return self::decorate($st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('[TourBuddy TravelNotices] visible: ' . $e->getMessage());
            return [];
        }
    }

    private static function decorate(array $rows): array
    {
        $today = new DateTime('today');
        foreach ($rows as &$r) {
            $age = (int)(new DateTime($r['VerifiedOn']))->diff($today)->format('%r%a');
            $r['age_days'] = $age;
            $r['stale'] = $age > (self::STALE_DAYS[$r['Kind']] ?? 30);
            $r['expired'] = $r['ExpiresOn'] !== null && $r['ExpiresOn'] < $today->format('Y-m-d');
        }
        return $rows;
    }

    public static function countries(PDO $dbh): array
    {
        return $dbh->query("SELECT DISTINCT Country FROM tbl_travel_notices WHERE Status='active' AND (ExpiresOn IS NULL OR ExpiresOn >= CURDATE()) ORDER BY Country")->fetchAll(PDO::FETCH_COLUMN);
    }
}
