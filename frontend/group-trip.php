<?php
/**
 * group-trip.php — one group trip: members, shared itinerary, shared
 * expenses/settlement, polls, and discussion.
 * Every action here re-checks tms_group_is_member() before touching
 * anything (Rule 44 -- no IDOR via a guessed ?id=).
 */
require('backend/config.php');
require_once('backend/GroupTripService.php');
tms_require_user();

$email = $_SESSION['login'];
$groupId = (int)($_GET['id'] ?? $_POST['group_id'] ?? 0);
$msg = ''; $msgType = 'success';

$group = $dbh->prepare("SELECT * FROM tblgroup_trips WHERE GroupId = :id");
$group->execute([':id' => $groupId]);
$group = $group->fetch(PDO::FETCH_OBJ);

if (!$group || !tms_group_is_member($dbh, $groupId, $email)) {
    header('Location: group-trips.php');
    exit;
}

$myRole = $dbh->prepare("SELECT Role FROM tblgroup_members WHERE GroupId=:g AND UserEmail=:e");
$myRole->execute([':g' => $groupId, ':e' => $email]);
$myRole = $myRole->fetchColumn() ?: 'member';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tms_csrf_validate();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_member' && $myRole === 'organizer') {
        $newEmail = trim(strtolower($_POST['member_email'] ?? ''));
        if ($newEmail !== '') {
            $result = tms_add_group_member($dbh, $groupId, $newEmail);
            $msg = $result['message']; $msgType = $result['ok'] ? 'success' : 'danger';
        }
    } elseif ($action === 'add_itinerary_item') {
        // Round 105 (master prompt item 24 -- "shared itinerary"): any
        // member can add, same reasoning as comments and polls -- planning
        // what to do is a group decision, not an organizer-only privilege.
        $dayLabel = tms_clean($_POST['day_label'] ?? '');
        $activity = tms_clean($_POST['activity'] ?? '');
        $added = tms_add_itinerary_item($dbh, $groupId, $email, $dayLabel, $activity);
        $msg = $added ? 'Itinerary item added.' : 'Please enter what you\'ll be doing.';
        $msgType = $added ? 'success' : 'danger';
    } elseif ($action === 'add_expense') {
        $description = tms_clean($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $amountMinor = (int) round($amount * 100);
        if ($description !== '' && $amountMinor > 0) {
            tms_add_group_expense($dbh, $groupId, $email, $description, $amountMinor);
            $msg = 'Expense added.';
        } else {
            $msg = 'Please enter a description and a positive amount.'; $msgType = 'danger';
        }
    } elseif ($action === 'add_comment') {
        // Round 103 (master prompt item 24 -- "comments"): any member (not
        // just the organizer) can post -- a group discussion isn't an
        // organizer-only action, unlike adding members.
        $commentText = tms_clean($_POST['comment'] ?? '');
        if ($commentText !== '') {
            $posted = tms_add_group_comment($dbh, $groupId, $email, $commentText);
            $msg = $posted ? 'Comment posted.' : 'Could not post comment — please try a shorter message.';
            $msgType = $posted ? 'success' : 'danger';
        } else {
            $msg = 'Please enter a comment.'; $msgType = 'danger';
        }
    } elseif ($action === 'create_poll') {
        // Round 104 (master prompt item 24 -- "voting"): any member can
        // start a poll, same reasoning as comments -- deciding what to vote
        // on is itself a group decision, not an organizer-only privilege.
        $question = tms_clean($_POST['question'] ?? '');
        $options = [];
        for ($i = 1; $i <= 5; $i++) {
            $options[] = tms_clean($_POST['option_' . $i] ?? '');
        }
        $newPollId = tms_create_group_poll($dbh, $groupId, $question, $options, $email);
        if ($newPollId > 0) {
            $msg = 'Poll created.';
        } else {
            $msg = 'Please enter a question and at least 2 different options.'; $msgType = 'danger';
        }
    } elseif ($action === 'cast_vote') {
        $pollId = (int)($_POST['poll_id'] ?? 0);
        $optionId = (int)($_POST['option_id'] ?? 0);
        $voted = $pollId > 0 && $optionId > 0 && tms_cast_group_vote($dbh, $groupId, $pollId, $optionId, $email);
        $msg = $voted ? 'Vote recorded.' : 'Could not record your vote — please try again.';
        $msgType = $voted ? 'success' : 'danger';
    }
}

