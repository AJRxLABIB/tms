<?php
/** "My AI memory" over REAL HTTP: login gate, CSRF, add/edit/delete, cross-user isolation, XSS escaping, and the chat endpoint.
 *  php tests/ai/memory_http.php [base-url] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 74) . $d . "\n"; }
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

$A = 'mem-http-a@idor-test.example.test'; $B = 'mem-http-b@idor-test.example.test'; $PW = 'Idor-test-pass-1';
$clean = function () use ($dbh) { $dbh->exec("DELETE FROM tbl_ai_user_memory WHERE user_email LIKE 'mem-http-%@idor-test.example.test'"); $dbh->exec("DELETE FROM tblusers WHERE EmailId LIKE 'mem-http-%@idor-test.example.test'"); };
$clean();
$h = password_hash($PW, PASSWORD_BCRYPT);
$ins = $dbh->prepare('INSERT INTO tblusers (FullName, MobileNumber, EmailId, Password) VALUES (?,?,?,?)');
$ins->execute(['Alice Mem', '01711111111', $A, $h]); $ins->execute(['Bob Mem', '01722222222', $B, $h]);
$a = login($base, $A, $PW); $b = login($base, $B, $PW); $anon = new Client();
$page = "$base/ai-memory.php";
t('anonymous is sent to login, sees no memory page', !str_contains($anon->req($page)['body'], 'My AI memory'));
$body = $a->req($page)['body'];
t('signed-in user sees the page, empty', str_contains($body, 'My AI memory') && str_contains($body, 'Nothing saved yet'));
$tokA = $a->token($page); $tokB = $b->token($page);
$r = $a->req($page, ['add' => '1', 'category' => 'preference', 'content' => 'I like quiet places']);
t('POST without CSRF token is rejected (403) and saves nothing', $r['code'] === 403 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE user_email='$A'")->fetchColumn() === 0);
$body = $a->req($page, ['add' => '1', 'category' => 'preference', 'content' => 'I like quiet places', 'csrf_token' => $tokA])['body'];
t('add works and is listed', str_contains($body, 'I like quiet places') && str_contains($body, 'Saved.'));
$body = $a->req($page, ['add' => '1', 'category' => 'preference', 'content' => 'my password is hunter2', 'csrf_token' => $tokA])['body'];
t('sensitive text is refused with an explanation', str_contains($body, 'never saved') && !str_contains($body, 'hunter2</div>'));
$body = $a->req($page, ['add' => '1', 'category' => 'preference', 'content' => '<script>alert(1)</script>x-marker', 'csrf_token' => $tokA])['body'];
t('HTML in a memory is stripped/escaped on the page (no stored XSS)', !str_contains($body, '<script>alert(1)</script>'));
$id = (int)$dbh->query("SELECT MemoryId FROM tbl_ai_user_memory WHERE user_email='$A' AND Content='I like quiet places'")->fetchColumn();
t('B does not see A\'s memory', !str_contains($b->req($page)['body'], 'I like quiet places'));
$b->req($page, ['delete' => '1', 'id' => $id, 'csrf_token' => $tokB]);
t('B cannot delete A\'s item with a forged id', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE MemoryId=$id")->fetchColumn() === 1);
$b->req($page, ['update' => '1', 'id' => $id, 'category' => 'preference', 'content' => 'hijacked by B', 'csrf_token' => $tokB]);
t('B cannot edit A\'s item with a forged id', $dbh->query("SELECT Content FROM tbl_ai_user_memory WHERE MemoryId=$id")->fetchColumn() === 'I like quiet places');
$a->req($page, ['update' => '1', 'id' => $id, 'category' => 'preference', 'content' => 'I like beaches', 'csrf_token' => $tokA]);
t('owner can edit', $dbh->query("SELECT Content FROM tbl_ai_user_memory WHERE MemoryId=$id")->fetchColumn() === 'I like beaches');

// chat endpoint
$chat = fn(Client $c, string $msg, string $tok) => json_decode($c->req("$base/api/ai.php?action=chatbot", ['message' => $msg, 'csrf_token' => $tok])['body'], true);
$r = $chat($a, 'remember that I am travelling with a toddler', $tokA);
t('chat: "remember ..." saves for the signed-in user and confirms', ($r['success'] ?? false) && str_contains(json_encode($r), 'Saved') && (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE user_email='$A' AND Category='constraint'")->fetchColumn() === 1);
$r = $chat($anon, 'remember that I like trains', (new Client())->token("$base/index.php"));
t('chat: guest cannot save', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE Content='I like trains'")->fetchColumn() === 0);
$r = $chat($b, 'what do you remember about me?', $tokB);
t('chat: B asking "what do you remember" sees nothing of A', !str_contains(json_encode($r, JSON_UNESCAPED_UNICODE), 'toddler') && !str_contains(json_encode($r), 'beaches'));
$r = $chat($a, 'what do you remember about me?', $tokA);
t('chat: A asking gets A\'s own items', str_contains(json_encode($r), 'toddler'));
$r = $a->req("$base/api/ai.php?action=chatbot", ['message' => 'remember that I like boats']);
t('chat: command without CSRF token is rejected (403)', $r['code'] === 403 && (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE Content='I like boats'")->fetchColumn() === 0);

// switch off and delete all
$body = $a->req($page, ['toggle' => '1', 'csrf_token' => $tokA])['body'];
t('switch off works', str_contains($body, 'Memory is off') && !TravelMemoryEnabled($dbh, $A));
$a->req($page, ['toggle' => '1', 'enabled' => '1', 'csrf_token' => $tokA]);
$a->req($page, ['clear_all' => '1', 'csrf_token' => $tokA]);
t('delete everything removes all of A\'s items', (int)$dbh->query("SELECT COUNT(*) FROM tbl_ai_user_memory WHERE user_email='$A' AND Category<>'setting'")->fetchColumn() === 0);
$priv = $a->req("$base/privacy.php")['body'];
t('privacy page links to My AI memory', str_contains($priv, 'ai-memory.php'));
function TravelMemoryEnabled(PDO $d, string $e): bool { require_once __DIR__ . '/../../backend/ai/TravelMemory.php'; return TravelMemory::enabled($d, $e); }
$clean();
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
