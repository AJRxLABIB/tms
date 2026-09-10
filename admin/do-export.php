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
<title>TMS | Do Export</title>
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

$type   = tms_clean($_GET['type']   ?? 'bookings');
$format = tms_clean($_GET['format'] ?? 'csv');
$data = []; $headers = [];

if ($type === 'bookings') {
    $q = $dbh->query("SELECT b.BookingId,b.UserEmail,p.PackageName,b.FromDate,b.ToDate,
        CASE b.status WHEN 0 THEN 'Pending' WHEN 1 THEN 'Confirmed' WHEN 2 THEN 'Cancelled' ELSE 'Unknown' END as Status,
        b.RegDate FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId ORDER BY b.BookingId DESC");
    $headers = ['Booking ID','User Email','Package','From Date','To Date','Status','Booked On'];
    $data = $q->fetchAll(PDO::FETCH_NUM);
} elseif ($type === 'users') {
    $q = $dbh->query("SELECT id,FullName,EmailId,MobileNumber,RegDate FROM tblusers ORDER BY id DESC");
    $headers = ['ID','Full Name','Email','Mobile','Registered On'];
    $data = $q->fetchAll(PDO::FETCH_NUM);
} elseif ($type === 'enquiries') {
    $q = $dbh->query("SELECT id,fname,lname,email,mobileno,subject,RegDate FROM tblenquiry ORDER BY id DESC");
    $headers = ['ID','First Name','Last Name','Email','Mobile','Subject','Date'];
    $data = $q->fetchAll(PDO::FETCH_NUM);
} elseif ($type === 'packages') {
    $q = $dbh->query("SELECT PackageId,PackageName,PackageType,PackageLocation,PackagePrice,Creationdate FROM tbltourpackages ORDER BY PackageId DESC");
    $headers = ['ID','Package Name','Type','Location','Price','Created'];
    $data = $q->fetchAll(PDO::FETCH_NUM);
}

$filename = "tms_{$type}_" . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename={$filename}.csv");
    $out = fopen('php://output','w');
    fputcsv($out, $headers);
    foreach ($data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
?>

<button class="print-btn" onclick="window.print()"><i class="fa fa-print"></i> Print / Save as PDF</button>
<h3>TMS – <?= ucfirst($type) ?> Report &nbsp;<small style="color:#aaa;font-size:14px;"><?= date('d M Y') ?></small></h3>
<p style="color:#888;"><?= count($data) ?> record(s)</p>
<table>
  <thead><tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($data as $row): ?>
  <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  <?php if (count($data)===0): ?><tr><td colspan="<?= count($headers) ?>" style="text-align:center;color:#aaa;">No data found.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
