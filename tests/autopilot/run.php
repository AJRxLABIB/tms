<?php
/** Trip Autopilot tests against the real DB (tagged test rows, removed afterwards). php tests/autopilot/run.php
 *  The planner is replaced by a stub so no AI model is called; the AI output quality itself is NOT tested here. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/TravelNoticeService.php';
require_once 'backend/services/TripAutopilot.php';
TravelNoticeService::migrate($dbh); TripAutopilot::migrate($dbh);
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 72) . $d . "\n"; }
$d = fn(int $o) => date('Y-m-d', strtotime("$o days"));

// ---- pure functions
$n = fn($id, $c, $kind = 'disruption', $exp = false) => ['NoticeId' => $id, 'Kind' => $kind, 'Country' => $c, 'Title' => 'T' . $id, 'Body' => 'B', 'SourceName' => 'S', 'SourceUrl' => 'https://x.example', 'VerifiedOn' => '2026-10-01', 'ExpiresOn' => null, 'expired' => $exp];
$m = TripAutopilot::matchNotices([$n(1, 'Switzerland'), $n(2, 'Bhutan'), $n(3, 'Switzerland', 'entry'), $n(4, 'Switzerland', 'disruption', true), $n(5, 'India')], 'Paris and Switzerland');
t('matches by country word in package location', count($m) === 1 && $m[0]['NoticeId'] === 1);
t('entry and sustainability notices are not disruptions', TripAutopilot::matchNotices([$n(3, 'Bhutan', 'entry')], 'Bhutan') === []);
t('country must be a whole word (Oman is not inside Romance)', TripAutopilot::matchNotices([$n(6, 'Oman')], 'Romance Tour') === []);
t('case-insensitive match', count(TripAutopilot::matchNotices([$n(7, 'bhutan')], 'BHUTAN')) === 1);
$sig = TripAutopilot::buildSignals([$n(2, 'Bhutan')], [['day' => 2, 'date' => '2026-11-02', 'rain_chance' => 80]]);
t('signals carry source and checked date', $sig[0]['source_url'] === 'https://x.example' && $sig[0]['verified_on'] === '2026-10-01');
t('signal key is stable regardless of order', TripAutopilot::signalKey($sig) === TripAutopilot::signalKey(array_reverse($sig)));
$sig2 = $sig; $sig2[0]['verified_on'] = '2026-10-05';
t('re-verified notice produces a new key', TripAutopilot::signalKey($sig) !== TripAutopilot::signalKey($sig2));
$old = ['days' => [['day' => 1, 'title' => 'A', 'schedule' => [['activity' => 'x']]], ['day' => 2, 'title' => 'B', 'schedule' => [['activity' => 'y']]]]];
$new = ['days' => [['day' => 1, 'title' => 'A', 'schedule' => [['activity' => 'x']]], ['day' => 2, 'title' => 'B2', 'schedule' => [['activity' => 'z']]], ['day' => 3, 'title' => 'C', 'schedule' => []]]];
$df = TripAutopilot::diffDays($old, $new);
t('diff reports only changed and added days', count($df) === 2 && $df[0]['status'] === 'changed' && $df[1]['status'] === 'added');
t('diff against no itinerary lists everything as added', count(TripAutopilot::diffDays(null, $new)) === 3);
t('instruction quotes the notice and forbids invention', strpos(TripAutopilot::replanInstruction($sig), 'Do not invent') !== false && strpos(TripAutopilot::replanInstruction($sig), 'Bhutan') !== false);

// ---- fixtures
$email = 'ap-test@tms-testing.local'; $other = 'ap-other@tms-testing.local';
$cleanup = function () use ($dbh, $email, $other) {
    $dbh->exec("DELETE FROM tbl_autopilot_proposals WHERE UserEmail IN ('$email','$other')");
    $dbh->exec("DELETE FROM tbl_ai_itineraries WHERE user_email IN ('$email','$other')");
    $dbh->exec("DELETE FROM tbl_notifications WHERE UserEmail IN ('$email','$other')");
    $dbh->exec("DELETE FROM tblbooking WHERE UserEmail IN ('$email','$other')");
    $dbh->exec("DELETE FROM tblusers WHERE EmailId IN ('$email','$other')");
    $dbh->exec("DELETE FROM tbl_travel_notices WHERE Country='ZZ-Autopilot'");
};
$cleanup();
foreach ([$email, $other] as $e) $dbh->prepare("INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES ('AP Test','01700000000',?, 'x')")->execute([$e]);
$pkg = (int)$dbh->query('SELECT PackageId FROM tbltourpackages ORDER BY PackageId LIMIT 1')->fetchColumn();
$mk = function (string $em, string $from, string $to, int $status = 1) use ($dbh, $pkg) {
    $dbh->prepare("INSERT INTO tblbooking (PackageId, UserEmail, FromDate, ToDate, status, NoOfPersons, TotalCost, PaymentStatus) VALUES (?,?,?,?,?,2,50000,'paid')")->execute([$pkg, $em, $from, $to, $status]);
    return (int)$dbh->lastInsertId();
};
$bid = $mk($email, $d(10), $d(14));
$bookingRow = fn(int $id) => $dbh->query("SELECT b.*, 'Visit ZZ-Autopilot and Paris' AS PackageLocation FROM tblbooking b WHERE BookingId=$id")->fetch(PDO::FETCH_ASSOC);
$saveIt = function (int $b, string $em, string $title) use ($dbh) { $dbh->prepare("INSERT INTO tbl_ai_itineraries (booking_id, user_email, itinerary_json, ai_version) VALUES (?,?,?, 't')")->execute([$b, $em, json_encode(['days' => [['day' => 1, 'title' => $title, 'schedule' => [['activity' => $title]]]]])]); };
$saveIt($bid, $email, 'Original plan');
$mkNotice = function (string $kind = 'disruption') use ($dbh, $d) {
    return TravelNoticeService::save($dbh, null, ['kind' => $kind, 'country' => 'ZZ-Autopilot', 'title' => 'Transport strike', 'body' => 'Buses may not run.', 'source_name' => 'Ministry', 'source_url' => 'https://example.gov/strike', 'verified_on' => $d(0), 'expires_on' => $d(10)], 'tester')['id'];
};
$stub = new class {
    public bool $fallback = false; public bool $throw = false; public int $calls = 0;
    public function getByBookingId(int $id): ?array { global $dbh; $s = $dbh->prepare('SELECT itinerary_json FROM tbl_ai_itineraries WHERE booking_id=? ORDER BY id DESC LIMIT 1'); $s->execute([$id]); $j = $s->fetchColumn(); return $j ? json_decode($j, true) : null; }
    public function regenerate(array $t, array $f, string $e, ?int $b, bool $save): array {
        $this->calls++; if ($this->throw) throw new RuntimeException('ai down');
        if ($save) throw new LogicException('autopilot must never save while proposing');
        if ($this->fallback) return ['is_fallback' => true, 'days' => [['day' => 1, 'title' => 'Template', 'schedule' => []]]];
        return ['days' => [['day' => 1, 'title' => 'Indoor museum day (strike-safe)', 'schedule' => [['activity' => 'Museum']]]]];
    }
    public function checkWeatherRisk(int $b, string $d, string $s, int $n): array { return ['flagged_days' => []]; }
};

// ---- detection + proposal
$r = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false);
t('no notice -> no disruption, nothing stored', $r['status'] === 'no_disruption' && (int)$dbh->query("SELECT COUNT(*) FROM tbl_autopilot_proposals WHERE BookingId=$bid")->fetchColumn() === 0);
$mkNotice('entry');
t('entry-rule notice alone does not create a proposal', TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['status'] === 'no_disruption');
$noticeId = $mkNotice('disruption');
$r = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, true);
t('verified disruption -> proposal_ready', $r['status'] === 'proposal_ready' && $r['proposal_id'] > 0);
$pid = $r['proposal_id'];
t('customer was notified', (int)$dbh->query("SELECT COUNT(*) FROM tbl_notifications WHERE UserEmail='$email'")->fetchColumn() === 1);
t('proposing did NOT change the saved itinerary', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn() === 1);
$r2 = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, true);
t('checking again for the same event does not duplicate or re-notify', $r2['proposal_id'] === $pid && (int)$dbh->query("SELECT COUNT(*) FROM tbl_autopilot_proposals WHERE BookingId=$bid")->fetchColumn() === 1 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_notifications WHERE UserEmail='$email'")->fetchColumn() === 1);
$p = TripAutopilot::pendingFor($dbh, $bid, $email);
t('pending proposal carries signals and base itinerary id', $p && $p['signals'][0]['id'] === $noticeId && (int)$p['BaseItineraryId'] === TripAutopilot::latestItineraryId($dbh, $bid));
t('another account cannot see it', TripAutopilot::pendingFor($dbh, $bid, $other) === null);

// ---- approval rules
$bk0 = $dbh->query("SELECT FromDate,ToDate,TotalCost,status,PaymentStatus FROM tblbooking WHERE BookingId=$bid")->fetch(PDO::FETCH_ASSOC);
$x = TripAutopilot::approve($dbh, $pid, $other);
t('another account cannot approve', !$x['ok'] && $x['status'] === 'not_found');
$x = TripAutopilot::approve($dbh, $pid, $email);
t('owner approves -> applied', $x['ok'] && $x['status'] === 'approved');
$latest = $dbh->query("SELECT itinerary_json, ai_version FROM tbl_ai_itineraries WHERE booking_id=$bid ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
t('new itinerary version is exactly the stored proposal', strpos($latest['itinerary_json'], 'Indoor museum day') !== false && $latest['ai_version'] === 'autopilot-approved');
t('old itinerary kept as history', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn() === 2);
t('booking dates, price, status, payment untouched', $dbh->query("SELECT FromDate,ToDate,TotalCost,status,PaymentStatus FROM tblbooking WHERE BookingId=$bid")->fetch(PDO::FETCH_ASSOC) === $bk0);
$x = TripAutopilot::approve($dbh, $pid, $email);
t('approving twice is idempotent (no third version)', $x['ok'] && $x['status'] === 'already_approved' && (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn() === 2);
t('same event is not proposed again after approval', TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'] === $pid && (int)$dbh->query("SELECT COUNT(*) FROM tbl_autopilot_proposals WHERE BookingId=$bid")->fetchColumn() === 1);

// decline path
$dbh->exec("UPDATE tbl_autopilot_proposals SET Status='superseded' WHERE BookingId=$bid"); // simulate a fresh event: new verification date
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(-1), $noticeId]);
$r = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false);
$pid2 = $r['proposal_id'];
t('re-verified notice is a new event -> new proposal', $r['status'] === 'proposal_ready' && $pid2 !== $pid);
t('decline works once', TripAutopilot::decline($dbh, $pid2, $email) && !TripAutopilot::decline($dbh, $pid2, $email));
$cnt = (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn();
t('declining changed nothing', $cnt === 2);
t('declined event is not re-proposed (no nagging)', TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'] === $pid2);
t('declined proposal cannot be approved', !TripAutopilot::approve($dbh, $pid2, $email)['ok']);

// superseded when the itinerary changed meanwhile
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(0), $noticeId]);
$pid3 = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'];
$saveIt($bid, $email, 'Customer edited plan');
$x = TripAutopilot::approve($dbh, $pid3, $email);
t('itinerary changed after proposal -> refused as superseded', !$x['ok'] && $x['status'] === 'superseded');

// notice withdrawn
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(-2), $noticeId]);
$pid4 = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'];
TravelNoticeService::setStatus($dbh, $noticeId, 'archived');
$x = TripAutopilot::approve($dbh, $pid4, $email);
t('notice archived -> approval refused as expired, nothing saved', !$x['ok'] && $x['status'] === 'expired');
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(-4), $noticeId]);
TravelNoticeService::setStatus($dbh, $noticeId, 'active');
$pidE = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'];
TravelNoticeService::setStatus($dbh, $noticeId, 'archived');
$closed = TripAutopilot::expireStale($dbh);
t('expireStale closes a pending proposal whose notice was withdrawn', $closed >= 1 && TripAutopilot::find($dbh, $pidE)['Status'] === 'expired');

// cancelled / finished trips
TravelNoticeService::setStatus($dbh, $noticeId, 'active');
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(-3), $noticeId]);
$pid5 = TripAutopilot::checkBooking($dbh, $bookingRow($bid), $stub, false)['proposal_id'];
$dbh->exec("UPDATE tblbooking SET status=2 WHERE BookingId=$bid");
$x = TripAutopilot::approve($dbh, $pid5, $email);
t('cancelled trip -> approval refused', !$x['ok'] && $x['status'] === 'expired');
$dbh->exec("UPDATE tblbooking SET status=1 WHERE BookingId=$bid");

// failure modes of the planner
$bid2 = $mk($email, $d(5), $d(8)); $saveIt($bid2, $email, 'Plan 2');
$stub->fallback = true;
$r = TripAutopilot::checkBooking($dbh, $bookingRow($bid2), $stub, false);
t('AI fallback template is never offered as a revised plan', $r['status'] === 'alert_only');
$p = TripAutopilot::pendingFor($dbh, $bid2, $email);
t('alert-only proposal keeps the verified notice', $p && $p['proposed'] === null && count($p['signals']) === 1);
t('alert-only proposal cannot be "approved"', TripAutopilot::approve($dbh, $p['ProposalId'], $email)['status'] === 'alert_only');
$bid3 = $mk($email, $d(5), $d(8)); $stub->fallback = false; $stub->throw = true;
t('AI exception degrades to alert-only, no crash', TripAutopilot::checkBooking($dbh, $bookingRow($bid3), $stub, false)['status'] === 'alert_only');
$bid4 = $mk($email, $d(5), $d(8)); $stub->throw = false; $before = $stub->calls;
t('no saved itinerary -> alert only, no AI call', TripAutopilot::checkBooking($dbh, $bookingRow($bid4), $stub, false)['status'] === 'alert_only' && $stub->calls === $before);
t('no planner at all (--no-ai) -> alert only', TripAutopilot::checkBooking($dbh, $bookingRow($bid4), null, false)['status'] === 'alert_only');

$cleanup();
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
