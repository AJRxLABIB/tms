<?php
/**
 * admin/ai-safety.php - AI safety & evaluation.
 *
 * Shows (1) the deterministic safety/retrieval evaluation, which an admin can
 * re-run on demand (no model call, no network, no cost, fixture data only), and
 * (2) the real tool-call audit trail written by ToolRegistry.
 *
 * What this page does NOT show: answer quality. Measuring that needs a real model
 * (see tests/ai/live_quality.php).
 */
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['ai_manager', 'manager']);
require_once '../backend/ai/SafetyEval.php';

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_safety_eval'])) {
    tms_csrf_validate();
    $started = microtime(true);
    $result = SafetyEval::run(__DIR__ . '/../tests/ai/eval_cases.json');
    $result['ms'] = (int)round((microtime(true) - $started) * 1000);
    $result['at'] = date('Y-m-d H:i:s');
    tms_log_activity($dbh, $_SESSION['alogin'], 'AI Safety Eval Run', $result['pass'] . ' passed, ' . $result['fail'] . ' failed, ' . $result['leaked'] . ' leaked');
    // Keep a history for admin/ai-evaluation.php (best effort; needs migration 035).
    require_once __DIR__ . '/../backend/ai/AIMetrics.php';
    EvalRunLog::save($dbh, 'safety_eval', $result['pass'], $result['fail'], $result['leaked'], ['ms' => $result['ms'], 'leak_checks' => $result['leak_checks']], '', '', 'run from admin');
}

// Tool audit (table is created lazily by ToolRegistry, so it may not exist yet).
$auditExists = false; $byTool = []; $recent = []; $total7 = 0; $denied7 = 0;
try {
    $dbh->query("SELECT 1 FROM tbl_ai_tool_audit LIMIT 1");
    $auditExists = true;
    $st = $dbh->query("SELECT tool, result, COUNT(*) AS n FROM tbl_ai_tool_audit
                       WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY tool, result ORDER BY n DESC");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byTool[] = $r;
        $total7 += (int)$r['n'];
        if ($r['result'] !== 'ok') $denied7 += (int)$r['n'];
    }
    $recent = $dbh->query("SELECT tool, args_json, actor, result, created_at FROM tbl_ai_tool_audit ORDER BY id DESC LIMIT 25")
                  ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* no audit rows yet */ }

