<?php
/**
 * admin/ai-evaluation.php - AI evaluation & metrics (read-only).
 *
 * Shows the latest safety-eval, live-quality and benchmark results, the regression status against tests/ai/baseline.json,
 * real provider latency / failure rate per provider+model, retrieval hit rate, price groundedness and user feedback.
 * Nothing here calls a model or changes data. Figures describe engine behaviour; they are not a measure of answer helpfulness.
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['ai_manager', 'manager']);
require_once '../backend/ai/AIMetrics.php';

$days = (int)($_GET['days'] ?? 7);
$days = in_array($days, [1, 7, 30, 90], true) ? $days : 7;

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$pct = function ($v) { return $v === null ? 'n/a' : number_format($v * 100, 1) . '%'; };
$msf = function ($v) { return $v === null ? 'n/a' : number_format((float)$v, 0) . ' ms'; };

$baseline = null;
$bf = __DIR__ . '/../tests/ai/baseline.json';
if (is_file($bf)) { $j = json_decode((string)file_get_contents($bf), true); if (is_array($j)) $baseline = $j; }

$safety   = EvalRunLog::latest($dbh, 'safety_eval', 5);
$quality  = EvalRunLog::latest($dbh, 'live_quality', 5);
$bench    = EvalRunLog::latest($dbh, 'benchmark', 20);
$runsTableOk = (bool)($safety || $quality || $bench);
if (!$runsTableOk) { try { $dbh->query('SELECT 1 FROM tbl_ai_eval_runs LIMIT 1'); $runsTableOk = true; } catch (Throwable $e) { $runsTableOk = false; } }

$latestSafety = $safety[0] ?? null;
$status = $latestSafety ? AIMetrics::regressionStatus(['pass' => (int)$latestSafety['passed'], 'fail' => (int)$latestSafety['failed'], 'leaked' => (int)$latestSafety['leaked']], $baseline) : 'no_run';
$statusText = ['ok' => ['OK', 'ais-pass'], 'regressed' => ['REGRESSED', 'ais-fail'], 'no_baseline' => ['No baseline file', ''], 'no_run' => ['No run recorded yet', '']][$status];

$telemetry = AIMetrics::telemetry($dbh, $days);
$turns     = AIMetrics::turnCounts($dbh, $days);
$rates     = $turns ? AIMetrics::turnRates($turns) : null;
$feedback  = AIMetrics::feedback($dbh, max($days, 30));

// Latest benchmark file from the CLI (tests/ai/results is git-ignored; may not exist).
$benchFile = null;
$files = glob(__DIR__ . '/../tests/ai/results/benchmark-*.json') ?: [];
if ($files) { rsort($files); $j = json_decode((string)file_get_contents($files[0]), true); if (is_array($j)) { $benchFile = $j; $benchFile['_name'] = basename($files[0]); } }

$provider = defined('AI_PROVIDER') ? (string)AI_PROVIDER : '';
$pageTitle = 'AI Evaluation & Metrics';
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS Admin | AI Evaluation &amp; Metrics</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.ais-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin:18px 0;}
.ais-card{background:var(--card-bg,#fff);border:1px solid var(--border,#d7e0db);border-radius:10px;padding:16px 18px;}
.ais-num{font-size:26px;font-weight:800;line-height:1.1;color:var(--text-primary,#17231E);}
.ais-lbl{color:var(--text-secondary,#5D7481);font-size:13px;}
.ais-pass{color:#0B6B2E;font-weight:700;} .ais-fail{color:#B3302B;font-weight:700;}
.ais-note{border-left:4px solid var(--primary,#176B50);padding:10px 14px;background:var(--card-bg,#fff);border-radius:6px;margin:14px 0;color:var(--text-primary,#17231E);}
html[data-theme="dark"] .ais-pass{color:#5CC79C;} html[data-theme="dark"] .ais-fail{color:#FCA5A5;}
.ais-table > tbody > tr, .ais-table > tbody > tr > td { background: transparent !important; color: var(--text-primary,#17231E); }
.ais-table > tbody > tr:nth-of-type(odd) > td { background: rgba(23,107,80,.06) !important; }
html[data-theme="dark"] .ais-table > tbody > tr:nth-of-type(odd) > td { background: rgba(92,199,156,.08) !important; }
.ais-table td,.ais-table th{font-size:13px;vertical-align:top;} .ais-table code{background:transparent;color:var(--text-primary,#17231E);padding:0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-line-chart"></i> AI Evaluation &amp; Metrics</h2>
  <div class="ais-note">
    <strong>What these figures are:</strong> behaviour of the assistant engine, measured from real provider calls, assistant turns and thumbs feedback, plus stored results of the
    evaluation suites. <strong>What they are not:</strong> a verdict on answer helpfulness or tone, and not a production-readiness claim. Samples shown as "low sample" should not be trusted.
    Run the suites from the command line: <code>php tests/ai/run_eval.php</code>, <code>php tests/ai/live_quality.php</code>, <code>php scripts/ai_benchmark.php --save</code>.
  </div>

  <form method="get" style="margin-bottom:14px;" aria-label="Time window">
    <label for="days" class="ais-lbl">Window for live metrics:</label>
    <select id="days" name="days" class="form-control" style="display:inline-block;width:auto;" onchange="this.form.submit()">
      <?php foreach ([1 => '24 hours', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?>
        <option value="<?php echo $d; ?>"<?php echo $d === $days ? ' selected' : ''; ?>><?php echo $h($l); ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-default" type="submit">Apply</button></noscript>
  </form>

  <h3>Regression status</h3>
  <div class="ais-grid">
    <div class="ais-card"><div class="ais-num <?php echo $h($statusText[1]); ?>"><?php echo $h($statusText[0]); ?></div><div class="ais-lbl">latest safety evaluation vs <code>tests/ai/baseline.json</code></div></div>
    <div class="ais-card"><div class="ais-num"><?php echo $baseline ? (int)$baseline['min_cases'] : 'n/a'; ?></div><div class="ais-lbl">baseline: minimum cases (may only rise)</div></div>
    <?php if ($latestSafety): ?>
      <div class="ais-card"><div class="ais-num <?php echo (int)$latestSafety['failed'] ? 'ais-fail' : 'ais-pass'; ?>"><?php echo (int)$latestSafety['passed']; ?> / <?php echo (int)$latestSafety['passed'] + (int)$latestSafety['failed']; ?></div><div class="ais-lbl">cases passed &middot; <?php echo $h($latestSafety['created_at']); ?></div></div>
      <div class="ais-card"><div class="ais-num <?php echo (int)$latestSafety['leaked'] ? 'ais-fail' : 'ais-pass'; ?>"><?php echo (int)$latestSafety['leaked']; ?></div><div class="ais-lbl">unauthorised information leaked in the leakage checks</div></div>
    <?php endif; ?>
  </div>
  <?php if (!$runsTableOk): ?>
    <p class="ais-lbl">Run history needs migration 035 (<code>php database/migrate.php</code>). Use "AI Safety &amp; Evaluation" to run the suite once it is applied.</p>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Live provider calls (<?php echo $days; ?> d) &mdash; configured provider: <code><?php echo $h($provider ?: 'n/a'); ?></code></h3>
  <?php if ($telemetry === null): ?>
    <p class="ais-lbl">No telemetry table yet.</p>
  <?php elseif (!$telemetry): ?>
    <p class="ais-lbl">No provider calls recorded in this window.</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">Provider</th><th scope="col">Model</th><th scope="col">Calls</th><th scope="col">Failure rate</th><th scope="col">p50</th><th scope="col">p95</th><th scope="col">p99</th><th scope="col">Cache hits</th></tr></thead><tbody>
      <?php foreach ($telemetry as $t): ?>
        <tr><td><?php echo $h($t['provider']); ?></td><td><code><?php echo $h($t['model']); ?></code></td><td><?php echo (int)$t['calls']; ?><?php echo $t['calls'] < 30 ? ' <span class="ais-lbl">(low sample)</span>' : ''; ?></td>
            <td class="<?php echo ($t['failure_rate'] ?? 0) > 0.1 ? 'ais-fail' : ''; ?>"><?php echo $h($pct($t['failure_rate'])); ?> (<?php echo (int)$t['failures']; ?>)</td>
            <td><?php echo $h($msf($t['p50_ms'])); ?></td><td><?php echo $h($msf($t['p95_ms'])); ?></td><td><?php echo $h($msf($t['p99_ms'])); ?></td><td><?php echo (int)$t['cached']; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <p class="ais-lbl">Latency percentiles use successful, uncached calls only. Failures are counted separately.</p>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Assistant turns (<?php echo $days; ?> d)</h3>
  <?php if (!$rates || !$turns['turns']): ?>
    <p class="ais-lbl">No assistant turns recorded in this window.</p>
  <?php else: ?>
    <div class="ais-grid">
      <div class="ais-card"><div class="ais-num"><?php echo (int)$turns['turns']; ?></div><div class="ais-lbl">turns (<?php echo (int)$rates['answered']; ?> answered, <?php echo (int)$rates['guard_blocked']; ?> blocked by the guard)</div></div>
      <div class="ais-card"><div class="ais-num"><?php echo $h($pct($rates['retrieval_hit_rate'])); ?></div><div class="ais-lbl">retrieval hit rate: searched turns that returned at least one source</div></div>
      <div class="ais-card"><div class="ais-num"><?php echo $h($pct($rates['groundedness'])); ?></div><div class="ais-lbl">price groundedness: answered turns with no unverified price</div></div>
      <div class="ais-card"><div class="ais-num <?php echo ($rates['model_error_rate'] ?? 0) > 0.1 ? 'ais-fail' : ''; ?>"><?php echo $h($pct($rates['model_error_rate'])); ?></div><div class="ais-lbl">turns that ended in a model error</div></div>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">User feedback (<?php echo max($days, 30); ?> d)</h3>
  <?php if ($feedback === null): ?>
    <p class="ais-lbl">No feedback table yet.</p>
  <?php elseif (!$feedback): ?>
    <p class="ais-lbl">No thumbs feedback recorded.</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">Feature</th><th scope="col">Thumbs up</th><th scope="col">Thumbs down</th><th scope="col">Positive share</th></tr></thead><tbody>
      <?php foreach ($feedback as $f => $r): ?>
        <tr><td><code><?php echo $h($f); ?></code></td><td><?php echo (int)$r['up']; ?></td><td><?php echo (int)$r['down']; ?></td>
            <td><?php echo $h($pct($r['ratio'])); ?><?php echo $r['low_sample'] ? ' <span class="ais-lbl">(low sample)</span>' : ''; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Stored evaluation runs</h3>
  <?php $rows = array_merge($quality, $bench, $safety); usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at'])); ?>
  <?php if (!$rows): ?>
    <p class="ais-lbl">None yet. Live-quality and benchmark runs store a summary row when they finish (benchmark needs <code>--save</code>).</p>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">When</th><th scope="col">Kind</th><th scope="col">Provider / model</th><th scope="col">Passed</th><th scope="col">Failed</th><th scope="col">Leaked</th><th scope="col">Detail</th></tr></thead><tbody>
      <?php foreach (array_slice($rows, 0, 25) as $r): $m = json_decode((string)$r['metrics_json'], true) ?: []; ?>
        <tr><td><?php echo $h($r['created_at']); ?></td><td><?php echo $h($r['kind']); ?></td><td><?php echo $h(trim($r['provider'] . ' / ' . $r['model'], ' /')); ?></td>
            <td><?php echo (int)$r['passed']; ?></td><td><?php echo (int)$r['failed']; ?></td><td class="<?php echo (int)$r['leaked'] ? 'ais-fail' : ''; ?>"><?php echo (int)$r['leaked']; ?></td>
            <td><?php echo $h(implode(', ', array_map(fn($k, $v) => $k . '=' . (is_scalar($v) || $v === null ? (string)$v : json_encode($v)), array_keys(array_slice($m, 0, 4, true)), array_slice($m, 0, 4, true)))); ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>

  <h3 style="margin-top:28px;">Latest local-model benchmark</h3>
  <?php if (!$benchFile): ?>
    <p class="ais-lbl">No benchmark file. Run <code>php scripts/ai_benchmark.php</code> on a machine with Ollama (use <code>--dry-run</code> to preview). Results are saved under <code>tests/ai/results/</code>.</p>
  <?php else: ?>
    <p class="ais-lbl"><?php echo $h($benchFile['_name']); ?> &middot; <?php echo (int)($benchFile['cases'] ?? 0); ?> cases &times; <?php echo (int)($benchFile['repeat'] ?? 1); ?></p>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">Model</th><th scope="col">p50</th><th scope="col">p95</th><th scope="col">Failures</th><th scope="col">JSON valid</th><th scope="col">Price grounded</th><th scope="col">Bengali reply</th><th scope="col">Pass</th><th scope="col">tok/s</th><th scope="col">Disk</th><th scope="col">Loaded</th></tr></thead><tbody>
      <?php foreach (($benchFile['models'] ?? []) as $m => $s): ?>
        <tr><td><code><?php echo $h($m); ?></code></td><td><?php echo $h($msf($s['p50_ms'] ?? null)); ?></td><td><?php echo $h($msf($s['p95_ms'] ?? null)); ?></td>
            <td><?php echo $h($pct($s['failure_rate'] ?? null)); ?></td><td><?php echo $h($pct($s['json_valid_rate'] ?? null)); ?></td><td><?php echo $h($pct($s['grounded_rate'] ?? null)); ?></td>
            <td><?php echo $h($pct($s['bn_reply_rate'] ?? null)); ?></td><td><?php echo $h($pct($s['pass_rate'] ?? null)); ?></td><td><?php echo $h($s['tokens_per_sec'] ?? 'n/a'); ?></td>
            <td><?php echo !empty($s['size_bytes']) ? $h(number_format($s['size_bytes'] / 1048576, 0)) . ' MB' : 'n/a'; ?></td><td><?php echo !empty($s['loaded_bytes']) ? $h(number_format($s['loaded_bytes'] / 1048576, 0)) . ' MB' : 'n/a'; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php $hint = $benchFile['hint'] ?? null; if ($hint): ?>
      <p class="ais-lbl">Routing hint from these measurements: fast = <code><?php echo $h($hint['fast'] ?? 'none'); ?></code>, quality = <code><?php echo $h($hint['quality'] ?? 'none'); ?></code>. <?php echo $h($hint['note'] ?? ''); ?></p>
    <?php endif; ?>
  <?php endif; ?>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>