$members = $dbh->prepare("SELECT UserEmail, Role FROM tblgroup_members WHERE GroupId=:g ORDER BY Role, UserEmail");
$members->execute([':g' => $groupId]);
$members = $members->fetchAll(PDO::FETCH_OBJ);

$expenses = $dbh->prepare("SELECT * FROM tblgroup_expenses WHERE GroupId=:g ORDER BY CreatedAt DESC");
$expenses->execute([':g' => $groupId]);
$expenses = $expenses->fetchAll(PDO::FETCH_OBJ);

$settlement = tms_compute_group_settlement($dbh, $groupId);
$comments = tms_get_group_comments($dbh, $groupId);
$polls = tms_get_group_polls($dbh, $groupId, $email);
$itinerary = tms_get_group_itinerary($dbh, $groupId);
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<title>TMS | <?php echo htmlspecialchars($group->Name, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;}
.gt-box{background:var(--white);border-radius:12px;padding:24px;box-shadow:0 3px 15px rgba(0,0,0,0.08);margin-bottom:24px;}
.gt-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.gt-row:last-child{border-bottom:none;}
.balance-pos{color:#27ae60;font-weight:700;}
.balance-neg{color:#e74c3c;font-weight:700;}
.gt-comment{padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;}
.gt-comment:last-child{border-bottom:none;}
.gt-comment-meta{color:#94a3b8;font-size:12px;margin-bottom:2px;}
.gt-comment-text{white-space:pre-wrap;word-break:break-word;}
.gt-poll{padding:14px 0;border-bottom:1px solid #eef1f6;}
.gt-poll:last-child{border-bottom:none;}
.gt-poll-q{font-weight:700;margin-bottom:8px;}
.gt-poll-opt{margin-bottom:8px;}
.gt-poll-opt-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px;}
.gt-poll-bar-bg{background:#eef1f6;border-radius:6px;height:8px;overflow:hidden;}
.gt-poll-bar-fill{background:var(--primary);height:100%;}
.gt-poll-mine{outline:2px solid var(--primary);outline-offset:2px;border-radius:6px;}
.gt-poll-meta{color:#94a3b8;font-size:12px;margin-top:6px;}
.gt-itin{padding:10px 0;border-bottom:1px solid #eef1f6;font-size:14px;display:flex;gap:10px;align-items:baseline;}
.gt-itin:last-child{border-bottom:none;}
.gt-itin-day{flex-shrink:0;font-weight:700;color:var(--primary);min-width:70px;}
.gt-itin-activity{flex-grow:1;}
.gt-itin-meta{color:#94a3b8;font-size:12px;}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title"><div class="container"><h2 style="margin:0;"><i class="fa fa-users"></i> <?php echo htmlspecialchars($group->Name, ENT_QUOTES, 'UTF-8'); ?></h2></div></div>
<div class="container" style="margin-bottom:60px;">
  <p><a href="group-trips.php">&larr; All group trips</a></p>
  <?php if ($msg): ?><div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="gt-box">
    <h4>Members</h4>
    <?php foreach ($members as $m): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($m->UserEmail, ENT_QUOTES, 'UTF-8'); ?></span><span class="label label-<?php echo $m->Role==='organizer'?'primary':'default'; ?>"><?php echo ucfirst($m->Role); ?></span></div>
    <?php endforeach; ?>
    <?php if ($myRole === 'organizer'): ?>
    <form method="POST" class="form-inline" style="margin-top:14px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_member">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="member_email" class="sr-only">Member email</label>
      <input type="email" id="member_email" name="member_email" class="form-control" placeholder="friend@email.com" required style="width:240px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-user-plus"></i> Add Member</button>
    </form>
    <p style="font-size:12px;color:#888;">They must already have a TMS account.</p>
    <?php endif; ?>
  </div>

  <div class="gt-box">
    <h4>Itinerary</h4>
    <p style="font-size:12px;color:#888;margin-top:-6px;">Anyone in the group can add a planned activity. Kept simple and chronological -- no fixed dates required.</p>
    <?php if (empty($itinerary)): ?><p style="color:#888;">No itinerary items yet — add the first one below.</p><?php endif; ?>
    <?php foreach ($itinerary as $item): ?>
      <div class="gt-itin">
        <?php if ($item['day_label']): ?><span class="gt-itin-day"><?php echo htmlspecialchars($item['day_label'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
        <span class="gt-itin-activity"><?php echo htmlspecialchars($item['activity'], ENT_QUOTES, 'UTF-8'); ?> <span class="gt-itin-meta">&middot; added by <?php echo htmlspecialchars($item['added_by'], ENT_QUOTES, 'UTF-8'); ?></span></span>
      </div>
    <?php endforeach; ?>
    <form method="POST" class="form-inline" style="margin-top:16px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_itinerary_item">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="itin_day" class="sr-only">Day (optional)</label>
      <input type="text" id="itin_day" name="day_label" class="form-control" maxlength="50" placeholder="Day (optional)" style="width:140px;">
      <label for="itin_activity" class="sr-only">Activity</label>
      <input type="text" id="itin_activity" name="activity" class="form-control" maxlength="300" placeholder="What's the plan?" required style="width:280px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-map-signs"></i> Add to Itinerary</button>
    </form>
  </div>

  <div class="gt-box">
    <h4>Shared expenses</h4>
    <form method="POST" class="form-inline" style="margin-bottom:16px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_expense">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="expense_description" class="sr-only">Description</label>
      <input type="text" id="expense_description" name="description" class="form-control" placeholder="What was it for?" required style="width:220px;">
      <label for="expense_amount" class="sr-only">Amount</label>
      <input type="number" step="0.01" min="0.01" id="expense_amount" name="amount" class="form-control" placeholder="Amount" required style="width:120px;">
      <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> Add Expense</button>
    </form>
    <?php if (empty($expenses)): ?><p style="color:#888;">No shared expenses logged yet.</p><?php endif; ?>
    <?php foreach ($expenses as $e): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($e->Description, ENT_QUOTES, 'UTF-8'); ?> <small style="color:#aaa;">paid by <?php echo htmlspecialchars($e->PaidByEmail, ENT_QUOTES, 'UTF-8'); ?></small></span><span><?php echo tms_currency($e->AmountMinor / 100); ?></span></div>
    <?php endforeach; ?>
    <div class="gt-row" style="font-weight:800;"><span>Total spent</span><span><?php echo tms_currency($settlement['total_minor'] / 100); ?></span></div>
  </div>

  <div class="gt-box">
    <h4>Who owes whom</h4>
    <p style="color:#888;font-size:13px;">Split evenly across <?php echo count($settlement['members']); ?> member(s). Every amount below is exact — no rounding drift, any odd cent is assigned to specific members rather than lost.</p>
    <?php foreach ($settlement['balance'] as $memberEmail => $bal): ?>
      <div class="gt-row">
        <span><?php echo htmlspecialchars($memberEmail, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="<?php echo $bal >= 0 ? 'balance-pos' : 'balance-neg'; ?>">
          <?php echo $bal >= 0 ? 'is owed ' . tms_currency($bal/100) : 'owes ' . tms_currency(abs($bal)/100); ?>
        </span>
      </div>
    <?php endforeach; ?>
    <?php if (!empty($settlement['settlements'])): ?>
    <h5 style="margin-top:16px;">Suggested settlement</h5>
    <?php foreach ($settlement['settlements'] as $s): ?>
      <div class="gt-row"><span><?php echo htmlspecialchars($s['from'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?php echo htmlspecialchars($s['to'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo tms_currency($s['amount_minor']/100); ?></span></div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="gt-box">
    <h4>Polls</h4>
    <p style="font-size:12px;color:#888;margin-top:-6px;">Anyone in the group can start a poll (e.g. "which date works?"). One vote per person -- voting again just moves your vote.</p>
    <?php if (empty($polls)): ?><p style="color:#888;">No polls yet — create one below.</p><?php endif; ?>
    <?php foreach ($polls as $poll): ?>
      <div class="gt-poll">
        <div class="gt-poll-q"><?php echo htmlspecialchars($poll['question'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($poll['options'] as $opt): ?>
          <div class="gt-poll-opt<?php echo $poll['my_option_id'] === $opt['id'] ? ' gt-poll-mine' : ''; ?>" style="padding:4px 6px;">
            <div class="gt-poll-opt-row">
              <span><?php echo htmlspecialchars($opt['text'], ENT_QUOTES, 'UTF-8'); ?><?php echo $poll['my_option_id'] === $opt['id'] ? ' <i class="fa fa-check-circle" style="color:var(--primary);"></i>' : ''; ?></span>
              <span><?php echo $opt['votes']; ?> vote<?php echo $opt['votes'] == 1 ? '' : 's'; ?> (<?php echo $opt['pct']; ?>%)</span>
            </div>
            <div class="gt-poll-bar-bg"><div class="gt-poll-bar-fill" style="width:<?php echo $opt['pct']; ?>%;"></div></div>
            <form method="POST" style="margin-top:4px;">
              <?php tms_csrf_field(); ?>
              <input type="hidden" name="action" value="cast_vote">
              <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
              <input type="hidden" name="poll_id" value="<?php echo (int)$poll['id']; ?>">
              <input type="hidden" name="option_id" value="<?php echo (int)$opt['id']; ?>">
              <button type="submit" class="btn btn-xs btn-default"><?php echo $poll['my_option_id'] === $opt['id'] ? 'Voted' : 'Vote'; ?></button>
            </form>
          </div>
        <?php endforeach; ?>
        <div class="gt-poll-meta"><?php echo $poll['total_votes']; ?> total vote<?php echo $poll['total_votes'] == 1 ? '' : 's'; ?> &middot; started by <?php echo htmlspecialchars($poll['created_by'], ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    <?php endforeach; ?>
    <form method="POST" style="margin-top:16px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="create_poll">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="poll_question" class="sr-only">Poll question</label>
      <input type="text" id="poll_question" name="question" class="form-control" maxlength="300" placeholder="What should we vote on?" required style="margin-bottom:8px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
        <?php for ($i = 1; $i <= 5; $i++): ?>
        <label for="poll_option_<?php echo $i; ?>" class="sr-only">Option <?php echo $i; ?></label>
        <input type="text" id="poll_option_<?php echo $i; ?>" name="option_<?php echo $i; ?>" class="form-control" maxlength="200" placeholder="Option <?php echo $i; ?><?php echo $i > 2 ? ' (optional)' : ''; ?>"<?php echo $i <= 2 ? ' required' : ''; ?>>
        <?php endfor; ?>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa fa-check-square-o"></i> Create Poll</button>
    </form>
  </div>

  <div class="gt-box">
    <h4>Discussion</h4>
    <p style="font-size:12px;color:#888;margin-top:-6px;">Visible to everyone in this group -- general discussion, separate from the itinerary and polls above.</p>
    <?php if (empty($comments)): ?><p style="color:#888;">No comments yet — start the discussion below.</p><?php endif; ?>
    <?php foreach ($comments as $c): ?>
      <div class="gt-comment">
        <div class="gt-comment-meta"><strong><?php echo htmlspecialchars($c->UserEmail, ENT_QUOTES, 'UTF-8'); ?></strong> &middot; <?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($c->CreatedAt)), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="gt-comment-text"><?php echo htmlspecialchars($c->Comment, ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    <?php endforeach; ?>
    <form method="POST" style="margin-top:16px;">
      <?php tms_csrf_field(); ?>
      <input type="hidden" name="action" value="add_comment">
      <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
      <label for="comment_text" class="sr-only">Comment</label>
      <textarea id="comment_text" name="comment" class="form-control" rows="2" maxlength="1000" placeholder="Say something to the group..." required style="margin-bottom:8px;resize:vertical;"></textarea>
      <button type="submit" class="btn btn-primary"><i class="fa fa-comment"></i> Post Comment</button>
    </form>
  </div>
</div>
<?php include('backend/footer.php'); ?>
</body></html>
