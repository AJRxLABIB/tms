<?php
/**
 * admin/ai-setup.php — AI setup & connection test.
 *
 * Shows which provider the site is really using and why, what the local Ollama runtime has installed, exact next steps,
 * and a "Test connection" button that sends ONE tiny real request and shows the provider's own (sanitised) error text.
 * No API key is ever displayed. The test runs only on a CSRF-protected POST.
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['ai_manager', 'manager']);
require_once '../backend/ai/AIClient.php';
require_once '../backend/ai/AIDiagnostics.php';

$probe = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    tms_csrf_validate();
    $probe = AIDiagnostics::probe($dbh, 45);
    tms_log_activity($dbh, $_SESSION['alogin'], 'AI Connection Test', ($probe['ok'] ? 'ok ' : 'failed ') . $probe['provider'] . ' ' . $probe['latency_ms'] . 'ms');
}
AIClient::resetResolution();
$s = AIDiagnostics::state();
$sel = $s['selected'];
$steps = AIDiagnostics::nextSteps($s);
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$sizeMb = fn($b) => $b > 0 ? number_format($b / 1048576, 0) . ' MB' : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TourBuddy Admin | AI Setup</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.aisu-card{background:var(--card-bg,#fff);border:1px solid var(--border,#d7e0db);border-radius:10px;padding:16px 18px;margin:14px 0;}
.aisu-ok{color:#0B6B2E;font-weight:700;} .aisu-bad{color:#B3302B;font-weight:700;} .aisu-warn{color:#8A5A00;font-weight:700;}
html[data-theme="dark"] .aisu-ok{color:#5CC79C;} html[data-theme="dark"] .aisu-bad{color:#FCA5A5;} html[data-theme="dark"] .aisu-warn{color:#F4C26B;}
.aisu-lbl{color:var(--text-secondary,#5D7481);font-size:13px;}
.aisu-card code,.aisu-pre{background:rgba(23,107,80,.08);color:var(--text-primary,#17231E);padding:2px 6px;border-radius:4px;}
.aisu-pre{display:block;padding:10px 12px;margin:6px 0;white-space:pre-wrap;word-break:break-word;}
.aisu-table td,.aisu-table th{font-size:13px;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-plug"></i> AI Setup &amp; Connection Test</h2>
  <p class="aisu-lbl">What the assistant, Explore and the planners are actually using right now, and how to fix it. Customers never see this page: when no AI is available they get catalogue-based answers instead of an error.</p>

  <div class="aisu-card">
    <h4>Status</h4>
    <p>
      <?php if ($sel['provider'] === 'none'): ?>
        <span class="aisu-bad"><i class="fa fa-times-circle"></i> AI is OFF</span>
      <?php else: ?>
        <span class="aisu-ok"><i class="fa fa-check-circle"></i> Using <?php echo $h($sel['provider']); ?><?php echo $sel['model'] !== '' ? ' (' . $h($sel['model']) . ')' : ''; ?></span>
      <?php endif; ?>
      &mdash; <?php echo $h(AIProviderResolver::explain($sel['reason'], true)); ?>
    </p>
    <p class="aisu-lbl"><?php echo $h($sel['detail']); ?></p>
    <table class="table aisu-table" style="max-width:640px;">
      <tr><th scope="row">AI_PROVIDER in .env</th><td><code><?php echo $h($s['provider_setting']); ?></code> <span class="aisu-lbl">(auto = local Ollama first, then a valid Gemini key, else off)</span></td></tr>
      <tr><th scope="row">Ollama</th><td><?php echo $s['ollama_reachable'] ? '<span class="aisu-ok">reachable</span>' : '<span class="aisu-warn">not reachable</span>'; ?> at <code><?php echo $h($s['ollama_base']); ?></code></td></tr>
      <tr><th scope="row">GEMINI_API_KEY</th><td><?php echo !$s['gemini_key_set'] ? 'not set' : ($s['gemini_key_valid'] ? '<span class="aisu-ok">set, valid format</span>' : '<span class="aisu-bad">set, but not a valid Google AI Studio key</span>'); ?> <span class="aisu-lbl">(value is never shown)</span></td></tr>
      <tr><th scope="row">Wanted chat model</th><td><code><?php echo $h($s['wanted_model']); ?></code> <?php echo $s['ollama_reachable'] ? ($s['wanted_installed'] ? '<span class="aisu-ok">installed</span>' : '<span class="aisu-warn">not installed</span>') : ''; ?></td></tr>
      <tr><th scope="row">Embedding model</th><td><?php echo $s['embed_model'] === '' ? 'none (optional)' : '<code>' . $h($s['embed_model']) . '</code> ' . ($s['embed_installed'] ? '<span class="aisu-ok">installed</span>' : '<span class="aisu-warn">not installed</span>'); ?></td></tr>
    </table>
    <?php if ($s['ollama_models']): ?>
      <p class="aisu-lbl" style="margin-bottom:4px;">Installed in Ollama:</p>
      <ul><?php foreach ($s['ollama_models'] as $m): ?><li><code><?php echo $h($m['name']); ?></code> <span class="aisu-lbl"><?php echo $h($sizeMb($m['size'])); ?><?php echo AIProviderResolver::isEmbeddingModel($m['name']) ? ' · embedding model' : ' · chat model'; ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <div class="aisu-card">
    <h4>Test connection</h4>
    <form method="post" style="margin-bottom:10px;">
      <?php tms_csrf_field(); ?>
      <button type="submit" name="test_connection" value="1" class="btn btn-primary" <?php echo $sel['provider'] === 'none' ? 'disabled' : ''; ?>><i class="fa fa-bolt"></i> Send one test request</button>
      <span class="aisu-lbl" style="margin-left:8px;">Sends a 1-word prompt (no customer data). The first call after starting Ollama can take 10&ndash;40 seconds while the model loads.</span>
    </form>
    <?php if ($probe): ?>
      <div role="status" aria-live="polite">
        <?php if ($probe['ok']): ?>
          <p class="aisu-ok"><i class="fa fa-check-circle"></i> It works: <?php echo $h($probe['provider']); ?> / <?php echo $h($probe['model']); ?> answered in <?php echo (int)$probe['latency_ms']; ?> ms.</p>
          <?php if ($probe['latency_ms'] > 8000): ?><p class="aisu-warn">That is slow. After the model is loaded it should be faster; if not, use a smaller model (qwen2.5:1.5b-instruct) or a machine with a GPU.</p><?php endif; ?>
        <?php else: ?>
          <p class="aisu-bad"><i class="fa fa-times-circle"></i> The test failed (<?php echo $h($probe['provider']); ?><?php echo $probe['http'] ? ', HTTP ' . (int)$probe['http'] : ''; ?>, <?php echo (int)$probe['latency_ms']; ?> ms)</p>
          <code class="aisu-pre"><?php echo $h($probe['error']); ?></code>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($steps): ?>
  <div class="aisu-card">
    <h4>What to do next</h4>
    <ol><?php foreach ($steps as $st): ?><li style="margin-bottom:6px;"><?php echo $h($st); ?></li><?php endforeach; ?></ol>
  </div>
  <?php endif; ?>

  <div class="aisu-card">
    <h4>Install the local AI on Windows / XAMPP (about 10 minutes)</h4>
    <ol>
      <li>Download and install Ollama from <code>https://ollama.com/download/windows</code>. It starts automatically and sits in the system tray.</li>
      <li>Open PowerShell and pull the chat model (about 2 GB): <code class="aisu-pre">ollama pull qwen2.5:3b-instruct</code></li>
      <li>Optional, for better search (and Bengali): <code class="aisu-pre">ollama pull bge-m3</code> then add <code>AI_EMBED_MODEL=bge-m3</code> to <code>.env</code> and run <code>php scripts/sync_embeddings.php</code>.</li>
      <li>Leave <code>AI_PROVIDER</code> unset (or <code>auto</code>) in <code>.env</code>. Remove a wrong <code>GEMINI_API_KEY</code> line.</li>
      <li>Reload this page, then press <strong>Send one test request</strong>.</li>
    </ol>
    <p class="aisu-lbl">Command line version of this page: <code>php scripts/ai_setup_check.php --test</code>. Full guide: <code>AI_SETUP.md</code>.</p>
  </div>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>
