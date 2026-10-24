<?php
/**
 * Login-first and ownership tests for the tourist features, over REAL HTTP against a running copy of the site.
 *   php tests/security/tourist_access_test.php http://localhost/tourbuddy
 * Creates two throw-away customers (@tourist-test.example.test), one personal trip and one saved place owned by customer A,
 * proves signed-out visitors and customer B cannot reach them, and deletes everything afterwards. Exit code 1 on any failure.
 * (Not run by the author: it needs MySQL and Apache. Run it on XAMPP.)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
$base = rtrim($argv[1] ?? 'http://localhost/tourbuddy', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 78) . $d . "\n"; }

final class TClient {
    public string $jar;
    public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'tmstourist_'); }
    public function __destruct() { @unlink($this->jar); }
    /** @return array{code:int,body:string,loc:string} */
    public function req(string $url, ?array $post = null, bool $follow = true, array $headers = []): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = (string)curl_exec($ch);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
        return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => $m[1] ?? ''];
    }
    public function token(string $url): string { return preg_match('/name="csrf_token" value="([^"]+)"/', $this->req($url)['body'], $m) ? $m[1] : ''; }
    public function json(string $url, ?array $post = null): array { $r = $this->req($url, $post); $j = json_decode($r['body'], true); return ['code' => $r['code'], 'j' => is_array($j) ? $j : []]; }
}
function tlogin(string $base, string $email, string $pw): TClient {
    $c = new TClient(); $c->req($base . '/login.php', ['EmailId' => $email, 'Password' => $pw, 'csrf_token' => $c->token($base . '/login.php')]); return $c;
}
function tcleanup(PDO $d): void {
    try { $d->exec("DELETE FROM tbl_saved_places WHERE user_email LIKE '%@tourist-test.example.test'"); } catch (Throwable $e) {}
    try { $d->exec("DELETE FROM tbl_map_itineraries WHERE user_email LIKE '%@tourist-test.example.test'"); } catch (Throwable $e) {}
    $d->exec("DELETE FROM tblusers WHERE EmailId LIKE '%@tourist-test.example.test'");
}
tcleanup($dbh);
$A = 'tourist-a@tourist-test.example.test'; $B = 'tourist-b@tourist-test.example.test'; $PW = 'Tourist-test-pass-1';
$h = password_hash($PW, PASSWORD_BCRYPT);
$ins = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)');
$ins->execute(['Tourist A', '01711110001', $A, $h]); $ins->execute(['Tourist B', '01711110002', $B, $h]);

