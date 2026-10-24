<?php
/**
 * admin/fraud-cases.php — review queue for transparent anomaly signals.
 *
 * A case is a prompt for a human to look, with the evidence shown. Nothing here (or anywhere) automatically bans,
 * blocks, refunds or cancels. "Confirmed" records a reviewer's finding; any action is taken separately by a person.
 * Customers can appeal; appeals are answered here and an upheld appeal reopens the case.
 */
require_once __DIR__ . '/../backend/session-boot.php';
require('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['manager', 'operations']);
require_once '../backend/services/FraudCases.php';

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$actor = (string)($_SESSION['alogin'] ?? 'admin');
$installed = FraudCases::installed($dbh);
$flash = ''; $flashErr = '';

if ($installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $caseId = (int)($_POST['case_id'] ?? 0);
    if (isset($_POST['decide'])) {
        $err = FraudCases::decide($dbh, $caseId, $actor, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''));
        if ($err) { $flashErr = $err; } else {
            $flash = 'Decision recorded.';
            tms_log_activity($dbh, $actor, 'Fraud case decision', 'case #' . $caseId . ' -> ' . (string)($_POST['decision'] ?? ''), 'fraud_case', $caseId);
        }
    } elseif (isset($_POST['appeal_response'])) {
        $err = FraudCases::respondToAppeal($dbh, (int)($_POST['appeal_id'] ?? 0), $actor, (string)($_POST['outcome'] ?? ''), (string)($_POST['response'] ?? ''));
        if ($err) { $flashErr = $err; } else {
            $flash = 'Appeal answered.';
            tms_log_activity($dbh, $actor, 'Fraud appeal answered', 'case #' . $caseId . ' appeal #' . (int)($_POST['appeal_id'] ?? 0), 'fraud_case', $caseId);
        }
    }
}

