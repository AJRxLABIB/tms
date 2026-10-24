<?php
/**
 * admin/workflow-rules.php — manage operational workflow rules (RulesEngine).
 *
 * Rules are built ONLY from predefined triggers and predefined actions (backend/services/RulesEngine.php). There is
 * no field here that accepts SQL, shell commands or code. New rules are created DISABLED; run a dry run to see what
 * a rule would do before enabling it. Every run is recorded in tbl_workflow_runs. Super-admin only.
 */
require_once __DIR__ . '/../backend/session-boot.php';
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, []);                 // empty list: only superadmin passes (see tms_admin_can)
require_once '../backend/services/RulesEngine.php';

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$actor = (string)($_SESSION['alogin'] ?? 'admin');
$flash = ''; $flashErr = []; $dryResult = null; $dryRuleId = 0;

$installed = true;
try { $dbh->query('SELECT 1 FROM tbl_workflow_rules LIMIT 1'); $dbh->query('SELECT 1 FROM tbl_workflow_runs LIMIT 1'); }
catch (Throwable $e) { $installed = false; }

$triggerHelp = [
    'overdue_refund'            => 'A refund request has waited longer than "days".',
    'incomplete_travel_details' => 'A confirmed trip starts within "days_before" days but traveller details are incomplete.',
    'supplier_sync_failure'     => 'A supplier inventory sync failed or is stale (uses "lookback_hours").',
    'repeated_failed_job'       => 'A background job failed at least "min_attempts" times.',
    'booking_status_change'     => 'A booking changed status within the last "lookback_hours".',
];
$actionHelp = [
    'notify_admin' => 'Writes an operations notice for admins.', 'notify_customer' => 'Sends the customer an in-app notice.',
    'notify_supplier' => 'Notifies the supplier concerned.', 'escalate' => 'Raises a high-severity operations notice.',
];

if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $rid = (int)($_POST['rule_id'] ?? 0);
    if (isset($_POST['create'])) {
        $errs = [];
        $id = RulesEngine::createRule($dbh, [
            'name' => (string)($_POST['name'] ?? ''), 'trigger_type' => (string)($_POST['trigger_type'] ?? ''), 'action_type' => (string)($_POST['action_type'] ?? ''),
            'window_minutes' => (int)($_POST['window_minutes'] ?? 1440), 'max_attempts' => (int)($_POST['max_attempts'] ?? 3),
            // Empty boxes are dropped (an empty string would otherwise be clamped to 1 by the validator).
            'params' => array_filter([
                'days' => $_POST['p_days'] ?? null, 'days_before' => $_POST['p_days_before'] ?? null,
                'min_attempts' => $_POST['p_min_attempts'] ?? null, 'lookback_hours' => $_POST['p_lookback_hours'] ?? null,
                'message' => $_POST['p_message'] ?? null,
            ], function ($v) { return $v !== null && trim((string)$v) !== ''; }),
        ], $actor, $errs);
        if ($id) { $flash = 'Rule created (disabled). Run a dry run, then enable it.'; tms_log_activity($dbh, $actor, 'Workflow rule created', 'rule #' . $id, 'workflow_rule', $id); }
        else { $flashErr = $errs ?: ['Could not create the rule.']; }
    } elseif (isset($_POST['enable']) || isset($_POST['disable'])) {
        $on = isset($_POST['enable']);
        RulesEngine::setEnabled($dbh, $rid, $on);
        tms_log_activity($dbh, $actor, $on ? 'Workflow rule enabled' : 'Workflow rule disabled', 'rule #' . $rid, 'workflow_rule', $rid);
        $flash = $on ? 'Rule enabled. It runs when the scheduled evaluator runs.' : 'Rule disabled.';
    } elseif (isset($_POST['dry_run'])) {
        $dryRuleId = $rid;
        try { $dryResult = RulesEngine::evaluate($dbh, true, $rid); }
        catch (Throwable $e) { error_log('[TMS workflow-rules] dry run: ' . $e->getMessage()); $flashErr = ['Dry run failed (see error log).']; }
        tms_log_activity($dbh, $actor, 'Workflow rule dry run', 'rule #' . $rid, 'workflow_rule', $rid);
    }
}

