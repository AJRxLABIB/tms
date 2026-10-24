<?php
/**
 * Pure tests (no database, no network): AI provider auto-selection, Gemini key format, diagnostics redaction and
 * next steps, the catalogue-only destination overview and the no-model chat renderer.
 *   php tests/ai/provider_resolver_test.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../backend/ai/AIProviderResolver.php';
require_once __DIR__ . '/../../backend/ai/AIDiagnostics.php';
require_once __DIR__ . '/../../backend/ai/OverviewFallback.php';
require_once __DIR__ . '/../../backend/ai/OfflineAssistant.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $detail = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : ($detail !== '' ? "  -> $detail" : '')) . "\n"; }

$goodKey = 'AIza' . str_repeat('x', 35);
$up   = fn(array $names) => fn() => $names;
$down = fn() => null;

// ── key format ──
t('valid Google AI Studio key shape accepted', AIProviderResolver::geminiKeyLooksValid($goodKey));
t('key without a known prefix rejected', !AIProviderResolver::geminiKeyLooksValid(str_repeat('a', 39)));
t('new AI Studio authorization key (AQ.) accepted', AIProviderResolver::geminiKeyLooksValid('AQ.' . str_repeat('x', 50)));
t('AQ. key that is too short rejected', !AIProviderResolver::geminiKeyLooksValid('AQ.abc'));
t('key of wrong length rejected', !AIProviderResolver::geminiKeyLooksValid('AIza' . str_repeat('x', 20)));
t('empty key rejected', !AIProviderResolver::geminiKeyLooksValid(''));
t('key with illegal characters rejected', !AIProviderResolver::geminiKeyLooksValid('AIza' . str_repeat('x', 34) . '!'));

// ── embedding model detection ──
t('nomic-embed-text is an embedding model', AIProviderResolver::isEmbeddingModel('nomic-embed-text:latest'));
t('bge-m3 is an embedding model', AIProviderResolver::isEmbeddingModel('bge-m3'));
t('qwen2.5:3b-instruct is a chat model', !AIProviderResolver::isEmbeddingModel('qwen2.5:3b-instruct'));

// ── auto selection ──
$r = AIProviderResolver::resolve(['provider' => '', 'gemini_key' => '', 'ollama_model' => 'qwen2.5:3b-instruct'], $up(['qwen2.5:3b-instruct', 'nomic-embed-text:latest']));
t('unset provider + Ollama with the wanted model -> ollama with that model', $r['provider'] === 'ollama' && $r['model'] === 'qwen2.5:3b-instruct' && $r['reason'] === 'auto_ollama', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'gemini_key' => '', 'ollama_model' => 'qwen2.5:3b-instruct'], $up(['llama3.2:1b']));
t('auto: wanted model missing -> first installed chat model', $r['provider'] === 'ollama' && $r['model'] === 'llama3.2:1b', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'ollama_model' => 'qwen2.5:3b-instruct'], $up(['nomic-embed-text:latest']));
t('auto: only an embedding model installed -> not usable for chat', $r['provider'] === 'none' && $r['reason'] === 'ollama_no_model', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'gemini_key' => $goodKey], $down);
t('auto: Ollama down + valid Gemini key -> gemini', $r['provider'] === 'gemini' && $r['reason'] === 'auto_gemini', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => '', 'gemini_key' => 'not-a-real-key-12345'], $down);
t('auto: Ollama down + malformed Gemini key -> none, reason invalid_key (the key is never used)', $r['provider'] === 'none' && $r['reason'] === 'invalid_key', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => '', 'gemini_key' => ''], $down);
t('auto: nothing configured -> none', $r['provider'] === 'none' && $r['reason'] === 'not_configured', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'gemini_key' => $goodKey], $up(['qwen2.5:3b-instruct']));
t('auto prefers local Ollama over a valid Gemini key (no data leaves the machine)', $r['provider'] === 'ollama', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'gemini_key' => $goodKey, 'ollama_model' => 'x'], function () { throw new RuntimeException('boom'); });
t('a throwing probe is treated as "Ollama down"', $r['provider'] === 'gemini', json_encode($r));

// ── explicit settings always win ──
foreach (['ollama', 'gemini', 'openai'] as $p) {
    $r = AIProviderResolver::resolve(['provider' => $p, 'gemini_key' => '', 'ollama_model' => 'm'], $down);
    t("explicit AI_PROVIDER=$p is honoured even if it cannot work", $r['provider'] === $p && $r['reason'] === 'explicit', json_encode($r));
}
$r = AIProviderResolver::resolve(['provider' => 'none', 'gemini_key' => $goodKey], $up(['qwen2.5:3b-instruct']));
t('AI_PROVIDER=none turns AI off even with a key and a runtime', $r['provider'] === 'none' && $r['reason'] === 'disabled');
$r = AIProviderResolver::resolve(['provider' => 'banana', 'gemini_key' => $goodKey], $up(['qwen2.5:3b-instruct']));
t('an unrecognised value behaves like auto (local first), not like a cloud vendor', $r['provider'] === 'ollama', json_encode($r));
$r = AIProviderResolver::resolve(['provider' => 'auto', 'gemini_key' => ''], $up(['qwen2.5:3b-instruct']));
t('OpenAI is never auto-selected', $r['provider'] !== 'openai');

// ── wording ──
t('customer wording never mentions keys, ports or env vars', !preg_match('/key|ollama|\.env|port|11434/i', AIProviderResolver::explain('invalid_key', false)));
t('admin wording for invalid_key names the cause', stripos(AIProviderResolver::explain('invalid_key', true), 'GEMINI_API_KEY') !== false);

// ── redaction ──
$raw = 'HTTP 400 {"error":{"message":"API key not valid: ' . $goodKey . '. Bearer abcdefghijklmnop1234"}} <b>x</b> sk-abcdefghijklmnop123456';
$red = AIDiagnostics::redact($raw, 500);
t('redact removes a Google key', strpos($red, $goodKey) === false && strpos($red, 'AIzaxxxx') === false, $red);
t('redact removes a bearer token', strpos($red, 'abcdefghijklmnop1234') === false, $red);
t('redact removes an sk- key', strpos($red, 'sk-abcdefghijklmnop123456') === false, $red);
t('redact strips markup', strpos($red, '<b>') === false);
t('redact bounds the length', mb_strlen(AIDiagnostics::redact(str_repeat('a', 2000), 100), 'UTF-8') <= 100);

// ── diagnostics state + next steps (stubbed Ollama) ──
$s = AIDiagnostics::state(['provider' => 'auto', 'gemini_key' => 'bad-key', 'ollama_base' => 'http://127.0.0.1:11434', 'ollama_model' => 'qwen2.5:3b-instruct', 'embed_model' => ''], $down);
t('state: nothing running + bad key -> none / invalid_key', $s['selected']['provider'] === 'none' && $s['selected']['reason'] === 'invalid_key' && !$s['ollama_reachable']);
$steps = implode(' | ', AIDiagnostics::nextSteps($s));
t('next steps tell the operator to install/start Ollama', stripos($steps, 'Install Ollama') !== false, $steps);
t('next steps explain the bad Gemini key without printing it', stripos($steps, 'not a valid Google AI Studio key') !== false && strpos($steps, 'bad-key') === false, $steps);
t('next steps say the site keeps working', stripos($steps, 'keeps working') !== false);

$s = AIDiagnostics::state(['provider' => 'auto', 'gemini_key' => '', 'ollama_model' => 'qwen2.5:3b-instruct', 'embed_model' => ''],
    fn() => [['name' => 'nomic-embed-text:latest', 'size' => 1], ['name' => 'llama3.2:1b', 'size' => 2]]);
t('state: lists chat and embedding models separately', $s['ollama_chat'] === ['llama3.2:1b'] && $s['ollama_embed'] === ['nomic-embed-text:latest']);
t('state: wanted model reported as not installed', $s['wanted_installed'] === false && $s['selected']['model'] === 'llama3.2:1b');
$steps = implode(' | ', AIDiagnostics::nextSteps($s));
t('next steps suggest pulling the default model', strpos($steps, 'ollama pull qwen2.5:3b-instruct') !== false, $steps);

$s = AIDiagnostics::state(['provider' => 'auto', 'gemini_key' => '', 'ollama_model' => 'qwen2.5:3b-instruct'], fn() => []);
t('Ollama reachable but empty -> ollama_no_model with pull instruction', $s['selected']['reason'] === 'ollama_no_model' && strpos(implode(' ', AIDiagnostics::nextSteps($s)), 'ollama pull qwen2.5:3b-instruct') !== false);

// ── OverviewFallback ──
$guides = [
    ['slug' => 'cox-bazar', 'name' => "Cox's Bazar", 'known_for' => 'Longest natural sea beach | Himchari | Inani', 'best_time' => 'Nov-Mar', 'how_to_get_there' => 'Bus or flight from Dhaka', 'practical_note' => 'Check sea conditions', 'sources' => 'https://a.example|https://b.example', 'researched_at' => '2026-09-20'],
    ['slug' => 'sylhet', 'name' => 'Sylhet', 'known_for' => 'Tea gardens', 'best_time' => 'Oct-Mar', 'how_to_get_there' => 'Train', 'practical_note' => '', 'sources' => '', 'researched_at' => '2026-09-20'],
];
t('guide match: "Cox\'s Bazar, Bangladesh" -> cox-bazar', (OverviewFallback::matchGuide($guides, "Cox's Bazar, Bangladesh")['slug'] ?? '') === 'cox-bazar');
t('guide match: slug form "cox bazar"', (OverviewFallback::matchGuide($guides, 'Cox Bazar')['slug'] ?? '') === 'cox-bazar');
t('guide match: unrelated place -> null', OverviewFallback::matchGuide($guides, 'Paris, France') === null);
t('guide match: too-short fragment does not match', OverviewFallback::matchGuide($guides, 'Sy') === null);
$inv = ['packages' => [['PackageName' => 'Sea Beach Escape'], ['PackageName' => 'x']], 'hotels' => [['HotelName' => 'h']], 'activities' => []];
$pp = [['PackageName' => 'Sea Beach Escape', 'PackageLocation' => "Cox's Bazar", 'PackagePrice' => 12000, 'PackageDuration' => '3 Days']];
$emg = ['police' => '999', 'ambulance' => '199', 'fire' => '199', 'helpline' => '16263', 'embassy' => null];
$o = OverviewFallback::build($guides[0], $inv, $pp, $emg, "Cox's Bazar, Bangladesh", 'Bangladesh');
t('overview built from the guide, not invented', strpos($o['overview'], 'Longest natural sea beach') !== false && strpos($o['overview'], '2 tour packages, 1 hotel') !== false, $o['overview']);
t('overview flags itself as catalogue-sourced with the honest note', $o['source'] === 'catalogue' && strpos($o['ai_note'], 'AI summary unavailable') !== false);
t('attractions: guide items first, then real tours with real price', count($o['attractions']) === 4 && $o['attractions'][3]['name'] === 'Sea Beach Escape' && strpos($o['attractions'][3]['description'], '12,000') !== false, json_encode($o['attractions']));
t('no invented costs, history, culture or foods', $o['estimated_costs'] === null && $o['history'] === '' && $o['local_culture'] === '' && $o['famous_foods'] === []);
t('best time and getting there come from the guide', $o['best_time_to_visit'] === 'Nov-Mar' && $o['how_to_get_there'] === 'Bus or flight from Dhaka');
t('emergency contacts passed through unchanged', $o['emergency_contacts'] === $emg);
t('guide sources and research date are kept for attribution', $o['guide_sources'] === ['https://a.example', 'https://b.example'] && $o['researched_at'] === '2026-09-20');
$o2 = OverviewFallback::build(null, ['packages' => [], 'hotels' => [], 'activities' => []], [], null, 'Lisbon, Portugal', 'Portugal');
t('no guide and empty catalogue: honest sentence, nothing invented', strpos($o2['overview'], 'do not have a curated guide') !== false && strpos($o2['overview'], 'Nothing is listed') !== false && $o2['attractions'] === [], $o2['overview']);
$o3 = OverviewFallback::build(null, $inv, [], null, 'Dhaka', 'Bangladesh', ['current' => ['temp' => 31.4, 'description' => 'Clear sky']]);
t('live weather is added when the page has it', strpos($o3['overview'], '31°C (Clear sky)') !== false, $o3['overview']);

// ── OfflineAssistant::render ──
$cite = ['id' => 'pkg:7', 'title' => 'Sea Beach Escape', 'url' => 'package-details.php?pkgid=7'];
$search = ['tool' => 'search_packages', 'ok' => true, 'data' => [
    ['id' => 7, 'title' => 'Sea Beach Escape', 'location' => "Cox's Bazar", 'country' => 'Bangladesh', 'price_bdt' => 12000, 'duration' => '3 Days', 'citation' => $cite],
    ['id' => 8, 'title' => 'Hill & Lake <b>Tour</b>', 'location' => 'Bandarban', 'country' => 'Bangladesh', 'price_bdt' => 15500, 'duration' => '4 Days', 'citation' => ['id' => 'pkg:8', 'title' => 'Hill', 'url' => 'package-details.php?pkgid=8']],
]];
$r = OfflineAssistant::render([$search], 'en');
t('search reply lists real titles with prices from the tool', strpos($r['reply'], '৳ 12,000') !== false && strpos($r['reply'], '৳ 15,500') !== false);
t('search reply links only to relative catalogue pages', strpos($r['reply'], '<a href="package-details.php?pkgid=7">Sea Beach Escape</a>') !== false);
t('markup in a title is neutralised (widget anchor rule)', strpos($r['reply'], '<b>') === false && strpos($r['reply'], 'Hill and Lake Tour') !== false, $r['reply']);
t('sources carry the verified citations', count($r['sources']) === 2 && $r['sources'][0]['id'] === 'pkg:7');
t('chips offered after a search', in_array('Compare them', $r['suggestions'], true));
$r = OfflineAssistant::render([['tool' => 'search_packages', 'ok' => true, 'data' => []]], 'en');
t('empty search -> honest "nothing found", no invented package', strpos($r['reply'], "couldn't find anything") !== false && !$r['found']);
$r = OfflineAssistant::render([$search], 'bn', true);
t('Bengali reply uses the Bengali browse wording', strpos($r['reply'], 'হুবহু মিল পাইনি') !== false);
$hotels = ['tool' => 'search_hotels', 'ok' => true, 'data' => ['results' => [
    ['kind' => 'hotel', 'id' => '1', 'title' => 'Real Inn', 'city' => 'Sylhet', 'stars' => 4, 'demo' => false, 'price_per_night_bdt' => 4500, 'citation' => ['id' => 'hotel:1', 'title' => 'Real Inn', 'url' => 'hotel-details.php?id=1']],
    ['kind' => 'hotel', 'id' => '2', 'title' => 'Demo Palace', 'city' => 'Sylhet', 'stars' => 5, 'demo' => true, 'price_bdt' => null, 'citation' => ['id' => 'hotel:2', 'title' => 'Demo Palace', 'url' => 'hotel-details.php?id=2']],
]]];
$r = OfflineAssistant::render([$hotels], 'en');
t('real hotel shows its listed price per night', strpos($r['reply'], '৳ 4,500 per night') !== false, $r['reply']);
t('demo hotel is labelled and never priced', strpos($r['reply'], 'sample entry, not a real listing') !== false && preg_match('/Demo Palace.*৳/u', $r['reply']) === 0, $r['reply']);
t('availability is never claimed (points to the booking page)', strpos($r['reply'], 'Check the booking page') !== false);
$r = OfflineAssistant::render([['tool' => 'my_bookings', 'ok' => false, 'error' => 'sign_in_required']], 'en');
t('guest asking for bookings is asked to sign in', strpos($r['reply'], 'sign in') !== false);
$r = OfflineAssistant::render([['tool' => 'my_bookings', 'ok' => true, 'data' => [['booking_id' => 12, 'package' => 'Sea Beach Escape', 'from' => '2026-12-01', 'to' => '2026-12-03', 'status' => 'confirmed', 'payment' => 'paid']]]], 'en');
t('own bookings rendered from the tool result', strpos($r['reply'], '#12 Sea Beach Escape') !== false && strpos($r['reply'], 'confirmed') !== false);
$r = OfflineAssistant::render([['tool' => 'get_cancellation_info', 'ok' => true, 'data' => ['package_policy' => null, 'site_policy' => [], 'note' => 'none']]], 'en');
t('no policy text recorded -> says so, invents no refund rule', strpos($r['reply'], 'No cancellation policy is recorded') !== false);
$r = OfflineAssistant::render([['tool' => 'compare_packages', 'ok' => true, 'data' => ['packages' => [
    ['id' => 7, 'title' => 'A', 'location' => 'X', 'country' => 'B', 'price_bdt' => 9000, 'duration' => '2 Days', 'citation' => $cite],
    ['id' => 8, 'title' => 'B', 'location' => 'Y', 'country' => 'B', 'price_bdt' => 12000, 'duration' => '3 Days', 'citation' => $cite]], 'cheapest_id' => 7]]], 'en');
t('comparison names the cheaper package using the tool\'s own result', strpos($r['reply'], 'Cheapest of these: **A**') !== false, $r['reply']);
t('plain(): anchors and bold become plain text', OfflineAssistant::plain('<a href="package-details.php?pkgid=7">Sea</a> **bold**') === 'Sea bold');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
