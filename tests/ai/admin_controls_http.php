<?php
/** Admin "Engine controls" over REAL HTTP with real admin sessions: auth + role gate, CSRF, validation, audit log, effect on the chat
 *  endpoint, and XSS safety of the turn viewer. Creates two temporary admin accounts and removes them (and its config rows) afterwards.
 *  php tests/ai/admin_controls_http.php [base-url] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/ai/AIConfig.php'; require_once 'backend/ai/AITurnLog.php';
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 76) . $d . "\n"; }
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

$pw = 'Cfg-test-' . bin2hex(random_bytes(6));
$hash = password_hash($pw, PASSWORD_BCRYPT);
$dbh->exec("DELETE FROM admin WHERE UserName IN ('cfg-test-super','cfg-test-content')");
$ins = $dbh->prepare('INSERT INTO admin (UserName, Password, Role) VALUES (?,?,?)');
$ins->execute(['cfg-test-super', $hash, 'superadmin']); $ins->execute(['cfg-test-content', $hash, 'content_manager']);
$cleanup = function () use ($dbh) {
    $dbh->exec("DELETE FROM admin WHERE UserName IN ('cfg-test-super','cfg-test-content')");
    $dbh->exec("DELETE FROM tbl_app_meta WHERE k LIKE 'ai_cfg_%'"); $dbh->exec("DELETE FROM tbl_ai_config_log WHERE changed_by IN ('cfg-test-super','cfg-test-content')");
    $dbh->exec("DELETE FROM tbl_login_attempts WHERE Email LIKE 'admin:cfg-test-%'");
    $dbh->exec("DELETE FROM tbl_ai_turn_log WHERE tools='cfgtest_<b>x</b>'"); AIConfig::clearCache();
};
register_shutdown_function($cleanup);
$adminLogin = function (string $u) use ($base, $pw): Client {
    $c = new Client(); $tok = $c->token("$base/admin/index.php");
    $c->req("$base/admin/index.php", ['csrf_token' => $tok, 'txtusername' => $u, 'txtpassword' => $pw, 'btn-login' => '1']);
    return $c;
};
$url = "$base/admin/ai-dashboard.php";

$anon = new Client(); $r = $anon->req($url, null, false);
t('anonymous visitor cannot open the dashboard', $r['code'] !== 200 || strlen($r['body']) < 300);
$post = $anon->req($url, ['ai_cfg_action' => 'save', 'cfg' => ['assistant_enabled' => '0']], false);
t('anonymous POST changes nothing', AIConfig::get($dbh, 'assistant_enabled') === 1);
$cm = $adminLogin('cfg-test-content'); $r = $cm->req($url, null, false);
t('admin without the AI role gets 403, not the controls', $r['code'] === 403 && !str_contains($r['body'], 'Engine controls'));
$tok = $cm->token("$base/admin/dashboard.php");
$cm->req($url, ['csrf_token' => $tok, 'ai_cfg_action' => 'save', 'cfg' => ['assistant_enabled' => '0']], false); AIConfig::clearCache();
t('admin without the AI role cannot change settings by POSTing directly', AIConfig::get($dbh, 'assistant_enabled') === 1);

$sa = $adminLogin('cfg-test-super'); $page = $sa->req($url)['body'];
t('superadmin sees the controls, current values and the privacy note', str_contains($page, 'Engine controls') && str_contains($page, 'name="cfg[max_steps]"') && str_contains($page, 'cannot be switched off'));
t('embedding panel renders, and without AI_EMBED_MODEL says keyword-only (no fake coverage)', str_contains($page, 'Embedding index') && (str_contains($page, 'Search uses keyword (BM25)') || str_contains($page, 'packages indexed for model')) && !str_contains($page, 'Index now') === !getenv('AI_EMBED_MODEL'));
$tok = ($m = preg_match('/name="csrf_token" value="([^"]+)"/', $page, $mm)) ? $mm[1] : '';
$r = $sa->req($url, ['ai_cfg_action' => 'save', 'cfg' => ['max_steps' => '2', 'deadline_sec' => '8', 'assistant_enabled' => '1', 'memory_enabled' => '1', 'failover_enabled' => '1']], false); AIConfig::clearCache();
t('POST without a CSRF token is rejected (403) and changes nothing', $r['code'] === 403 && AIConfig::get($dbh, 'max_steps') === 4);
$r = $sa->req($url, ['csrf_token' => $tok, 'ai_cfg_action' => 'save', 'cfg' => ['max_steps' => '2', 'deadline_sec' => '8', 'assistant_enabled' => '1', 'memory_enabled' => '1', 'failover_enabled' => '1']], false); AIConfig::clearCache();
t('valid save redirects and stores the new value', in_array($r['code'], [301, 302, 303], true) && AIConfig::get($dbh, 'max_steps') === 2);
$page = $sa->req($url)['body'];
t('flash message shown once after save, form shows the new value', str_contains($page, 'Engine controls saved.') && preg_match('/name="cfg\[max_steps\]" value="2"/', $page) === 1 && !str_contains($sa->req($url)['body'], 'Engine controls saved.'));
t('the change is in the audit log with the real admin name', ($lg = AIConfig::recentChanges($dbh, 1)[0] ?? null) && $lg['changed_by'] === 'cfg-test-super' && $lg['new_value'] === '2' && str_contains($page, 'cfg-test-super'));
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $page, $mm) ? $mm[1] : $tok;
$sa->req($url, ['csrf_token' => $tok, 'ai_cfg_action' => 'save', 'cfg' => ['max_steps' => '99', 'deadline_sec' => '1; DROP', 'memory_enabled' => '1', 'assistant_enabled' => '1', 'failover_enabled' => '1']], false); AIConfig::clearCache();
$page = $sa->req($url)['body'];
t('out-of-range / junk numbers are rejected with a visible message; previous value kept', AIConfig::get($dbh, 'max_steps') === 2 && AIConfig::get($dbh, 'deadline_sec') === 8 && str_contains($page, 'were rejected'));

// Effect on the real chat endpoint: assistant switched off -> 503 for visitors
$tok = preg_match('/name="csrf_token" value="([^"]+)"/', $page, $mm) ? $mm[1] : $tok;
$sa->req($url, ['csrf_token' => $tok, 'ai_cfg_action' => 'save', 'cfg' => ['max_steps' => '4', 'deadline_sec' => '8', 'memory_enabled' => '1', 'failover_enabled' => '1']], false); AIConfig::clearCache(); // assistant checkbox absent = off
t('unchecked "assistant" box switches the assistant off', AIConfig::get($dbh, 'assistant_enabled') === 0);
$vis = new Client(); $vt = $vis->token("$base/index.php");
$r = $vis->req("$base/api/ai.php?action=chatbot", ['csrf_token' => $vt, 'message' => 'show me a beach package', 'page_context' => '']);
t('with the assistant off, the chat endpoint answers 503 (widget falls back to standard answers)', $r['code'] === 503);

// Restore defaults
$page = $sa->req($url)['body']; $tok = preg_match('/name="csrf_token" value="([^"]+)"/', $page, $mm) ? $mm[1] : $tok;
$sa->req($url, ['csrf_token' => $tok, 'ai_cfg_action' => 'reset'], false); AIConfig::clearCache();
t('"Restore defaults" restores every setting', AIConfig::get($dbh, 'assistant_enabled') === 1 && AIConfig::get($dbh, 'max_steps') === 4);

// Turn viewer escapes everything
$dbh->exec("INSERT INTO tbl_ai_turn_log (outcome, tools) VALUES ('answered', 'cfgtest_<b>x</b>')");
$page = $sa->req($url)['body'];
t('recent-turn viewer HTML-escapes stored values (no raw tag injected)', str_contains($page, 'cfgtest_&lt;b&gt;x&lt;/b&gt;') && !str_contains($page, 'cfgtest_<b>x</b>'));
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