$provider = defined('AI_PROVIDER') ? (string)AI_PROVIDER : 'gemini';
$model    = defined('OLLAMA_MODEL') && strtolower($provider) === 'ollama' ? (string)OLLAMA_MODEL : '';
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$pageTitle = 'AI Safety & Evaluation';
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS Admin | AI Safety &amp; Evaluation</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.ais-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:18px 0;}
.ais-card{background:var(--card-bg,#fff);border:1px solid var(--border,#d7e0db);border-radius:10px;padding:16px 18px;}
.ais-num{font-size:28px;font-weight:800;line-height:1.1;}
.ais-lbl{color:var(--text-secondary,#5D7481);font-size:13px;}
.ais-pass{color:#0B6B2E;font-weight:700;} .ais-fail{color:#B3302B;font-weight:700;}
.ais-note{border-left:4px solid var(--primary,#176B50);padding:10px 14px;background:var(--card-bg,#fff);border-radius:6px;margin:14px 0;color:var(--text-primary,#17231E);}
html[data-theme="dark"] .ais-pass{color:#5CC79C;} html[data-theme="dark"] .ais-fail{color:#FCA5A5;}
.ais-table > tbody > tr, .ais-table > tbody > tr > td { background: transparent !important; color: var(--text-primary,#17231E); }
.ais-table > tbody > tr:nth-of-type(odd) > td { background: rgba(23,107,80,.06) !important; }
html[data-theme="dark"] .ais-table > tbody > tr:nth-of-type(odd) > td { background: rgba(92,199,156,.08) !important; }
.ais-num { color: var(--text-primary,#17231E); }
.ais-table code { background: transparent; color: var(--text-primary,#17231E); padding: 0; }
.ais-table td,.ais-table th{font-size:13px;vertical-align:top;} .ais-table code{word-break:break-all;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-shield"></i> AI Safety &amp; Evaluation</h2>
  <p style="color:var(--text-secondary,#5D7481);">Checks that the guardrails around the assistant behave. See <a href="ai-dashboard.php" style="text-decoration:underline;">AI Usage Dashboard</a> for usage and the live-model suite.</p>

  <div class="ais-note">
    <strong>What this measures:</strong> prompt-injection blocking, retrieval ranking, tool routing, tool argument validation and reply sanitising, against built-in <em>test</em> data (not your real packages).
    <strong>What it does not measure:</strong> how good the model's answers are. That needs a real model run.
  </div>

  <h3>Safety evaluation</h3>
  <form method="post" style="margin-bottom:14px;">
    <?php tms_csrf_field(); ?>
    <button type="submit" name="run_safety_eval" value="1" class="btn btn-primary"><i class="fa fa-play"></i> Run evaluation now</button>
    <span class="ais-lbl" style="margin-left:10px;">Runs locally in milliseconds. No model call, no cost.</span>
  </form>

  <?php if ($result): ?>
    <div class="ais-grid">
      <div class="ais-card"><div class="ais-num <?php echo $result['fail'] ? 'ais-fail' : 'ais-pass'; ?>"><?php echo (int)$result['pass']; ?> / <?php echo (int)($result['pass'] + $result['fail']); ?></div><div class="ais-lbl">cases passed</div></div>
      <div class="ais-card"><div class="ais-num"><?php echo (int)$result['fail']; ?></div><div class="ais-lbl">failed</div></div>
      <div class="ais-card"><div class="ais-num <?php echo $result['leaked'] ? 'ais-fail' : 'ais-pass'; ?>"><?php echo (int)$result['leaked']; ?></div><div class="ais-lbl">unauthorised information leaked (of <?php echo (int)$result['leak_checks']; ?> leakage checks)</div></div>
      <div class="ais-card"><div class="ais-num"><?php echo (int)$result['ms']; ?> ms</div><div class="ais-lbl">run at <?php echo $h($result['at']); ?></div></div>
    </div>
    <div class="table-responsive">
    <table class="table table-striped ais-table">
      <thead><tr><th scope="col">Result</th><th scope="col">Case</th><th scope="col">Detail</th></tr></thead>
      <tbody>
      <?php foreach ($result['rows'] as [$ok, $id, $detail]): ?>
        <tr><td><?php echo $ok ? '<span class="ais-pass"><i class="fa fa-check"></i> PASS</span>' : '<span class="ais-fail"><i class="fa fa-times"></i> FAIL</span>'; ?></td>
            <td><code><?php echo $h($id); ?></code></td><td><?php echo $h(mb_substr($detail, 0, 160)); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <p class="ais-lbl">No run yet in this session. Results are not stored; run it after any change to the AI code.</p>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Assistant provider</h3>
  <div class="ais-grid">
    <div class="ais-card"><div class="ais-num" style="font-size:20px;"><?php echo $h($provider ?: 'gemini'); ?></div><div class="ais-lbl">AI_PROVIDER<?php echo $model ? ' &middot; model ' . $h($model) : ''; ?></div></div>
  </div>
  <p class="ais-lbl">Self-hosted means <code>AI_PROVIDER=ollama</code>. Any other value sends prompts to a third-party service.</p>

  <h3 style="margin-top:30px;">Tool-call audit (last 7 days)</h3>
  <?php if (!$auditExists || !$total7): ?>
    <p class="ais-lbl">No assistant tool calls recorded yet<?php echo $auditExists ? ' in the last 7 days' : ''; ?>. Rows appear once visitors use the site assistant.</p>
  <?php else: ?>
    <div class="ais-grid">
      <div class="ais-card"><div class="ais-num"><?php echo $total7; ?></div><div class="ais-lbl">tool calls</div></div>
      <div class="ais-card"><div class="ais-num"><?php echo $denied7; ?></div><div class="ais-lbl">rejected or errored (not ok)</div></div>
    </div>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">Tool</th><th scope="col">Result</th><th scope="col">Calls</th></tr></thead><tbody>
      <?php foreach ($byTool as $r): ?><tr><td><code><?php echo $h($r['tool']); ?></code></td><td><?php echo $h($r['result']); ?></td><td><?php echo (int)$r['n']; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <h4>Most recent 25</h4>
    <div class="table-responsive"><table class="table table-striped ais-table">
      <thead><tr><th scope="col">When</th><th scope="col">Tool</th><th scope="col">Actor</th><th scope="col">Arguments</th><th scope="col">Result</th></tr></thead><tbody>
      <?php foreach ($recent as $r): ?><tr><td><?php echo $h($r['created_at']); ?></td><td><code><?php echo $h($r['tool']); ?></code></td><td><?php echo $h($r['actor']); ?></td><td><code><?php echo $h($r['args_json']); ?></code></td><td><?php echo $h($r['result']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <p class="ais-lbl">Actors are hashed; the audit shows "same user", not who. Arguments are truncated to 500 characters.</p>
  <?php endif; ?>
</div><!-- /.tms-content -->
<?php include('includes/footer.php'); ?>
</main>
</div>
</body>
</html>
