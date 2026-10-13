<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['operations', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Emergency Contacts</title>
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
.btn-edit{background:#DCF1E5;color:#104B3A;}.btn-edit:hover{background:#B8E4C9;}
.btn-delete{background:#fee2e2;color:#991b1b;}.btn-delete:hover{background:#fecaca;}
.btn-approve{background:#d1fae5;color:#065f46;}.btn-approve:hover{background:#a7f3d0;}
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-phone" style="color:#176B50;margin-right:10px;"></i>Emergency Contacts</h2>
</div>


<?php
$msg=''; $msgType='';
if(isset($_POST['add_contact'])){
    tms_csrf_validate();
    $country=tms_clean($_POST['country']??'');
    $police=tms_clean($_POST['police']??'');
    $ambulance=tms_clean($_POST['ambulance']??'');
    $fire=tms_clean($_POST['fire']??'');
    $helpline=tms_clean($_POST['helpline']??'');
    $embassy=tms_clean($_POST['embassy']??'');
    $tips=tms_clean($_POST['tips']??'');
    if($country){
        try{
            $dbh->prepare("INSERT INTO tblemergencycontacts (Country,PoliceNumber,AmbulanceNumber,FireNumber,TouristHelpline,Embassy,SafetyTips) VALUES(:c,:p,:a,:f,:h,:e,:t)")
                ->execute([':c'=>$country,':p'=>$police,':a'=>$ambulance,':f'=>$fire,':h'=>$helpline,':e'=>$embassy,':t'=>$tips]);
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Emergency Contact Added', "Country: $country");
            $msg="Emergency contact info added for {$country}."; $msgType='success';
        }catch(Exception $e){ $msg="Error: ".$e->getMessage(); $msgType='error'; }
    } else { $msg="Country is required."; $msgType='error'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    tms_csrf_validate();
    $eid=(int)$_POST['id'];
    $dbh->prepare("DELETE FROM tblemergencycontacts WHERE id=:id")->execute([':id'=>$eid]);
    // Audit log coverage (Round 52). This table backs a safety-critical
    // feature (safety.php's emergency numbers), so accidental/malicious
    // deletion is worth an audit trail.
    tms_log_activity($dbh, $_SESSION['alogin'], 'Emergency Contact Deleted', "id=$eid");
    $msg="Contact deleted."; $msgType='success';
}
try{ $contacts=$dbh->query("SELECT * FROM tblemergencycontacts ORDER BY Country")->fetchAll(PDO::FETCH_OBJ); }
catch(Exception $e){ $contacts=[]; }
?>
<?php if($msg): ?><div class="alert-msg alert-<?=$msgType?>"><i class="fa fa-check-circle"></i><?=htmlspecialchars($msg)?></div><?php endif; ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="font-size:16px;font-weight:700;margin-bottom:14px;">Add Country Emergency Info</h3>
  <p style="font-size:12px;color:var(--text-muted);margin-top:-8px;margin-bottom:14px;">This feeds the live "Emergency Contacts &amp; Travel Safety" page — one entry per country.</p>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
  <?php tms_csrf_field(); ?>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_country">Country *</label>
    <input type="text" id="emg_country" name="country" placeholder="e.g. Bangladesh" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" required></div>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_police">Police</label>
    <input type="text" id="emg_police" name="police" placeholder="999" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:100px;"></div>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_ambulance">Ambulance</label>
    <input type="text" id="emg_ambulance" name="ambulance" placeholder="999" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:100px;"></div>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_fire">Fire</label>
    <input type="text" id="emg_fire" name="fire" placeholder="999" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:100px;"></div>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_helpline">Tourist Helpline</label>
    <input type="text" id="emg_helpline" name="helpline" placeholder="+880..." style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>
    <div><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_embassy">Embassy</label>
    <input type="text" id="emg_embassy" name="embassy" placeholder="Embassy contact" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>
    <div style="flex-basis:100%;"><label style="font-size:12px;font-weight:500;color:var(--text-muted);display:block;margin-bottom:4px;" for="emg_tips">Safety Tips</label>
    <textarea id="emg_tips" name="tips" placeholder="General safety guidance for this country..." style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;width:100%;min-height:60px;"></textarea></div>
    <button type="submit" name="add_contact" class="btn-v6 primary"><i class="fa fa-plus"></i> Add</button>
  </form>
</div>
<div class="table-responsive">
<table class="tms-table" style="font-size:13px;">
  <thead><tr><th>#</th><th>Country</th><th>Police</th><th>Ambulance</th><th>Fire</th><th>Helpline</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if(empty($contacts)): ?>
  <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No emergency contact info added yet.</td></tr>
  <?php else: $i=1; foreach($contacts as $c): ?>
  <tr>
    <td><?=$i++?></td>
    <td><strong><?=htmlspecialchars($c->Country??'—')?></strong></td>
    <td><?=htmlspecialchars($c->PoliceNumber??'—')?></td>
    <td><?=htmlspecialchars($c->AmbulanceNumber??'—')?></td>
    <td><?=htmlspecialchars($c->FireNumber??'—')?></td>
    <td><?=htmlspecialchars($c->TouristHelpline??'—')?></td>
    <td><form method="post" style="display:contents"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c->id?>"><?php tms_csrf_field(); ?><button type="submit" class="action-btn btn-delete" onclick="return confirm('Delete?')"><i class="fa fa-trash"></i></button></form></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>


<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
