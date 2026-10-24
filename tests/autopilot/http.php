<?php
/** Trip Autopilot over REAL HTTP: card renders, API gating (guest/CSRF/other account), approve/decline end to end.
 *  php tests/autopilot/http.php [base-url]   (AI is not called: proposals are inserted directly, so the model is not tested here) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/TripAutopilot.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 72) . $d . "\n"; }
final class Client {
    public string $jar;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'tmssec_'); }
    public function __destruct() { @unlink($this->jar); }
    /** @return array{code:int,body:string,loc:string,headers:string} */
    public function req(string $url, ?array $post = null, bool $follow = true): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $headers = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => $m[1] ?? '', 'headers' => $headers];
    }
    public function token(string $url): string { return preg_match('/name="csrf_token" value="([^"]+)"/', $this->req($url)['body'], $m) ? $m[1] : ''; }
}
function login(string $base, string $email, string $pw): Client {
    $c = new Client(); $c->req($base . '/login.php', ['EmailId' => $email, 'Password' => $pw, 'csrf_token' => $c->token($base . '/login.php')]); return $c;
}

$A = 'ap-http-a@idor-test.example.test'; $B = 'ap-http-b@idor-test.example.test'; $PW = 'Idor-test-pass-1';
$clean = function () use ($dbh) {
    foreach (['tbl_autopilot_proposals' => 'UserEmail', 'tbl_ai_itineraries' => 'user_email', 'tbl_notifications' => 'UserEmail', 'tblbooking' => 'UserEmail', 'tblusers' => 'EmailId'] as $tb => $c) $dbh->exec("DELETE FROM $tb WHERE $c LIKE 'ap-http-%@idor-test.example.test'");
    $dbh->exec("DELETE FROM tbl_travel_notices WHERE Country='ZZ-Http'");
};
$clean();
$h = password_hash($PW, PASSWORD_BCRYPT);
$ins = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)');
$ins->execute(['Alice AP', '01711111111', $A, $h]); $ins->execute(['Bob AP', '01722222222', $B, $h]);
$pk = (int)$dbh->query('SELECT PackageId FROM tbltourpackages WHERE Status=1 LIMIT 1')->fetchColumn();
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus) VALUES (?,?,?,?,1,1,5000,'paid')")->execute([$pk, $A, date('Y-m-d', strtotime('+10 days')), date('Y-m-d', strtotime('+13 days'))]);
$bid = (int)$dbh->lastInsertId();
$dbh->prepare("INSERT INTO tbl_ai_itineraries (booking_id,user_email,itinerary_json,ai_version) VALUES (?,?,?,'t')")->execute([$bid, $A, json_encode(['days' => [['day' => 1, 'title' => 'Original beach day', 'schedule' => [['activity' => 'Beach']]]]])]);
$a = login($base, $A, $PW); $b = login($base, $B, $PW); $anon = new Client();
$page = "$base/my-trip.php?id=$bid";
$body = $a->req($page)['body'];
t('owner sees Trip Autopilot card with the check button', str_contains($body, 'Trip Autopilot') && str_contains($body, 'mtApCheck'));
t('clean stored plan shows a Plan check note with the honest "does not guarantee" wording', str_contains($body, 'Plan check:') && str_contains($body, 'does not guarantee'));
$flawed = json_encode(['days' => [['day' => 1, 'title' => 'Flawed', 'schedule' => [
    ['time' => '09:00', 'duration' => '3 hours', 'activity' => '<img src=x onerror=alert(1)> Tour A', 'location' => 'A', 'type' => 'sightseeing'],
    ['time' => '10:00', 'duration' => '2 hours', 'activity' => 'Tour B', 'location' => 'B', 'type' => 'sightseeing']]]]]);