$statusFilter = in_array($_GET['status'] ?? '', FraudCases::STATUSES, true) ? $_GET['status'] : '';
$viewId = (int)($_GET['id'] ?? ($_POST['case_id'] ?? 0));
$cases = []; $counts = []; $case = null; $history = []; $appeals = [];
$openAppeals = [];
if ($installed) {
    try {
        foreach ($dbh->query('SELECT status, COUNT(*) n FROM tbl_fraud_cases GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['status']] = (int)$r['n'];
        $q = $dbh->prepare('SELECT * FROM tbl_fraud_cases ' . ($statusFilter ? 'WHERE status = :s ' : "WHERE status IN ('open','reviewing') ") . 'ORDER BY score DESC, id DESC LIMIT 200');
        $statusFilter ? $q->execute([':s' => $statusFilter]) : $q->execute();
        $cases = $q->fetchAll(PDO::FETCH_ASSOC);
        try { $openAppeals = $dbh->query("SELECT id, case_id, user_email, created_at FROM tbl_fraud_appeals WHERE status = 'open' ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $openAppeals = []; }
        if ($viewId) {
            $c = $dbh->prepare('SELECT * FROM tbl_fraud_cases WHERE id = ?'); $c->execute([$viewId]); $case = $c->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($case) {
                $d = $dbh->prepare('SELECT * FROM tbl_fraud_case_decisions WHERE case_id = ? ORDER BY id DESC'); $d->execute([$viewId]); $history = $d->fetchAll(PDO::FETCH_ASSOC);
                $a = $dbh->prepare('SELECT * FROM tbl_fraud_appeals WHERE case_id = ? ORDER BY id DESC'); $a->execute([$viewId]); $appeals = $a->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) { error_log('[TMS fraud-cases] ' . $e->getMessage()); $flashErr = $flashErr ?: 'Could not load cases.'; }
}
$sevClass = ['high' => '#B3302B', 'medium' => '#B7791F', 'low' => '#2F6F5E'];
?>
<!DOCTYPE html>
<html lang="<?php echo $h(tms_active_language()); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Admin | Fraud Review</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.fc-note{border-left:4px solid var(--primary,#176B50);padding:10px 14px;background:var(--card-bg,#fff);border-radius:6px;margin:12px 0;color:var(--text-primary,#17231E);}
.fc-table td,.fc-table th{font-size:13px;vertical-align:top;} .fc-badge{font-weight:700;}
.fc-pre{white-space:pre-wrap;word-break:break-word;font-size:12px;background:rgba(23,107,80,.06);padding:8px;border-radius:6px;color:var(--text-primary,#17231E);}
.fc-box{border:1px solid rgba(23,107,80,.25);border-radius:8px;padding:14px;margin:14px 0;background:var(--card-bg,#fff);color:var(--text-primary,#17231E);}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
  <h2><i class="fa fa-search"></i> Fraud &amp; Anomaly Review</h2>
  <div class="fc-note">
    Cases are <strong>prompts for a person to look</strong>, built from fixed, visible rules. A score is a priority for investigation, not proof of wrongdoing.
    Nothing is blocked, banned or cancelled automatically. Check the evidence, record your decision and a reason, and handle any customer appeal.
  </div>

  <?php if (!$installed): ?>
    <div class="alert alert-warning">Fraud review tables are not installed. Run <code>php database/migrate.php</code> (migration 022), then <code>php scripts/scan_fraud_signals.php --apply</code>.</div>
  <?php else: ?>
    <?php if ($flash): ?><div class="alert alert-success" role="status"><?php echo $h($flash); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="alert alert-danger" role="alert"><?php echo $h($flashErr); ?></div><?php endif; ?>

    <p>
      <?php foreach (FraudCases::STATUSES as $s): ?>
        <a class="btn btn-default btn-sm" href="fraud-cases.php?status=<?php echo $h($s); ?>"><?php echo $h(ucfirst($s)); ?> (<?php echo (int)($counts[$s] ?? 0); ?>)</a>
      <?php endforeach; ?>
      <a class="btn btn-link btn-sm" href="fraud-cases.php">Open + reviewing</a>
    </p>

    <?php if ($openAppeals): ?>
      <div class="alert alert-warning" role="status">
        <strong><?php echo count($openAppeals); ?> open customer appeal(s) waiting for a reviewer:</strong>
        <?php foreach ($openAppeals as $oa): ?>
          <a href="fraud-cases.php?id=<?php echo (int)$oa['case_id']; ?>">case #<?php echo (int)$oa['case_id']; ?></a> (<?php echo $h(substr((string)$oa['created_at'], 0, 10)); ?>)&nbsp;
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($case): ?>
      <div class="fc-box">
        <h3 style="margin-top:0;">Case #<?php echo (int)$case['id']; ?> — <?php echo $h(FraudCases::label($case['signal'])); ?></h3>
        <p><strong>Subject:</strong> <?php echo $h($case['subject_key']); ?> &middot; <strong>Scan day:</strong> <?php echo $h($case['day_key']); ?> &middot;
           <strong>Status:</strong> <?php echo $h($case['status']); ?> &middot;
           <span class="fc-badge" style="color:<?php echo $sevClass[$case['severity']] ?? '#333'; ?>"><?php echo $h(strtoupper($case['severity'])); ?> priority <?php echo (int)$case['score']; ?>/100</span></p>
        <p><strong>Why it was flagged:</strong> <?php echo $h($case['reason']); ?></p>
        <p><strong>Evidence (exact figures the rule used):</strong></p>
        <div class="fc-pre"><?php echo $h(json_encode(json_decode((string)$case['evidence'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></div>

        <form method="post" style="margin-top:14px;">
          <?php tms_csrf_field(); ?>
          <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
          <label for="fc-decision"><strong>Record a decision</strong></label>
          <select name="decision" id="fc-decision" class="form-control" style="max-width:260px;display:inline-block;">
            <option value="reviewing">Mark as under review</option>
            <option value="cleared">Cleared — legitimate</option>
            <option value="confirmed">Confirmed — finding for follow-up</option>
            <option value="note">Add a note only</option>
          </select>
          <label for="fc-note" class="sr-only">Note</label>
          <input type="text" name="note" id="fc-note" class="form-control" maxlength="1000" placeholder="Reason (required to clear or confirm)" style="max-width:520px;display:inline-block;">
          <button class="btn btn-primary" name="decide" value="1">Save</button>
        </form>

        <?php if ($appeals): ?>
          <h4 style="margin-top:20px;">Customer appeals</h4>
          <?php foreach ($appeals as $ap): ?>
            <div class="fc-pre" style="margin-bottom:8px;">
              <strong><?php echo $h($ap['user_email']); ?></strong> — <?php echo $h($ap['created_at']); ?> — <em><?php echo $h($ap['status']); ?></em><br>
              <?php echo $h($ap['message']); ?>
              <?php if ($ap['response']): ?><br><strong>Response (<?php echo $h($ap['responded_by']); ?>):</strong> <?php echo $h($ap['response']); ?><?php endif; ?>
            </div>
            <?php if ($ap['status'] === 'open'): ?>
              <form method="post" style="margin-bottom:12px;">
                <?php tms_csrf_field(); ?>
                <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                <input type="hidden" name="appeal_id" value="<?php echo (int)$ap['id']; ?>">
                <select name="outcome" class="form-control" style="max-width:240px;display:inline-block;" aria-label="Appeal outcome">
                  <option value="upheld">Uphold appeal (reopen case)</option>
                  <option value="dismissed">Dismiss appeal</option>
                </select>
                <input type="text" name="response" class="form-control" maxlength="1000" required placeholder="Response to the customer (required)" style="max-width:520px;display:inline-block;" aria-label="Response">
                <button class="btn btn-default" name="appeal_response" value="1">Send response</button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>

        <h4 style="margin-top:20px;">Decision history</h4>
        <?php if (!$history): ?><p class="text-muted">No decisions yet.</p><?php else: ?>
          <div class="table-responsive"><table class="table fc-table"><thead><tr><th scope="col">When</th><th scope="col">Reviewer</th><th scope="col">Decision</th><th scope="col">Note</th></tr></thead><tbody>
          <?php foreach ($history as $d): ?><tr><td><?php echo $h($d['created_at']); ?></td><td><?php echo $h($d['reviewer']); ?></td><td><?php echo $h($d['decision']); ?></td><td><?php echo $h($d['note']); ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table table-striped fc-table">
      <thead><tr><th scope="col">#</th><th scope="col">Priority</th><th scope="col">Signal</th><th scope="col">Subject</th><th scope="col">Why</th><th scope="col">Status</th><th scope="col">Scan day</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($cases as $c): ?>
        <tr>
          <td><?php echo (int)$c['id']; ?></td>
          <td><span class="fc-badge" style="color:<?php echo $sevClass[$c['severity']] ?? '#333'; ?>"><?php echo $h(strtoupper($c['severity'])); ?> <?php echo (int)$c['score']; ?></span></td>
          <td><?php echo $h(FraudCases::label($c['signal'])); ?></td>
          <td><?php echo $h($c['subject_key']); ?></td>
          <td><?php echo $h($c['reason']); ?></td>
          <td><?php echo $h($c['status']); ?></td>
          <td><?php echo $h($c['day_key']); ?></td>
          <td><a href="fraud-cases.php?id=<?php echo (int)$c['id']; ?><?php echo $statusFilter ? '&amp;status=' . $h($statusFilter) : ''; ?>">Review</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$cases): ?><tr><td colspan="8" class="text-center text-muted">No cases in this view. Cases are created by <code>scripts/scan_fraud_signals.php --apply</code>.</td></tr><?php endif; ?>
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
