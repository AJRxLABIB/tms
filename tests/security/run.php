<?php
/**
 * Cross-account / authorization / cookie tests over REAL HTTP against a running copy of the site.
 *   php tests/security/run.php [base-url]        default http://127.0.0.1:8099  (php -S 127.0.0.1:8099 from the project root)
 *   XAMPP: php tests\security\run.php http://localhost/tms
 * Creates two throw-away customers (@idor-test.example.test), one booking, one group trip and one wallet file owned by
 * customer A, then proves customer B and anonymous visitors cannot read or change them. Deletes everything afterwards.
 * Exit code 1 on any failure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 70) . $d . "\n"; }

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
function cleanup(PDO $d): void {
    foreach ([['tblbooking', 'UserEmail'], ['tbltraveldocuments', 'UserEmail'], ['tblgroup_members', 'UserEmail'], ['tblusers', 'EmailId']] as [$tb, $col])
        $d->exec("DELETE FROM $tb WHERE $col LIKE '%@idor-test.example.test'");
    $d->exec("DELETE FROM tblgroup_trips WHERE CreatedBy LIKE '%@idor-test.example.test'");
    $d->exec("DELETE FROM tblexpenses WHERE Description='csrfprobe'");
}
cleanup($dbh);
$A = 'idor-a@idor-test.example.test'; $B = 'idor-b@idor-test.example.test'; $PW = 'Idor-test-pass-1';
$h = password_hash($PW, PASSWORD_BCRYPT);
$ins = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)');
$ins->execute(['Alice IDOR', '01711111111', $A, $h]); $ins->execute(['Bob IDOR', '01722222222', $B, $h]);
$pk = (int)$dbh->query('SELECT PackageId FROM tbltourpackages WHERE Status=1 LIMIT 1')->fetchColumn();
$dbh->prepare("INSERT INTO tblbooking (PackageId,UserEmail,FromDate,ToDate,status,NoOfPersons,TotalCost,PaymentStatus,MobileNumber) VALUES (?,?,?,?,1,1,5000,'unpaid','01999888777')")->execute([$pk, $A, '2031-01-01', '2031-01-03']);
$bid = (int)$dbh->lastInsertId();
$dbh->prepare("INSERT INTO tblgroup_trips (Name, CreatedBy) VALUES ('ALICE-SECRET-GROUP', ?)")->execute([$A]); $gid = (int)$dbh->lastInsertId();
$dbh->prepare("INSERT INTO tblgroup_members (GroupId, UserEmail, Role) VALUES (?,?,'organizer')")->execute([$gid, $A]);
@mkdir(__DIR__ . '/../../uploads/wallet', 0755, true);
$stored = 'sectest_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents(__DIR__ . '/../../uploads/wallet/' . $stored, 'ALICE-SECRET-DOC-CONTENT');
$dbh->prepare("INSERT INTO tbltraveldocuments (UserEmail,DocType,OriginalFilename,StoredFilename,MimeType,FileSize,Encrypted) VALUES (?, 'other','a.txt',?,'text/plain',24,0)")->execute([$A, $stored]);
$did = (int)$dbh->lastInsertId();

$a = login($base, $A, $PW); $b = login($base, $B, $PW); $anon = new Client();
t('A is signed in (profile opens)', str_contains($a->req("$base/profile.php")['body'], 'Alice IDOR'));
t('B is signed in (profile opens)', str_contains($b->req("$base/profile.php")['body'], 'Bob IDOR'));
t('anonymous is redirected away from profile', in_array($anon->req("$base/profile.php", null, false)['code'], [301, 302, 303], true));

// Session cookie flags (the bug where pages started the session before config.php ran)
$h = (new Client())->req("$base/index.php")["headers"];
t('session cookie is HttpOnly', (bool)preg_match('/Set-Cookie: PHPSESSID=[^;]+;.*HttpOnly/i', $h), '');
t('session cookie has SameSite', (bool)preg_match('/Set-Cookie: PHPSESSID=[^;]+;.*SameSite=(Lax|Strict)/i', $h));
t('server refuses a session ID it did not issue (strict mode)', (function () use ($base) {
    // The cookie must be scoped to the host actually under test (it was hard-coded to 127.0.0.1, so against
    // http://localhost/tms the forged cookie was never sent and the check could not pass).
    $host = (string)parse_url($base, PHP_URL_HOST);
    $c = new Client(); file_put_contents($c->jar, "#HttpOnly_{$host}\tFALSE\t/\tFALSE\t0\tPHPSESSID\tattackerchosenid1234567890\n");
    $r = $c->req($base . '/index.php'); return !str_contains(file_get_contents($c->jar), 'attackerchosenid1234567890'); })());

foreach ([['my-trip', "/my-trip.php?id=$bid", '2031-01-01', '2031'], ['ticket', "/ticket.php?bkid=$bid", '01999888777', '01999888777']] as [$n, $u, $own, $mark]) {
    t("$n: owner sees own booking", str_contains($a->req($base . $u)['body'], $mark));
    t("$n: other customer does NOT", !str_contains($b->req($base . $u)['body'], $mark));
    t("$n: anonymous does NOT", !str_contains($anon->req($base . $u)['body'], $mark));
}
$r = $a->req("$base/wallet-document.php?id=$did");  t('wallet doc: owner can download', str_contains($r['body'], 'ALICE-SECRET-DOC-CONTENT'));
$r = $b->req("$base/wallet-document.php?id=$did");  t('wallet doc: other customer gets 404 and no content', $r['code'] === 404 && !str_contains($r['body'], 'ALICE-SECRET'));
$r = $anon->req("$base/wallet-document.php?id=$did"); t('wallet doc: anonymous gets no content', !str_contains($r['body'], 'ALICE-SECRET'));
t('group trip: member sees it', str_contains($a->req("$base/group-trip.php?id=$gid")['body'], 'ALICE-SECRET-GROUP'));
t('group trip: non-member does NOT', !str_contains($b->req("$base/group-trip.php?id=$gid")['body'], 'ALICE-SECRET-GROUP'));
t('group trip: anonymous does NOT', !str_contains($anon->req("$base/group-trip.php?id=$gid")['body'], 'ALICE-SECRET-GROUP'));
$b->req("$base/group-trip.php", ['csrf_token' => $b->token("$base/cart.php"), 'group_id' => $gid, 'action' => 'add_member', 'member_email' => $B]);
t('group trip: non-member cannot add themselves', (int)$dbh->query("SELECT COUNT(*) FROM tblgroup_members WHERE GroupId=$gid AND UserEmail='$B'")->fetchColumn() === 0);
$r = $b->req("$base/pay-now.php", ['csrf_token' => $b->token("$base/cart.php"), 'booking_id' => $bid]);
t("pay-now: B cannot start payment for A's booking", !str_contains($r['body'], '5,000') && (int)$dbh->query("SELECT COUNT(*) FROM tblpayments WHERE BookingId=$bid")->fetchColumn() === 0);
$r = $b->req("$base/expense-tracker.php", ['add_expense' => '1', 'amount' => '100', 'category' => 'food', 'description' => 'csrfprobe']);
t('CSRF: state-changing POST without a token is refused', $r['code'] === 403 && (int)$dbh->query("SELECT COUNT(*) FROM tblexpenses WHERE Description='csrfprobe'")->fetchColumn() === 0, 'HTTP ' . $r['code']);

$bad = [];
foreach (glob(__DIR__ . '/../../admin/*.php') as $f) {
    $n = basename($f); if (in_array($n, ['index.php', 'logout.php'], true)) continue;
    $r = $anon->req("$base/admin/$n", null, false);   if ($r['code'] === 200 && strlen($r['body']) > 300) $bad[] = "anon:$n";
    $r = $a->req("$base/admin/$n", null, false);      if ($r['code'] === 200 && strlen($r['body']) > 300) $bad[] = "customer:$n";
}
t('admin: every page refuses anonymous visitors AND customer sessions', !$bad, implode(',', array_slice($bad, 0, 6)));
$bad = [];
foreach (['dashboard', 'packages', 'blackouts', 'bookings', 'settlements'] as $n) {
    if ($anon->req("$base/supplier/$n.php", null, false)['code'] === 200) $bad[] = "anon:$n";
    if ($a->req("$base/supplier/$n.php", null, false)['code'] === 200) $bad[] = "customer:$n";
}
t('supplier portal: refuses anonymous visitors and customer sessions', !$bad, implode(',', $bad));
foreach (['redirect=https://evil.example', 'next=//evil.example', 'return=https://evil.example'] as $q) {
    $r = $anon->req("$base/login.php?$q", null, false);
    t("no open redirect: $q", !str_contains($r['loc'], 'evil.example'));
}

@unlink(__DIR__ . '/../../uploads/wallet/' . $stored);
cleanup($dbh);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