$anon = new TClient();
// 1. public pages stay public
foreach (['index.php', 'login.php', 'register.php', 'package-list.php', 'hotels.php'] as $p) {
    t("public page opens while signed out: $p", $anon->req("$base/$p", null, false)['code'] === 200);
}
// 2. protected pages send a signed-out visitor to the login page (server-side, no JavaScript)
foreach (['explore.php', 'explore-view.php?name=Cox&lat=21.4&lng=92.0', 'tour-map.php', 'weather.php', 'my-trips.php', 'saved-places.php', 'trip-planner.php?id=1', 'ai-planner.php'] as $p) {
    $r = (new TClient())->req("$base/$p", null, false);
    t("protected page redirects to login: $p", in_array($r['code'], [301, 302, 303], true) && str_contains($r['loc'], 'login.php'), 'HTTP ' . $r['code'] . ' ' . $r['loc']);
}
// 3. the intended page is remembered and opened after sign-in (and only same-site targets are honoured)
$c = new TClient();
$c->req("$base/explore.php?q=Cox", null, false);                        // sets the remembered target in the session
$login = $c->req("$base/login.php", ['EmailId' => $A, 'Password' => $PW, 'csrf_token' => $c->token("$base/login.php")], false);
t('after sign-in the user returns to the page they asked for', str_contains($login['loc'], 'explore.php'), $login['loc']);
foreach (['https://evil.example/x', '//evil.example', 'javascript:alert(1)'] as $bad) {
    $c2 = new TClient(); $c2->req("$base/login.php?redirect=" . rawurlencode($bad), null, false);
    $r = $c2->req("$base/login.php", ['EmailId' => $A, 'Password' => $PW, 'csrf_token' => $c2->token("$base/login.php")], false);
    t("redirect parameter cannot leave the site: $bad", !str_contains($r['loc'], 'evil.example') && !str_contains($r['loc'], 'javascript'), $r['loc']);
}
// 4. APIs
$r = $anon->json("$base/api/map.php?action=search&q=cox");              t('map API: search needs sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/map.php?action=nearby&lat=21.4&lng=92&radius=1000"); t('map API: nearby needs sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/map.php?action=route"); t('map API: route needs sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/map.php?action=saved_list"); t('map API: saved places need sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/map.php?action=itinerary_list"); t('map API: trips need sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/map.php?action=config"); t('map API: config stays open for basemap previews', $r['code'] === 200 && !empty($r['j']['ok']));
$r = $anon->json("$base/api/explore.php?action=search&q=cox"); t('explore API: search needs sign-in (401)', $r['code'] === 401);
$r = $anon->json("$base/api/explore.php?action=weather&lat=21.4&lng=92"); t('explore API: weather needs sign-in (401)', $r['code'] === 401);

// 5. ownership: A creates a trip and a saved place, B and anonymous cannot read or change them
$a = tlogin($base, $A, $PW); $b = tlogin($base, $B, $PW);
$cfgA = $a->json("$base/api/map.php?action=config"); $tokA = (string)($cfgA['j']['csrf'] ?? '');
$cfgB = $b->json("$base/api/map.php?action=config"); $tokB = (string)($cfgB['j']['csrf'] ?? '');
t('A can read the map config with a CSRF token', $tokA !== '');
$tripId = 0; $saved = null;
if ($tokA !== '') {
    $r = $a->json("$base/api/map.php?action=itinerary_create", ['csrf_token' => $tokA, 'title' => 'ALICE-PRIVATE-TRIP', 'destination_name' => 'Cox']);
    $tripId = (int)($r['j']['id'] ?? 0);
    t('A can create a personal trip (no booking or payment involved)', $tripId > 0, 'HTTP ' . $r['code']);
    $s = $a->json("$base/api/map.php?action=saved_save", ['csrf_token' => $tokA, 'place_key' => 'dest:tourist-test', 'name' => 'ALICE-SAVED', 'lat' => '21.4', 'lng' => '92.0', 'category' => 'beach', 'kind' => 'destination']);
    t('A can save a place', !empty($s['j']['ok']), 'HTTP ' . $s['code']);
    $s2 = $a->json("$base/api/map.php?action=saved_save", ['csrf_token' => $tokA, 'place_key' => 'dest:tourist-test', 'name' => 'ALICE-SAVED', 'lat' => '21.4', 'lng' => '92.0', 'category' => 'beach', 'kind' => 'destination']);
    t('saving the same place twice does not create a duplicate', (int)$dbh->query("SELECT COUNT(*) FROM tbl_saved_places WHERE user_email='$A'")->fetchColumn() === 1);
}
if ($tripId > 0) {
    $r = $b->json("$base/api/map.php?action=itinerary_get&id=$tripId");   t("B cannot read A's trip", $r['code'] === 404 && !str_contains(json_encode($r['j']), 'ALICE-PRIVATE'));
    $r = $anon->json("$base/api/map.php?action=itinerary_get&id=$tripId"); t("signed-out visitor cannot read A's trip", $r['code'] === 401);
    $r = $b->json("$base/api/map.php?action=itinerary_delete", ['csrf_token' => $tokB, 'id' => $tripId]);
    t("B cannot delete A's trip", $r['code'] === 404 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_map_itineraries WHERE id=$tripId")->fetchColumn() === 1);
    $r = $b->json("$base/api/map.php?action=itinerary_duplicate_trip", ['csrf_token' => $tokB, 'id' => $tripId]);
    t("B cannot duplicate A's trip", $r['code'] === 404);
    $page = $b->req("$base/trip-planner.php?id=$tripId");
    t("B opening A's trip page sees no private content", !str_contains($page['body'], 'ALICE-PRIVATE-TRIP'));
    $page = $a->req("$base/trip-planner.php?id=$tripId");
    t('A opening the trip page sees it', str_contains($page['body'], 'ALICE-PRIVATE-TRIP'));
}
$r = $b->json("$base/api/map.php?action=saved_list"); t("B's saved list does not contain A's place", !str_contains(json_encode($r['j']), 'ALICE-SAVED'));
$r = $b->json("$base/api/map.php?action=saved_remove", ['csrf_token' => $tokB, 'place_key' => 'dest:tourist-test']);
t("B cannot remove A's saved place", (int)$dbh->query("SELECT COUNT(*) FROM tbl_saved_places WHERE user_email='$A'")->fetchColumn() === 1);
$r = $a->json("$base/api/map.php?action=saved_save", ['place_key' => 'dest:x', 'name' => 'X', 'lat' => '21.4', 'lng' => '92.0']);
t('write without CSRF token is refused (403)', $r['code'] === 403);
$r = $a->json("$base/api/map.php?action=saved_save", ['csrf_token' => $tokA, 'place_key' => 'dest:bad', 'name' => 'Bad', 'lat' => '999', 'lng' => '92.0', 'category' => 'beach']);
t('invalid coordinates are rejected', $r['code'] === 400 || empty($r['j']['ok']));
$r = $a->json("$base/api/map.php?action=nearby&lat=21.4&lng=92&radius_m=99999999");
t('excessive search radius is clamped to 50 km', $r['code'] === 200 && (int)($r['j']['radius_m'] ?? 0) === 50000, 'HTTP ' . $r['code']);
$r = $a->json("$base/api/map.php?action=nearby&lat=999&lng=92&radius_m=1000");
t('invalid latitude is rejected (400)', $r['code'] === 400, 'HTTP ' . $r['code']);

// 6. sign out: protected information is gone
$a->req("$base/logout.php?t=" . urlencode($a->token("$base/index.php")), null, true);
$r = $a->req("$base/my-trips.php", null, false);
t('after sign-out my-trips.php sends the user back to login', in_array($r['code'], [301, 302, 303], true) && str_contains($r['loc'], 'login.php'), 'HTTP ' . $r['code'] . ' ' . $r['loc']);

tcleanup($dbh);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
