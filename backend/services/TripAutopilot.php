<?php
/**
 * TripAutopilot — proposal-and-approval handling for trip disruptions.
 *
 *   spot a disruption  ->  propose a revised itinerary  ->  the CUSTOMER approves or declines  ->  only then anything changes.
 *
 * What it will and will not do (by design, enforced here rather than promised in a comment):
 *  - Disruption signals come from exactly two real sources: admin-verified, unexpired travel notices
 *    (TravelNoticeService) whose country appears in the booking's package location, and — when the caller supplies
 *    them — forecast days that ItineraryPlanner::checkWeatherRisk() flagged. Nothing is guessed or simulated.
 *  - A proposal is stored server-side. Approving applies THAT stored proposal; the browser never sends the itinerary back.
 *  - Approval changes the saved AI day-by-day plan only (a new tbl_ai_itineraries row; the old row is kept as history).
 *    It never touches the booking, dates, price, payment, refund or supplier. Real booking changes still go through
 *    the normal modification/cancellation flow.
 *  - A proposal is only applicable while it is still true: it is expired if the notice that triggered it is gone, and
 *    superseded if the saved itinerary changed after it was made. Approving twice saves once.
 */
require_once __DIR__ . '/TravelNoticeService.php';

final class TripAutopilot
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const SUPERSEDED = 'superseded';

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_autopilot_proposals` (
  `ProposalId` int(11) NOT NULL AUTO_INCREMENT,
  `BookingId` int(11) NOT NULL,
  `UserEmail` varchar(100) NOT NULL,
  `SignalKey` char(40) NOT NULL,
  `Signals` mediumtext NOT NULL,
  `BaseItineraryId` int(11) DEFAULT NULL,
  `ProposedJson` longtext DEFAULT NULL,
  `Summary` varchar(500) DEFAULT NULL,
  `Status` varchar(12) NOT NULL DEFAULT 'pending',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `DecidedAt` timestamp NULL DEFAULT NULL,
  `AppliedItineraryId` int(11) DEFAULT NULL,
  PRIMARY KEY (`ProposalId`),
  KEY `idx_ap_booking` (`BookingId`,`Status`),
  KEY `idx_ap_user` (`UserEmail`,`Status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ── Detection ─────────────────────────────────────────────────────────────────

    /**
     * Pure: which of the given notices apply to this package location? A notice applies when its country name appears
     * (case-insensitive, whole word) in the location text, e.g. "Paris and Switzerland" matches a "Switzerland" notice.
     * Only the notice kinds that can disrupt a trip qualify ('disruption'); entry/sustainability notices are information.
     * Known limit: a city-only location such as "Paris" will not match a "France" notice.
     * @param array<int,array> $notices rows from TravelNoticeService::visible()
     * @return array<int,array>
     */
    public static function matchNotices(array $notices, string $location): array
    {
        $out = [];
        foreach ($notices as $n) {
            if (($n['Kind'] ?? '') !== 'disruption') continue;
            if (!empty($n['expired'])) continue;
            $c = trim((string)($n['Country'] ?? ''));
            if ($c === '') continue;
            if (preg_match('/(?<![\p{L}])' . preg_quote($c, '/') . '(?![\p{L}])/iu', $location)) $out[] = $n;
        }
        return $out;
    }

    /**
     * How sure are we, and how bad? Pure.
     *   label    verified    an admin-verified notice that names a source and a verification date
     *            forecast    a weather forecast day (a prediction, never a confirmed event)
     *            unconfirmed anything that lacks a named source or verification date
     *   severity high / medium / low from the wording of the notice (closures, strikes, evacuations... = high) or the rain chance.
     * Severity is a triage hint for ordering alerts, not a prediction; the customer always sees the source text.
     * @return array{label:string,severity:string}
     */
    public static function classifySignal(array $s): array
    {
        if (($s['type'] ?? '') === 'weather') {
            $rain = (int)($s['rain_chance'] ?? 0);
            return ['label' => 'forecast', 'severity' => $rain >= 85 ? 'medium' : 'low'];
        }
        $label = (trim((string)($s['source_name'] ?? '')) !== '' && trim((string)($s['verified_on'] ?? '')) !== '') ? 'verified' : 'unconfirmed';
        $text = mb_strtolower((string)($s['title'] ?? '') . ' ' . (string)($s['body'] ?? ''), 'UTF-8');
        $sev = 'low';
        if (preg_match('/\b(clos(ed|ure)|evacuat\w*|strike|curfew|suspend\w*|cancel\w*|shut ?down|emergency|conflict|war|flood\w*|cyclone|hurricane|earthquake|do not travel|airport)\b/u', $text)) $sev = 'high';
        elseif (preg_match('/\b(delay\w*|warning|advisory|protest\w*|disruption|restrict\w*|alert|storm|reduced|limited)\b/u', $text)) $sev = 'medium';
        return ['label' => $label, 'severity' => $sev];
    }

    /** Turn matched notices (+ optional flagged weather days) into plain signal records (with label, severity, source and time). */
    public static function buildSignals(array $matchedNotices, array $flaggedWeatherDays = []): array
    {
        $s = [];
        foreach ($matchedNotices as $n) {
            $x = ['type' => 'notice', 'id' => (int)$n['NoticeId'], 'title' => (string)$n['Title'], 'body' => (string)$n['Body'],
                'country' => (string)$n['Country'], 'source_name' => (string)$n['SourceName'], 'source_url' => (string)$n['SourceUrl'],
                'verified_on' => (string)$n['VerifiedOn'], 'expires_on' => $n['ExpiresOn'] ?? null];
            $s[] = $x + self::classifySignal($x) + ['observed_at' => (string)$n['VerifiedOn']];
        }
        foreach ($flaggedWeatherDays as $d) {
            $x = ['type' => 'weather', 'day' => (int)($d['day'] ?? 0), 'date' => (string)($d['date'] ?? ''), 'rain_chance' => (int)($d['rain_chance'] ?? 0)];
            $s[] = $x + self::classifySignal($x) + ['observed_at' => date('Y-m-d H:i:s'), 'source_name' => 'Weather forecast'];
        }
        return $s;
    }

    /**
     * Keep one tracked row per (booking, signal) with label, severity, source and when we saw it. Idempotent: seeing the same
     * signal again only refreshes LastSeenAt (so alerts are never duplicated). Best-effort: a missing table (migration 021 not applied) never
     * breaks the check. Resolution is recorded by resolveMissing() when the signal is no longer present.
     */
    public static function recordDisruptions(PDO $dbh, int $bookingId, array $signals): void
    {
        try {
            $st = $dbh->prepare("INSERT INTO tbl_disruption_events (BookingId, SignalKey, Label, Severity, Summary, SourceName, SourceUrl, ObservedAt)
                VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE LastSeenAt = NOW(), Status = IF(Status='resolved','open',Status), ResolvedAt = IF(Status='resolved', NULL, ResolvedAt)");
            foreach ($signals as $x) {
                $key = sha1(self::signalKey([$x]));
                $sum = $x['type'] === 'notice' ? mb_substr((string)$x['title'], 0, 300) : 'Forecast: ' . (int)$x['rain_chance'] . '% chance of rain on day ' . (int)$x['day'];
                $when = (string)($x['observed_at'] ?? date('Y-m-d H:i:s')); if (strlen($when) === 10) $when .= ' 00:00:00';
                $st->execute([$bookingId, $key, $x['label'] ?? 'unconfirmed', $x['severity'] ?? 'low', $sum, $x['source_name'] ?? null, $x['source_url'] ?? null, $when]);
            }
            self::resolveMissing($dbh, $bookingId, array_map(fn($x) => sha1(self::signalKey([$x])), $signals));
        } catch (Throwable $e) {
            if (!($e instanceof PDOException && (string)$e->getCode() === '42S02')) error_log('[TourBuddy Autopilot] recordDisruptions: ' . $e->getMessage());
        }
    }

    /** Open events for this booking whose signal is no longer present are marked resolved (with a reason), never deleted. */
    public static function resolveMissing(PDO $dbh, int $bookingId, array $presentKeys): void
    {
        $sql = "UPDATE tbl_disruption_events SET Status='resolved', ResolvedAt=NOW(), Resolution='no longer reported by the source' WHERE BookingId=? AND Status='open'";
        $args = [$bookingId];
        if ($presentKeys) { $sql .= ' AND SignalKey NOT IN (' . implode(',', array_fill(0, count($presentKeys), '?')) . ')'; $args = array_merge($args, $presentKeys); }
        $dbh->prepare($sql)->execute($args);
    }

    /** Stable fingerprint of a signal set: same disruption => same key, so we never propose twice for one event. */
    public static function signalKey(array $signals): string
    {
        $parts = [];
        foreach ($signals as $x) {
            $parts[] = $x['type'] === 'notice' ? 'n' . $x['id'] . '@' . $x['verified_on'] : 'w' . $x['day'] . '@' . $x['date'];
        }
        sort($parts);
        return sha1(implode('|', $parts));
    }

    /** The traveller-facing request we give the planner. Notice text is admin-typed; it is passed as quoted data. */
    public static function replanInstruction(array $signals): string
    {
        $lines = [];
        foreach ($signals as $x) {
            if ($x['type'] === 'notice') {
                $lines[] = 'Verified disruption notice for ' . $x['country'] . ' (' . $x['source_name'] . ', checked ' . $x['verified_on'] . '): "'
                    . mb_substr(preg_replace('/\s+/', ' ', $x['title'] . ' - ' . $x['body']), 0, 500) . '"';
            } else {
                $lines[] = 'Forecast shows ' . $x['rain_chance'] . '% chance of rain on Day ' . $x['day'] . ' (' . $x['date'] . ').';
            }
        }
        return "A disruption affects this trip. Propose a revised itinerary that works around it. Change only the days and activities "
            . "that the disruption actually affects and keep every other day as it is. Do not invent closures, dates or facts beyond "
            . "what is stated here; where something is uncertain, say so in the notes. Details: " . implode(' ', $lines);
    }

    // ── Diff (pure) ───────────────────────────────────────────────────────────────

    /** @return array<int,array{day:int,status:string,old_title:?string,new_title:?string}> only changed days */
    public static function diffDays(?array $old, array $new): array
    {
        $index = function (?array $it): array {
            $m = [];
            foreach (($it['days'] ?? []) as $d) $m[(int)($d['day'] ?? 0)] = $d;
            return $m;
        };
        $o = $index($old); $n = $index($new);
        $nums = array_unique(array_merge(array_keys($o), array_keys($n)));
        sort($nums);
        $out = [];
        foreach ($nums as $k) {
            if (!isset($o[$k])) { $out[] = ['day' => $k, 'status' => 'added', 'old_title' => null, 'new_title' => $n[$k]['title'] ?? null]; continue; }
            if (!isset($n[$k])) { $out[] = ['day' => $k, 'status' => 'removed', 'old_title' => $o[$k]['title'] ?? null, 'new_title' => null]; continue; }
            $a = $o[$k]; $b = $n[$k];
            if (json_encode($a['schedule'] ?? null) !== json_encode($b['schedule'] ?? null) || ($a['title'] ?? '') !== ($b['title'] ?? '')) {
                $out[] = ['day' => $k, 'status' => 'changed', 'old_title' => $a['title'] ?? null, 'new_title' => $b['title'] ?? null];
            }
        }
        return $out;
    }

    public static function isFallbackItinerary(array $it): bool
    {
        return !empty($it['is_fallback']);
    }

    // ── Storage and lifecycle ─────────────────────────────────────────────────────

    public static function latestItineraryId(PDO $dbh, int $bookingId): ?int
    {
        $st = $dbh->prepare('SELECT id FROM tbl_ai_itineraries WHERE booking_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$bookingId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * Record a proposal. Idempotent per (booking, signal set): an identical pending proposal is returned, not duplicated.
     * Pending proposals for a DIFFERENT signal set are superseded. $proposed may be null (alert only: no revised plan could be
     * produced, e.g. no saved itinerary or the AI was unavailable) — the customer still sees the verified notice.
     * @return array{id:int,created:bool}
     */
    public static function createProposal(PDO $dbh, int $bookingId, string $email, array $signals, ?array $proposed, ?string $summary = null): array
    {
        $key = self::signalKey($signals);
        $dbh->beginTransaction();
        try {
            $st = $dbh->prepare("SELECT ProposalId FROM tbl_autopilot_proposals WHERE BookingId=? AND SignalKey=? AND Status='pending' LIMIT 1 FOR UPDATE");
            $st->execute([$bookingId, $key]);
            if (($ex = $st->fetchColumn()) !== false) { $dbh->commit(); return ['id' => (int)$ex, 'created' => false]; }
            // Declined for this exact event already? Don't nag again.
            $st = $dbh->prepare("SELECT ProposalId FROM tbl_autopilot_proposals WHERE BookingId=? AND SignalKey=? AND Status IN ('declined','approved') LIMIT 1");
            $st->execute([$bookingId, $key]);
            if (($ex = $st->fetchColumn()) !== false) { $dbh->commit(); return ['id' => (int)$ex, 'created' => false]; }
            $dbh->prepare("UPDATE tbl_autopilot_proposals SET Status='superseded', DecidedAt=NOW() WHERE BookingId=? AND Status='pending'")->execute([$bookingId]);
            $dbh->prepare("INSERT INTO tbl_autopilot_proposals (BookingId, UserEmail, SignalKey, Signals, BaseItineraryId, ProposedJson, Summary) VALUES (?,?,?,?,?,?,?)")
                ->execute([$bookingId, $email, $key, json_encode($signals, JSON_UNESCAPED_UNICODE), self::latestItineraryId($dbh, $bookingId),
                    $proposed ? json_encode($proposed, JSON_UNESCAPED_UNICODE) : null, $summary !== null ? mb_substr($summary, 0, 500) : null]);
            $id = (int)$dbh->lastInsertId();
            $dbh->commit();
            return ['id' => $id, 'created' => true];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            throw $e;
        }
    }

    /** The customer's current pending proposal for a booking (if still valid), with decoded signals/itinerary. */
    public static function pendingFor(PDO $dbh, int $bookingId, string $email): ?array
    {
        $st = $dbh->prepare("SELECT * FROM tbl_autopilot_proposals WHERE BookingId=? AND UserEmail=? AND Status='pending' ORDER BY ProposalId DESC LIMIT 1");
        $st->execute([$bookingId, $email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decode($r) : null;
    }

    public static function find(PDO $dbh, int $id): ?array
    {
        $st = $dbh->prepare('SELECT * FROM tbl_autopilot_proposals WHERE ProposalId=?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::decode($r) : null;
    }

    private static function decode(array $r): array
    {
        $r['signals'] = json_decode((string)$r['Signals'], true) ?: [];
        $r['proposed'] = $r['ProposedJson'] !== null ? (json_decode((string)$r['ProposedJson'], true) ?: null) : null;
        return $r;
    }

    /** Are the notices behind these signals still published (active, unexpired)? Weather signals are re-checked by the cron/check. */
    public static function noticesStillValid(PDO $dbh, array $signals): bool
    {
        foreach ($signals as $x) {
            if ($x['type'] !== 'notice') continue;
            $st = $dbh->prepare("SELECT 1 FROM tbl_travel_notices WHERE NoticeId=? AND Status='active' AND (ExpiresOn IS NULL OR ExpiresOn >= CURDATE()) AND VerifiedOn=?");
            $st->execute([$x['id'], $x['verified_on']]);
            if (!$st->fetchColumn()) return false;
        }
        return true;
    }

    /**
     * The customer approves. Applies the STORED proposal as a new saved itinerary version.
     * @return array{ok:bool,status:string,message:string}
     */
    public static function approve(PDO $dbh, int $proposalId, string $email): array
    {
        $dbh->beginTransaction();
        try {
            $st = $dbh->prepare('SELECT * FROM tbl_autopilot_proposals WHERE ProposalId=? AND UserEmail=? FOR UPDATE');
            $st->execute([$proposalId, $email]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $dbh->rollBack(); return ['ok' => false, 'status' => 'not_found', 'message' => 'Proposal not found on your account.']; }
            $p = self::decode($row);
            if ($p['Status'] === self::APPROVED) { $dbh->commit(); return ['ok' => true, 'status' => 'already_approved', 'message' => 'Already applied.']; }
            if ($p['Status'] !== self::PENDING) { $dbh->rollBack(); return ['ok' => false, 'status' => $p['Status'], 'message' => 'This proposal is no longer open (' . $p['Status'] . ').']; }
            if (!$p['proposed'] || empty($p['proposed']['days'])) {
                $dbh->rollBack();
                return ['ok' => false, 'status' => 'alert_only', 'message' => 'This alert has no revised plan to apply. You can dismiss it.'];
            }
            // Must still be about a live trip that is still the customer's, not cancelled, and not finished.
            $b = $dbh->prepare('SELECT status, ToDate FROM tblbooking WHERE BookingId=? AND UserEmail=?');
            $b->execute([$p['BookingId'], $email]);
            $bk = $b->fetch(PDO::FETCH_ASSOC);
            if (!$bk || (int)$bk['status'] === 2 || $bk['ToDate'] < date('Y-m-d')) {
                self::mark($dbh, $proposalId, self::EXPIRED);
                $dbh->commit();
                return ['ok' => false, 'status' => self::EXPIRED, 'message' => 'The trip is cancelled or finished, so this proposal was closed.'];
            }
            if (!self::noticesStillValid($dbh, $p['signals'])) {
                self::mark($dbh, $proposalId, self::EXPIRED);
                $dbh->commit();
                return ['ok' => false, 'status' => self::EXPIRED, 'message' => 'The notice behind this proposal is no longer published, so nothing was changed.'];
            }
            if (self::latestItineraryId($dbh, (int)$p['BookingId']) !== ($p['BaseItineraryId'] !== null ? (int)$p['BaseItineraryId'] : null)) {
                self::mark($dbh, $proposalId, self::SUPERSEDED);
                $dbh->commit();
                return ['ok' => false, 'status' => self::SUPERSEDED, 'message' => 'Your itinerary changed after this proposal was made, so it was closed. Run a new check.'];
            }
            $dbh->prepare('INSERT INTO tbl_ai_itineraries (booking_id, user_email, itinerary_json, ai_version, created_at) VALUES (?,?,?,?,NOW())')
                ->execute([(int)$p['BookingId'], $email, json_encode($p['proposed'], JSON_UNESCAPED_UNICODE), 'autopilot-approved']);
            $newId = (int)$dbh->lastInsertId();
            $dbh->prepare("UPDATE tbl_autopilot_proposals SET Status='approved', DecidedAt=NOW(), AppliedItineraryId=? WHERE ProposalId=?")->execute([$newId, $proposalId]);
            $dbh->commit();
            return ['ok' => true, 'status' => self::APPROVED, 'message' => 'Your itinerary was updated. Your booking itself was not changed.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            error_log('[TourBuddy Autopilot] approve: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'error', 'message' => 'Could not apply the proposal right now.'];
        }
    }

    public static function decline(PDO $dbh, int $proposalId, string $email): bool
    {
        $st = $dbh->prepare("UPDATE tbl_autopilot_proposals SET Status='declined', DecidedAt=NOW() WHERE ProposalId=? AND UserEmail=? AND Status='pending'");
        $st->execute([$proposalId, $email]);
        return $st->rowCount() === 1;
    }

    private static function mark(PDO $dbh, int $id, string $status): void
    {
        $dbh->prepare("UPDATE tbl_autopilot_proposals SET Status=?, DecidedAt=NOW() WHERE ProposalId=? AND Status='pending'")->execute([$status, $id]);
    }

    /** Close pending proposals whose notices were withdrawn/expired, or whose trip ended or was cancelled. @return int closed */
    public static function expireStale(PDO $dbh): int
    {
        $n = 0;
        $rows = $dbh->query("SELECT p.ProposalId, p.Signals, b.status, b.ToDate FROM tbl_autopilot_proposals p LEFT JOIN tblbooking b ON b.BookingId=p.BookingId WHERE p.Status='pending'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $gone = $r['status'] === null || (int)$r['status'] === 2 || $r['ToDate'] < date('Y-m-d');
            if ($gone || !self::noticesStillValid($dbh, json_decode($r['Signals'], true) ?: [])) { self::mark($dbh, (int)$r['ProposalId'], self::EXPIRED); $n++; }
        }
        return $n;
    }

    // ── Orchestration (uses the planner; AI only here) ────────────────────────────

    /**
     * Check one booking now. Creates (at most) one pending proposal for the current disruption.
     * $planner is injectable so tests can run without a real model.
     * @param object|null $planner something with regenerate(array,array,string,?int,bool): array and checkWeatherRisk(...)
     * @return array{status:string,proposal_id:?int,signals:int}
     */
    public static function checkBooking(PDO $dbh, array $booking, $planner = null, bool $notify = true): array
    {
        $bid = (int)$booking['BookingId'];
        $email = (string)$booking['UserEmail'];
        $location = (string)($booking['PackageLocation'] ?? '');
        $matched = self::matchNotices(TravelNoticeService::visible($dbh), $location);
        $weather = [];
        if ($planner && method_exists($planner, 'checkWeatherRisk')) {
            try {
                $dur = max(1, (int)((strtotime($booking['ToDate']) - strtotime($booking['FromDate'])) / 86400) + 1);
                $w = $planner->checkWeatherRisk($bid, $location, (string)$booking['FromDate'], $dur);
                $weather = $w['flagged_days'] ?? [];
            } catch (Throwable $e) { error_log('[TourBuddy Autopilot] weather: ' . $e->getMessage()); }
        }
        $signals = self::buildSignals($matched, $weather);
        if (!$signals) {
            try { self::resolveMissing($dbh, $bid, []); } catch (Throwable $e) { /* tracking table not migrated yet: not fatal */ }
            return ['status' => 'no_disruption', 'proposal_id' => null, 'signals' => 0];
        }
        self::recordDisruptions($dbh, $bid, $signals);

        $proposed = null; $summary = null;
        $base = $planner ? $planner->getByBookingId($bid) : null;
        if ($planner && $base && !empty($base['days'])) {
            $tripData = ['destination' => $location, 'duration' => max(1, (int)((strtotime($booking['ToDate']) - strtotime($booking['FromDate'])) / 86400) + 1),
                'travelers' => max(1, (int)($booking['NoOfPersons'] ?? 1)), 'budget' => (int)($booking['TotalCost'] ?? 0), 'start_date' => (string)$booking['FromDate'],
                'travel_type' => 'leisure', 'climate' => 'any', 'accommodation' => 'hotel', 'transport' => 'mixed', 'activities' => ['sightseeing'], 'special_requests' => ''];
            try {
                $cand = $planner->regenerate($tripData, [self::replanInstruction($signals)], $email, $bid, false);
                if (is_array($cand) && !empty($cand['days']) && !self::isFallbackItinerary($cand)) $proposed = $cand;
            } catch (Throwable $e) { error_log('[TourBuddy Autopilot] regenerate: ' . $e->getMessage()); }
        }
        if ($proposed) {
            $changed = count(self::diffDays($base, $proposed));
            $summary = $changed . ' day(s) changed in the suggested plan.';
        } else {
            $summary = $base ? 'A revised plan could not be generated right now; review the notice and adjust your plans.' : 'You have no saved itinerary for this trip, so only the notice is shown.';
        }
        $r = self::createProposal($dbh, $bid, $email, $signals, $proposed, $summary);
        if ($r['created'] && $notify && function_exists('tms_notify')) {
            tms_notify($dbh, $email, 'A verified disruption may affect your trip to ' . $location . '. Review the suggested change; nothing changes unless you approve.', 'my-trip.php?id=' . $bid);
        }
        return ['status' => $proposed ? 'proposal_ready' : 'alert_only', 'proposal_id' => $r['id'], 'signals' => count($signals)];
    }
}
