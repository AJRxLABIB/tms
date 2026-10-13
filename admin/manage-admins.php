<?php
/**
 * manage-admins.php — RBAC (master prompt spec item 46)
 *
 * Superadmin-only. Lets a superadmin create additional admin accounts and
 * assign each one a role. Round 20 expanded this from the original 2-role
 * (superadmin/manager) system to the spec's 8 granular roles:
 *   - superadmin:      full access, always implicit everywhere (tms_admin_can())
 *   - booking_manager: bookings, booking calendar, custom trips
 *   - package_manager: packages (add/edit/images), popular destinations, coupons
 *   - finance:         revenue analytics, report exports, coupons
 *   - support:         support issues, enquiries, live chat, user accounts
 *   - content_manager: blog, pages, translations, reviews
 *   - ai_manager:      AI usage dashboard
 *   - operations:      operations dashboard, activity log, user analytics,
 *                      emergency contacts, custom trips, booking calendar
 *   - manager (legacy): kept working for any admin created before this round
 *     (broad access, same set it always had) but no longer offered for NEW
 *     admins -- the 8 roles above are strictly more specific. See
 *     tms_admin_role()'s docblock in includes/config.php.
 * See each admin/*.php file's tms_admin_require_role() call for the exact,
 * enforced per-page list -- this docblock is a summary, not the source of
 * truth.
 *
 * Safeguards:
 *   - Can't delete your own account (avoids accidentally locking yourself out)
 *   - Can't delete the last remaining superadmin (avoids locking EVERYONE out)
 *   - Can't demote the last remaining superadmin to any other role (same
 *     reason -- generalized in Round 20 to cover all 8+1 roles, not just
 *     the old superadmin->manager case)
 *   - Usernames are unique (DB-enforced via uq_admin_username + an
 *     application-level check for a friendly error message)
 *   - Passwords are bcrypt-hashed with tms_hash_password(), same as every
 *     other password in this app
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_admin_require_role($dbh, ['superadmin']);

$msg = ''; $msgType = '';

// ─── Create a new admin ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_admin'])) {
    tms_csrf_validate();

    // Round 20: validate against the full 8-role (+superadmin) set instead
    // of the old binary superadmin/manager ternary. 'manager' deliberately
    // excluded here -- it's kept working for existing admins but is no
    // longer offered for newly-created ones (see this file's docblock).
    $creatableRoles = array_diff(array_keys(tms_admin_role_labels()), ['manager']);
    $newUser = tms_clean($_POST['new_username'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $newRole = in_array($_POST['new_role'] ?? '', $creatableRoles, true) ? $_POST['new_role'] : 'operations';

    if (strlen($newUser) < 3) {
        $msg = 'Username must be at least 3 characters.'; $msgType = 'error';
    } elseif (strlen($newPass) < 8) {
        $msg = 'Password must be at least 8 characters.'; $msgType = 'error';
    } else {
        $chk = $dbh->prepare("SELECT id FROM admin WHERE UserName = :u LIMIT 1");
        $chk->execute([':u' => $newUser]);
        if ($chk->fetch()) {
            $msg = 'That username is already taken.'; $msgType = 'error';
        } else {
            try {
                $dbh->prepare("INSERT INTO admin (UserName, Password, Role, updationDate) VALUES (:u, :p, :r, NOW())")
                    ->execute([':u' => $newUser, ':p' => tms_hash_password($newPass), ':r' => $newRole]);
                tms_log_activity($dbh, $_SESSION['alogin'], 'Admin Created', "Created admin '$newUser' with role '$newRole'", 'admin', $newUser, null, $newRole);
                $msg = "Admin '$newUser' created with role '$newRole'."; $msgType = 'success';
            } catch (Exception $e) {
                error_log('[TMS Admin] Failed to create admin: ' . $e->getMessage());
                $msg = 'Could not create admin account. Please try again.'; $msgType = 'error';
            }
        }
    }
}

// ─── Change an existing admin's role ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    tms_csrf_validate();
    $targetId = (int)($_POST['admin_id'] ?? 0);
    // Round 20: validate against the full role set (all 8 + superadmin +
    // legacy manager -- a superadmin can still explicitly move someone
    // back to 'manager' if they want, this just isn't offered when
    // *creating* a new admin, see the create_admin handler above).
    $validRoles = array_keys(tms_admin_role_labels());
    $targetRole = in_array($_POST['role'] ?? '', $validRoles, true) ? $_POST['role'] : null;

    $target = $dbh->prepare("SELECT UserName, Role FROM admin WHERE id = :id LIMIT 1");
    $target->execute([':id' => $targetId]);
    $targetRow = $target->fetch(PDO::FETCH_OBJ);

    if (!$targetRow) {
        $msg = 'Admin not found.'; $msgType = 'error';
    } elseif ($targetRole === null) {
        $msg = 'Unknown role selected.'; $msgType = 'error';
    } elseif ($targetRow->Role === 'superadmin' && $targetRole !== 'superadmin') {
        // Demoting a superadmin to ANY other role — make sure at least one
        // superadmin remains. Generalized in Round 20: the old check only
        // caught the specific superadmin->manager case, which would have
        // let the last superadmin demote themselves to one of the 8 new
        // roles and lock everyone out of manage-admins.php.
        $remaining = (int)$dbh->query("SELECT COUNT(*) FROM admin WHERE Role='superadmin'")->fetchColumn();
        if ($remaining <= 1) {
            $msg = 'Cannot demote the last remaining super-admin.'; $msgType = 'error';
        } else {
            $dbh->prepare("UPDATE admin SET Role=:r WHERE id=:id")->execute([':r' => $targetRole, ':id' => $targetId]);
            $label = tms_admin_role_labels()[$targetRole] ?? $targetRole;
            // Round 96 (item 47): $targetRow->Role is the OLD role, read
            // before this UPDATE ran -- previously discarded instead of
            // logged.
            tms_log_activity($dbh, $_SESSION['alogin'], 'Admin Role Changed', "Demoted '{$targetRow->UserName}' from superadmin to '$targetRole'", 'admin', $targetId, $targetRow->Role, $targetRole);
            $msg = "'{$targetRow->UserName}' is now {$label}."; $msgType = 'success';
        }
    } else {
        $dbh->prepare("UPDATE admin SET Role=:r WHERE id=:id")->execute([':r' => $targetRole, ':id' => $targetId]);
        $label = tms_admin_role_labels()[$targetRole] ?? $targetRole;
        tms_log_activity($dbh, $_SESSION['alogin'], 'Admin Role Changed', "Set '{$targetRow->UserName}' to role '$targetRole'", 'admin', $targetId, $targetRow->Role, $targetRole);
        $msg = "'{$targetRow->UserName}' is now {$label}."; $msgType = 'success';
    }
}

// ─── Delete an admin ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $targetId = (int)$_POST['id'];
    $target = $dbh->prepare("SELECT UserName, Role FROM admin WHERE id = :id LIMIT 1");
    $target->execute([':id' => $targetId]);
    $targetRow = $target->fetch(PDO::FETCH_OBJ);

    if (!$targetRow) {
        $msg = 'Admin not found.'; $msgType = 'error';
    } elseif ($targetRow->UserName === $_SESSION['alogin']) {
        $msg = "You can't delete your own account while logged in as it."; $msgType = 'error';
    } elseif ($targetRow->Role === 'superadmin' && (int)$dbh->query("SELECT COUNT(*) FROM admin WHERE Role='superadmin'")->fetchColumn() <= 1) {
        $msg = 'Cannot delete the last remaining super-admin.'; $msgType = 'error';
    } else {
        $dbh->prepare("DELETE FROM admin WHERE id = :id")->execute([':id' => $targetId]);
        tms_log_activity($dbh, $_SESSION['alogin'], 'Admin Deleted', "Deleted admin '{$targetRow->UserName}'", 'admin', $targetId, $targetRow->Role, null);
        $msg = "Admin '{$targetRow->UserName}' deleted."; $msgType = 'success';
    }
}

$admins = $dbh->query("SELECT id, UserName, Role, updationDate FROM admin ORDER BY id ASC")->fetchAll(PDO::FETCH_OBJ);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Manage Admins</title>
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Inter',sans-serif;font-size:22px;font-weight:800;color:var(--text-primary);margin:0;}
.alert-msg{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.action-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:500;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
.role-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;letter-spacing:.3px;}
.role-superadmin{background:#ede9fe;color:#5b21b6;}
.role-manager{background:#DCF1E5;color:#104B3A;}
.role-booking_manager{background:#DCF1E5;color:#104B3A;}
.role-package_manager{background:#cffafe;color:#0e7490;}
.role-finance{background:#fef3c7;color:#92400e;}
.role-support{background:#dcfce7;color:#166534;}
.role-content_manager{background:#fce7f3;color:#9d174d;}
.role-ai_manager{background:#ede9fe;color:#6d28d9;}
.role-operations{background:#e0e7ff;color:#3730a3;}
.create-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:22px 24px;margin-bottom:24px;max-width:520px;}
.create-card h4{font-size:15px;font-weight:700;margin:0 0 16px;color:var(--text-primary);}
.form-row{margin-bottom:13px;}
.form-row label{display:block;font-size:11.5px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.form-row input,.form-row select{width:100%;padding:9px 12px;border:2px solid #e2e8f0;border-radius:8px;font-size:13.5px;outline:none;}
.form-row input:focus,.form-row select:focus{border-color:#176B50;}
.btn-primary-sm{background:#176B50;color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;}
.btn-primary-sm:hover{background:#104B3A;}
.role-select-inline{padding:4px 8px;border-radius:6px;border:1px solid #cbd5e1;font-size:12px;}
html[data-theme="dark"] .create-card { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .create-card h4 { color:#eef2f7 !important; }
html[data-theme="dark"] .form-row label { color:var(--text-muted) !important; }
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-user-shield" style="color:#176B50;margin-right:10px;"></i>Manage Admins</h2>
</div>

<?php if ($msg): ?><div class="alert-msg alert-<?= $msgType ?>"><i class="fa fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="create-card">
  <h4><i class="fa fa-plus-circle"></i> Create New Admin</h4>
  <form method="post">
    <?php tms_csrf_field(); ?>
    <div class="form-row">
      <label for="adm_new_username">Username</label>
      <input type="text" id="adm_new_username" name="new_username" minlength="3" maxlength="100" required autocomplete="off">
    </div>
    <div class="form-row">
      <label for="adm_new_password">Password</label>
      <input type="password" id="adm_new_password" name="new_password" minlength="8" required autocomplete="new-password">
    </div>
    <div class="form-row">
      <label for="adm_new_role">Role</label>
      <select id="adm_new_role" name="new_role">
        <?php foreach (tms_admin_role_labels() as $roleSlug => $roleLabel): ?>
          <?php if ($roleSlug === 'manager') continue; // legacy value, not offered for new admins ?>
          <option value="<?= htmlspecialchars($roleSlug) ?>" <?= $roleSlug === 'operations' ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="create_admin" class="btn-primary-sm"><i class="fa fa-plus"></i> Create Admin</button>
  </form>
</div>

<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Username</th><th>Role</th><th>Last Updated</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($admins as $a): ?>
  <tr>
    <td><?= (int)$a->id ?></td>
    <td><?= htmlspecialchars($a->UserName) ?><?php if ($a->UserName === $_SESSION['alogin']): ?> <span style="color:var(--text-muted);font-size:11px;">(you)</span><?php endif; ?></td>
    <td><span class="role-badge role-<?= htmlspecialchars($a->Role ?: 'superadmin') ?>"><?= htmlspecialchars(tms_admin_role_labels()[$a->Role] ?? $a->Role) ?></span></td>
    <td><?= htmlspecialchars($a->updationDate ?? '—') ?></td>
    <td style="display:flex;gap:8px;align-items:center;">
      <form method="post" style="display:inline-flex;gap:6px;align-items:center;">
        <?php tms_csrf_field(); ?>
        <input type="hidden" name="admin_id" value="<?= (int)$a->id ?>">
        <select name="role" class="role-select-inline">
          <?php foreach (tms_admin_role_labels() as $roleSlug => $roleLabel): ?>
            <option value="<?= htmlspecialchars($roleSlug) ?>" <?= $a->Role === $roleSlug ? 'selected' : '' ?>><?= htmlspecialchars($roleLabel) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="change_role" class="action-btn btn-edit" style="background:#DCF1E5;color:#104B3A;"><i class="fa fa-check"></i></button>
      </form>
      <?php if ($a->UserName !== $_SESSION['alogin']): ?>
      <form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a->id ?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete admin \'<?= htmlspecialchars($a->UserName, ENT_QUOTES) ?>\'?')"><i class="fa fa-trash"></i></button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