$rules = []; $runs = []; $dryRows = [];
if ($installed) {
    try {
        $rules = $dbh->query('SELECT * FROM tbl_workflow_rules ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        $runs = $dbh->query('SELECT r.*, w.name AS rule_name FROM tbl_workflow_runs r LEFT JOIN tbl_workflow_rules w ON w.id = r.rule_id ORDER BY r.id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
        if ($dryRuleId) {
            $q = $dbh->prepare("SELECT subject_key, detail, created_at FROM tbl_workflow_runs WHERE rule_id = ? AND dry_run = 1 ORDER BY id DESC LIMIT 20");
            $q->execute([$dryRuleId]); $dryRows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { error_log('[TMS workflow-rules] ' . $e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Admin | Workflow Rules</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.wr-note{border-left:4px solid var(--primary,#176B50);padding:10px 14px;background:var(--card-bg,#fff);border-radius:6px;margin:12px 0;color:var(--text-primary,#17231E);}
.wr-table td,.wr-table th{font-size:13px;vertical-align:top;}
.wr-box{border:1px solid rgba(23,107,80,.25);border-radius:8px;padding:14px;margin:14px 0;background:var(--card-bg,#fff);color:var(--text-primary,#17231E);}
.wr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-cogs"></i> Workflow Rules</h2>
  <div class="wr-note">
    Rules pick from <strong>fixed triggers and fixed actions</strong>; they cannot run SQL, shell commands or code. New rules start disabled.
    Use <strong>Dry run</strong> to see exactly what a rule would do before you enable it. Each rule acts at most once per subject per time window and retries a limited number of times.
  </div>

  <?php if (!$installed): ?>
    <div class="alert alert-warning">Workflow tables are not installed. Run <code>php database/migrate.php</code> (migration 017).</div>
  <?php else: ?>
    <?php if ($flash): ?><div class="alert alert-success" role="status"><?php echo $h($flash); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="alert alert-danger" role="alert"><?php echo $h(implode(' · ', $flashErr)); ?></div><?php endif; ?>

    <?php if ($dryResult !== null): ?>
      <div class="wr-box">
        <h4 style="margin-top:0;">Dry run result for rule #<?php echo (int)$dryRuleId; ?> (nothing was sent or changed)</h4>
        <p>Matched <strong><?php echo (int)$dryResult['matched']; ?></strong> · would act on <strong><?php echo (int)$dryResult['dry']; ?></strong> ·
           already handled this window <?php echo (int)$dryResult['skipped']; ?> · failed <?php echo (int)$dryResult['failed']; ?></p>
        <?php if ($dryRows): ?>
          <div class="table-responsive"><table class="table wr-table"><thead><tr><th scope="col">Subject</th><th scope="col">Would do</th><th scope="col">When</th></tr></thead><tbody>
          <?php foreach ($dryRows as $d): ?><tr><td><?php echo $h($d['subject_key']); ?></td><td><?php echo $h($d['detail']); ?></td><td><?php echo $h($d['created_at']); ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php else: ?><p class="text-muted">No matching subjects right now.</p><?php endif; ?>
      </div>
    <?php endif; ?>

    <h3>Rules</h3>
    <div class="table-responsive">
    <table class="table table-striped wr-table">
      <thead><tr><th scope="col">#</th><th scope="col">Name</th><th scope="col">When</th><th scope="col">Then</th><th scope="col">Window</th><th scope="col">State</th><th scope="col">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo $h($r['name']); ?><br><small class="text-muted">by <?php echo $h($r['created_by']); ?></small></td>
          <td><code><?php echo $h($r['trigger_type']); ?></code><br><small><?php echo $h($triggerHelp[$r['trigger_type']] ?? ''); ?></small><br><small class="text-muted"><?php echo $h($r['params']); ?></small></td>
          <td><code><?php echo $h($r['action_type']); ?></code></td>
          <td><?php echo (int)$r['window_minutes']; ?> min<br><small>max <?php echo (int)$r['max_attempts']; ?> attempts</small></td>
          <td><?php echo $r['enabled'] ? '<strong style="color:#176B50">Enabled</strong>' : 'Disabled'; ?></td>
          <td>
            <form method="post" style="display:inline;">
              <?php tms_csrf_field(); ?><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
              <button class="btn btn-default btn-sm" name="dry_run" value="1">Dry run</button>
              <?php if ($r['enabled']): ?><button class="btn btn-warning btn-sm" name="disable" value="1">Disable</button>
              <?php else: ?><button class="btn btn-success btn-sm" name="enable" value="1" onclick="return confirm('Enable this rule? It will act on matching subjects when the evaluator runs.');">Enable</button><?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rules): ?><tr><td colspan="7" class="text-center text-muted">No rules yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <h3 style="margin-top:28px;">New rule</h3>
    <form method="post" class="wr-box">
      <?php tms_csrf_field(); ?>
      <div class="wr-grid">
        <div><label for="wr-name">Name</label><input id="wr-name" name="name" class="form-control" maxlength="120" required></div>
        <div><label for="wr-trigger">When (trigger)</label>
          <select id="wr-trigger" name="trigger_type" class="form-control" required>
            <?php foreach (RulesEngine::TRIGGERS as $t): ?><option value="<?php echo $h($t); ?>"><?php echo $h($t); ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="wr-action">Then (action)</label>
          <select id="wr-action" name="action_type" class="form-control" required>
            <?php foreach (RulesEngine::ACTIONS as $a): ?><option value="<?php echo $h($a); ?>"><?php echo $h($a); ?> — <?php echo $h($actionHelp[$a] ?? ''); ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="wr-window">Act once per subject per (minutes)</label><input id="wr-window" name="window_minutes" type="number" min="5" max="43200" value="1440" class="form-control"></div>
        <div><label for="wr-max">Max attempts</label><input id="wr-max" name="max_attempts" type="number" min="1" max="10" value="3" class="form-control"></div>
        <div><label for="wr-days">days</label><input id="wr-days" name="p_days" type="number" min="1" max="365" class="form-control"></div>
        <div><label for="wr-before">days_before</label><input id="wr-before" name="p_days_before" type="number" min="1" max="365" class="form-control"></div>
        <div><label for="wr-minatt">min_attempts</label><input id="wr-minatt" name="p_min_attempts" type="number" min="1" max="365" class="form-control"></div>
        <div><label for="wr-look">lookback_hours</label><input id="wr-look" name="p_lookback_hours" type="number" min="1" max="365" class="form-control"></div>
      </div>
      <p style="margin-top:10px;"><label for="wr-msg">Message (optional; placeholders only, plain text, max 300)</label>
        <input id="wr-msg" name="p_message" class="form-control" maxlength="300"></p>
      <button class="btn btn-primary" name="create" value="1">Create (disabled)</button>
      <small class="text-muted" style="margin-left:8px;">Unused parameter boxes are ignored.</small>
    </form>

    <h3 style="margin-top:28px;">Recent runs</h3>
    <div class="table-responsive">
    <table class="table table-striped wr-table">
      <thead><tr><th scope="col">When</th><th scope="col">Rule</th><th scope="col">Subject</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Detail</th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): ?>
        <tr><td><?php echo $h($r['created_at']); ?></td><td>#<?php echo (int)$r['rule_id']; ?> <?php echo $h($r['rule_name']); ?></td><td><?php echo $h($r['subject_key']); ?></td>
            <td><?php echo $h($r['status']); ?><?php echo $r['dry_run'] ? ' (dry)' : ''; ?></td><td><?php echo (int)$r['attempts']; ?></td><td><?php echo $h($r['detail']); ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$runs): ?><tr><td colspan="6" class="text-center text-muted">No runs recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
</main>
</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
