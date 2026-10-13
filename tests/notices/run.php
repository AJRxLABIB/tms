<?php
/** Travel-notice tests against the real DB (rows tagged Country "ZZ-Test"; removed afterwards). php tests/notices/run.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/../..');
require 'backend/config.php';
require_once 'backend/services/TravelNoticeService.php';
TravelNoticeService::migrate($dbh);
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 66) . $d . "\n"; }
$dbh->exec("DELETE FROM tbl_travel_notices WHERE Country='ZZ-Test'");
$d = fn(int $o) => date('Y-m-d', strtotime("$o days"));
$base = ['kind' => 'entry', 'country' => 'ZZ-Test', 'title' => 'Visa on arrival', 'body' => 'Check the official page.', 'source_name' => 'Ministry', 'source_url' => 'https://example.gov/visa', 'verified_on' => $d(-1)];
$S = fn(array $o, ?int $id = null) => TravelNoticeService::save($dbh, $id, array_merge($base, $o), 'tester');

t('valid entry notice saves', $S([])['ok']);
t('source link is mandatory', !$S(['source_url' => ''])['ok']);
t('javascript: link refused', !$S(['source_url' => 'javascript:alert(1)'])['ok']);
t('plain-text link without scheme refused', !$S(['source_url' => 'example.gov'])['ok']);
t('source name is mandatory', !$S(['source_name' => ''])['ok']);
t('unknown type refused', !$S(['kind' => 'score'])['ok']);
t('future checked-on date refused', !$S(['verified_on' => $d(2)])['ok']);
t('bad date refused', !$S(['verified_on' => '2026-02-31'])['ok']);
t('empty body refused', !$S(['body' => '  '])['ok']);
t('over-long body refused', !$S(['body' => str_repeat('x', 2001)])['ok']);
t('disruption without expiry refused', !$S(['kind' => 'disruption'])['ok']);
t('disruption with expiry > 60 days refused', !$S(['kind' => 'disruption', 'verified_on' => $d(0), 'expires_on' => $d(61)])['ok']);
t('disruption with expiry before checked date refused', !$S(['kind' => 'disruption', 'verified_on' => $d(0), 'expires_on' => $d(-1)])['ok']);
$r = $S(['kind' => 'disruption', 'title' => 'Strike', 'verified_on' => $d(0), 'expires_on' => $d(10)]);
t('valid disruption saves', $r['ok']); $disId = (int)$r['id'];

$vis = TravelNoticeService::visible($dbh, 'zz-test');
t('country match is case-insensitive', count($vis) === 2);
t('disruption sorted before entry rules', $vis[0]['Kind'] === 'disruption');
t('other country sees nothing', TravelNoticeService::visible($dbh, 'ZZ-Other') === []);
t('fresh notices are not flagged stale', !$vis[0]['stale'] && !$vis[1]['stale']);

$old = $S(['title' => 'Old entry rule', 'verified_on' => $d(-100)]); 
$o = array_values(array_filter(TravelNoticeService::visible($dbh, 'ZZ-Test'), fn($x) => $x['Title'] === 'Old entry rule'))[0];
t('entry notice older than 90 days is flagged stale (still shown)', $o['stale'] === true);
$dbh->prepare('UPDATE tbl_travel_notices SET VerifiedOn=? WHERE NoticeId=?')->execute([$d(-8), $disId]);
$dv = array_values(array_filter(TravelNoticeService::visible($dbh, 'ZZ-Test'), fn($x) => $x['Kind'] === 'disruption'))[0];
t('disruption checked 8 days ago is flagged stale', $dv['stale'] === true);
$dbh->prepare('UPDATE tbl_travel_notices SET ExpiresOn=? WHERE NoticeId=?')->execute([$d(-1), $disId]);
t('expired disruption disappears from customer view', !in_array($disId, array_column(TravelNoticeService::visible($dbh, 'ZZ-Test'), 'NoticeId')));
t('...but remains visible to admin as expired', (bool)array_filter(TravelNoticeService::all($dbh), fn($x) => (int)$x['NoticeId'] === $disId && $x['expired']));
t('archive hides a notice', TravelNoticeService::setStatus($dbh, (int)$old['id'], 'archived') && !in_array((int)$old['id'], array_column(TravelNoticeService::visible($dbh, 'ZZ-Test'), 'NoticeId')));
t('restore shows it again', TravelNoticeService::setStatus($dbh, (int)$old['id'], 'active') && in_array((int)$old['id'], array_column(TravelNoticeService::visible($dbh, 'ZZ-Test'), 'NoticeId')));
t('invalid status refused', !TravelNoticeService::setStatus($dbh, (int)$old['id'], 'deleted'));
t('edit updates in place', $S(['title' => 'Edited'], (int)$old['id'])['ok'] && TravelNoticeService::find($dbh, (int)$old['id'])['Title'] === 'Edited');
$xss = $S(['title' => '<script>alert(1)</script>']);
t('stored text is kept raw (escaped at output)', TravelNoticeService::find($dbh, (int)$xss['id'])['Title'] === '<script>alert(1)</script>');

$dbh->exec("DELETE FROM tbl_travel_notices WHERE Country='ZZ-Test'");
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