$dbh->prepare('UPDATE tbl_ai_itineraries SET itinerary_json=? WHERE booking_id=?')->execute([$flawed, $bid]);
$fb = $a->req($page)['body'];
t('overlapping plan: the page lists the contradiction with exact numbers', str_contains($fb, 'thing to look at') && str_contains($fb, 'overlaps the next item at 10:00 by 120 min'));
t('plan text inside the check is HTML-escaped (no stored XSS)', !str_contains($fb, '<img src=x onerror') && str_contains($fb, '&lt;img src=x onerror'));
$dbh->prepare('UPDATE tbl_ai_itineraries SET itinerary_json=? WHERE booking_id=?')->execute([json_encode(['days' => [['day' => 1, 'title' => 'Original beach day', 'schedule' => [['activity' => 'Beach']]]]]), $bid]);
t('no proposal yet -> no approve button', !str_contains($body, 'id="mtApApprove"'));
$tokA = $a->token($page); $tokB = $b->token("$base/cart.php");
$post = fn(Client $c, string $act, array $p) => json_decode($c->req("$base/api/ai.php?action=$act", $p)['body'], true);
$r = $anon->req("$base/api/ai.php?action=autopilot_check", ['booking_id' => $bid]);
t('guest cannot run a check (401)', $r['code'] === 401);
$r = $a->req("$base/api/ai.php?action=autopilot_check", ['booking_id' => $bid]);
t('check without CSRF token is rejected (403)', $r['code'] === 403);
$r = $post($b, 'autopilot_check', ['booking_id' => $bid, 'csrf_token' => $tokB]);
t("another customer cannot check A's booking", ($r['success'] ?? true) === false);
$r = $post($a, 'autopilot_check', ['booking_id' => $bid, 'csrf_token' => $tokA]);
t('check with no verified notice -> no_disruption', ($r['data']['status'] ?? '') === 'no_disruption');
$r = $post($a, 'autopilot_check', ['booking_id' => $bid, 'csrf_token' => $tokA]);
t('immediate second check is rate limited (429 message)', ($r['success'] ?? true) === false);

// admin publishes a verified disruption; proposal inserted directly (stand-in for the AI step)
$dbh->prepare("UPDATE tblbooking SET PackageId=PackageId WHERE BookingId=?")->execute([$bid]);
$loc = (string)$dbh->query("SELECT PackageLocation FROM tbltourpackages WHERE PackageId=$pk")->fetchColumn();
$dbh->prepare("INSERT INTO tbl_travel_notices (Kind,Country,Title,Body,SourceName,SourceUrl,VerifiedOn,ExpiresOn) VALUES ('disruption','ZZ-Http','<b>Rail strike</b>','Trains <script>alert(1)</script> halted','Ministry','https://example.gov/x',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 5 DAY))")->execute();
$nid = (int)$dbh->lastInsertId();
$sig = TripAutopilot::buildSignals([TravelNoticeService::find($dbh, $nid)]);
$prop = TripAutopilot::createProposal($dbh, $bid, $A, $sig, ['days' => [['day' => 1, 'title' => 'Indoor museum day', 'schedule' => [['activity' => 'Museum']]]]], '1 day(s) changed')['id'];
$body = $a->req($page)['body'];
t('owner sees the proposal, source link and approve button', str_contains($body, 'id="mtApApprove"') && str_contains($body, 'https://example.gov/x') && str_contains($body, 'Indoor museum day'));
t('the suggested plan is checked too', str_contains($body, 'Check of the suggested plan'));
t('notice text is HTML-escaped (no stored XSS)', !str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&lt;b&gt;Rail strike'));
t('page says nothing changes unless approved', str_contains($body, 'never changed here') || str_contains($body, 'Nothing has been changed'));
t("other customer's my-trip page does not show it", !str_contains($b->req($page)['body'], 'Indoor museum day'));
$r = $a->req("$base/api/ai.php?action=autopilot_approve", ['proposal_id' => $prop]);
t('approve without CSRF token rejected (403)', $r['code'] === 403);
$r = $post($b, 'autopilot_approve', ['proposal_id' => $prop, 'csrf_token' => $tokB]);
t("another customer cannot approve A's proposal", ($r['success'] ?? true) === false);
t('nothing saved after those refusals', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn() === 1);
$r = $post($b, 'autopilot_decline', ['proposal_id' => $prop, 'csrf_token' => $tokB]);
t("another customer cannot decline A's proposal", ($r['success'] ?? true) === false && TripAutopilot::find($dbh, $prop)['Status'] === 'pending');
$r = $post($a, 'autopilot_approve', ['proposal_id' => $prop, 'csrf_token' => $tokA]);
t('owner approves over HTTP', ($r['success'] ?? false) === true);
$body = $a->req($page)['body'];
t('page now shows the new itinerary day', str_contains($body, 'Indoor museum day') && !str_contains($body, 'id="mtApApprove"'));
t('booking row unchanged by approval', (int)$dbh->query("SELECT TotalCost FROM tblbooking WHERE BookingId=$bid")->fetchColumn() === 5000 && $dbh->query("SELECT PaymentStatus FROM tblbooking WHERE BookingId=$bid")->fetchColumn() === 'paid');
$r = $post($a, 'autopilot_approve', ['proposal_id' => $prop, 'csrf_token' => $tokA]);
t('double-click approve does not create another version', ($r['success'] ?? false) === true && (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_itineraries WHERE booking_id=$bid")->fetchColumn() === 2);

$clean();
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
