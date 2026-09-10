<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_admin_require_role($dbh, ['superadmin', 'finance']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | TMS Admin | Export Reports</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/admin-v6.css" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php
?>





  <h4 style="margin-bottom:24px;color:#555;">Choose a report to export:</h4>
  <div class="row">
    <?php
    $reports = [
      ['bookings','list-alt','Bookings Report','All booking records with status and dates','#3F84B1'],
      ['users','user','Users Report','All registered users and their details','#9b59b6'],
      ['enquiries','envelope','Enquiries Report','All enquiries submitted by visitors','#e67e22'],
      ['packages','briefcase','Packages Report','All tour packages with pricing','#27ae60'],
    ];
    foreach ($reports as [$type,$icon,$title,$desc,$color]):
    ?>
    <div class="col-md-3 col-sm-6">
      <div class="report-card">
        <i class="fa fa-<?= $icon ?>" style="color:<?= $color ?>;"></i>
        <h4><?= $title ?></h4>
        <p><?= $desc ?></p>
        <a href="do-export.php?type=<?= $type ?>&format=csv" class="btn btn-sm btn-success" style="margin-right:4px;margin-bottom:6px;">
          <i class="fa fa-download"></i> CSV
        </a>
        <a href="do-export.php?type=<?= $type ?>&format=html" target="_blank" class="btn btn-sm btn-primary">
          <i class="fa fa-print"></i> Print/PDF
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

</div></div>

</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
