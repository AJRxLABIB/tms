<?php
/** PromptRedactor: pure tests (no DB, no network).   php tests/ai/redactor_test.php */
require_once __DIR__ . '/../../backend/ai/PromptRedactor.php';
$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . str_pad($n, 62) . $d . "\n"; }
$r = fn(string $s) => PromptRedactor::redact($s);

t('email masked', !str_contains($r('mail me at a.b+c@mail.example.org ok'), '@') && str_contains($r('x@y.com'), '[redacted:email]'));
t('BD phone 01XXXXXXXXX masked', $r('call 01712345678 now') === 'call [redacted:phone] now');
t('BD phone with dashes masked', !str_contains($r('call 017-1234-5678'), '1234'), $r('call 017-1234-5678'));
t('+880 phone masked', $r('wa +8801912345678') === 'wa [redacted:phone]');
t('Bengali-digit phone masked', $r('নম্বর ০১৭১২৩৪৫৬৭৮') === 'নম্বর [redacted:phone]', $r('নম্বর ০১৭১২৩৪৫৬৭৮'));
t('Bengali-digit NID (10 digits) masked', str_contains($r('এনআইডি ১২৩৪৫৬৭৮৯০'), '[redacted:id]'));
t('card with spaces masked', $r('4111 1111 1111 1111') === '[redacted:card]');
t('card with dashes masked', $r('5500-0000-0000-0004') === '[redacted:card]');
t('plain 16-digit card masked', $r('pay 4111111111111111 please') === 'pay [redacted:card] please');
t('13-digit NID-like (not Luhn) is not left in clear', !preg_match('/\d{10}/', $r('nid 1234567890123')), $r('nid 1234567890123'));
t('17-digit NID-like not left in clear', !preg_match('/\d{10}/', $r('nid 12345678901234567')), $r('nid 12345678901234567'));
t('passport-like masked', $r('passport A12345678') === 'passport [redacted:passport]');
t('two-letter passport masked', $r('BN1234567') === '[redacted:passport]');
t('Google-style key masked', $r('k=AIzaSyA1234567890abcdefghijklmnopqrstuv') === 'k=[redacted:key]');
t('sk- key masked', str_contains($r('sk-abcdefghijklmnopqrstuvwx'), '[redacted:key]'));
t('Bearer token masked', $r('Authorization: Bearer abcdef1234567890abcdef') === 'Authorization: [redacted:key]');
t('long hex secret masked', str_contains($r('hash 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'), '[redacted:key]'));
t('prices survive', $r('budget ৳50000 for 4 days, 2 people') === 'budget ৳50000 for 4 days, 2 people');
t('Bengali price survives (digits folded)', str_contains($r('বাজেট ৫০০০০ টাকা'), '50000') && !str_contains($r('বাজেট ৫০০০০ টাকা'), '[redacted'));
t('ordinary words survive', $r("Cox's Bazar beach trip in December") === "Cox's Bazar beach trip in December");
t('package ids and short numbers survive', $r('compare packages 12 and 345') === 'compare packages 12 and 345');
t('date survives', $r('travel on 2026-12-20') === 'travel on 2026-12-20', $r('travel on 2026-12-20'));
t('empty string', $r('') === '');
t('idempotent (redacting twice changes nothing)', $r($r('mail a@b.co or 01712345678')) === $r('mail a@b.co or 01712345678'));
$c = PromptRedactor::redactWithCounts('a@b.co, c@d.co and 01712345678');
t('counts per kind', ($c['counts']['email'] ?? 0) === 2 && ($c['counts']['phone'] ?? 0) === 1);
t('forLog truncates', mb_strlen(PromptRedactor::forLog(str_repeat('x', 500), 50)) <= 51);
t('forLog redacts before truncating', !str_contains(PromptRedactor::forLog('contact a@b.co ' . str_repeat('x', 400), 60), '@'));
t('localhost is local', PromptRedactor::isLocalEndpoint('http://127.0.0.1:11434') && PromptRedactor::isLocalEndpoint('http://localhost:11434'));
t('private LAN address is local', PromptRedactor::isLocalEndpoint('http://192.168.1.20:11434') && PromptRedactor::isLocalEndpoint('http://10.0.0.5:11434'));
t('public host is not local', !PromptRedactor::isLocalEndpoint('https://generativelanguage.googleapis.com') && !PromptRedactor::isLocalEndpoint('http://8.8.8.8:11434'));
t('unparseable URL is not local', !PromptRedactor::isLocalEndpoint('not a url'));
t('forProvider: local ollama sends text unchanged', PromptRedactor::forProvider('ollama', 'mail a@b.co', 'http://127.0.0.1:11434') === 'mail a@b.co');
t('forProvider: gemini always redacted', PromptRedactor::forProvider('gemini', 'mail a@b.co', 'https://x') === 'mail [redacted:email]');
t('forProvider: ollama on a public host is redacted', PromptRedactor::forProvider('ollama', 'mail a@b.co', 'https://remote.example.com') === 'mail [redacted:email]');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
