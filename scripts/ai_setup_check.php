<?php
/**
 * scripts/ai_setup_check.php — "why is the AI not working?" in one command. CLI only. Prints no secrets.
 *
 *   php scripts/ai_setup_check.php            inspect configuration + local Ollama (no model call)
 *   php scripts/ai_setup_check.php --test     also send one tiny real request to the selected provider and time it
 *
 * Exit code: 0 = an AI provider is selected (and, with --test, answered), 1 = AI is off or the test failed.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); die('This script is CLI-only.'); }

require __DIR__ . '/../backend/config.php';
require_once __DIR__ . '/../backend/ai/AIClient.php';
require_once __DIR__ . '/../backend/ai/AIDiagnostics.php';

$doTest = in_array('--test', $argv ?? [], true);
$line = fn(string $tag, string $label, string $msg) => printf("%-5s %-26s %s\n", $tag, $label, $msg);

AIClient::resetResolution();
$s = AIDiagnostics::state();
$sel = $s['selected'];

echo "TMS AI setup check\n==================\n";
$line('INFO', 'AI_PROVIDER setting', $s['provider_setting']);
$line($sel['provider'] === 'none' ? 'FAIL' : 'PASS', 'Selected provider', $sel['provider'] . ($sel['model'] !== '' ? ' (' . $sel['model'] . ')' : '') . ' — ' . AIProviderResolver::explain($sel['reason'], true));
$line($s['ollama_reachable'] ? 'PASS' : 'WARN', 'Ollama runtime', $s['ollama_reachable'] ? 'reachable at ' . $s['ollama_base'] : 'not reachable at ' . $s['ollama_base']);
if ($s['ollama_reachable']) {
    $line($s['ollama_chat'] ? 'PASS' : 'FAIL', 'Chat models installed', $s['ollama_chat'] ? implode(', ', $s['ollama_chat']) : 'none');
    $line($s['ollama_embed'] ? 'PASS' : 'INFO', 'Embedding models', $s['ollama_embed'] ? implode(', ', $s['ollama_embed']) : 'none (optional)');
    if ($s['wanted_model'] !== '') $line($s['wanted_installed'] ? 'PASS' : 'WARN', 'OLLAMA_MODEL', $s['wanted_model'] . ($s['wanted_installed'] ? ' is installed' : ' is NOT installed'));
}
$line($s['gemini_key_set'] ? ($s['gemini_key_valid'] ? 'PASS' : 'WARN') : 'INFO', 'GEMINI_API_KEY', !$s['gemini_key_set'] ? 'not set' : ($s['gemini_key_valid'] ? 'set, valid format (value not shown)' : 'set but NOT a valid Google AI Studio key format (value not shown)'));

$ok = $sel['provider'] !== 'none';
if ($doTest) {
    if (!$ok) { $line('SKIP', 'Test request', 'no provider selected'); }
    else {
        echo "\nSending one test request to {$sel['provider']} (the first call after a restart can be slow)...\n";
        $r = AIDiagnostics::probe($dbh, 60);
        $line($r['ok'] ? 'PASS' : 'FAIL', 'Test request', $r['ok'] ? "answered in {$r['latency_ms']} ms with model {$r['model']}" : $r['error']);
        $ok = $r['ok'];
    }
}

$steps = AIDiagnostics::nextSteps($s);
if ($steps) { echo "\nNext steps:\n"; foreach ($steps as $i => $st) echo '  ' . ($i + 1) . '. ' . $st . "\n"; }
echo "\nFull guide: AI_SETUP.md   Admin page: admin/ai-setup.php\n";
exit($ok ? 0 : 1);
