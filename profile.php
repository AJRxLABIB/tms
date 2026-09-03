<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user();

$error = '';
$msg   = '';

if (isset($_POST['submit6'])) {
    tms_csrf_validate();

    $name     = tms_clean($_POST['name']     ?? '');
    $mobileno = tms_clean($_POST['mobileno'] ?? '');
    $email    = $_SESSION['login'];

    if (empty($name)) {
        $error = 'Full name is required.';
    } elseif (strlen($name) > 100) {
        $error = 'Name is too long (max 100 characters).';
    } elseif (!empty($mobileno) && !preg_match('/^\+?[0-9]{7,15}$/', $mobileno)) {
        $error = 'Please enter a valid mobile number.';
    } else {
        $sql = "UPDATE tblusers SET FullName=:name, MobileNumber=:mobileno WHERE EmailId=:email";
        $query = $dbh->prepare($sql);
        $query->bindParam(':name',     $name,     PDO::PARAM_STR);
        $query->bindParam(':mobileno', $mobileno, PDO::PARAM_STR);
        $query->bindParam(':email',    $email,    PDO::PARAM_STR);
        $query->execute();
        $msg = 'Profile updated successfully.';
    }
}

$useremail = $_SESSION['login'];
$q = $dbh->prepare("SELECT * FROM tblusers WHERE EmailId=:e LIMIT 1");
$q->bindParam(':e', $useremail, PDO::PARAM_STR);
$q->execute();
$user = $q->fetch(PDO::FETCH_OBJ);

// Booking summary counts
$bkQ = $dbh->prepare("SELECT status, COUNT(*) as cnt FROM tblbooking WHERE UserEmail=:e GROUP BY status");
$bkQ->bindParam(':e', $useremail, PDO::PARAM_STR);
$bkQ->execute();
$bkStats = ['total'=>0, 'confirmed'=>0, 'pending'=>0, 'cancelled'=>0];
foreach ($bkQ->fetchAll(PDO::FETCH_OBJ) as $bk) {
    $bkStats['total'] += $bk->cnt;
    if ($bk->status == 1) $bkStats['confirmed'] = $bk->cnt;
    if ($bk->status == 0) $bkStats['pending']   = $bk->cnt;
    if ($bk->status == 2) $bkStats['cancelled'] = $bk->cnt;
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | My Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="css/style.min.css" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<link href="css/animate.css" rel="stylesheet" type="text/css" media="all">
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});</script>
<style>
.errorWrap{padding:10px;margin:0 0 16px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 16px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.profile-wrap{display:flex;gap:24px;flex-wrap:wrap;max-width:900px;margin:0 auto;}
.profile-sidebar{flex:0 0 220px;}
.profile-avatar{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--secondary),#27ae60);
    display:flex;align-items:center;justify-content:center;color:#fff;font-size:32px;font-weight:700;
    margin:0 auto 14px;}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;}
.stat-cell{background:var(--white);border-radius:10px;padding:14px 10px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.07);}
.stat-cell .num{font-size:1.6em;font-weight:700;color:var(--secondary);}
.stat-cell .lbl{font-size:11px;color:#aaa;margin-top:2px;}
.profile-main{flex:1;min-width:0;}
.profile-card{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:28px;}
.profile-card label{font-weight:600;color:#555;font-size:13px;display:block;margin-bottom:4px;}
.profile-card .form-control{border:2px solid #e0e0e0;border-radius:7px;height:42px;font-size:14px;}
.profile-card .form-control:focus{border-color:var(--secondary);box-shadow:none;outline:none;}
.profile-card .form-control[readonly]{background:#f8f9fa;cursor:default;}
.btn-save{background:var(--secondary);color:#fff;border:none;border-radius:7px;padding:11px 32px;font-weight:700;font-size:14px;cursor:pointer;transition:.2s;}
.btn-save:hover{background:#2d6a97;}
.quick-links{background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:20px;margin-top:20px;}
.quick-links a{display:flex;align-items:center;gap:10px;padding:10px 0;color:#555;font-size:14px;border-bottom:1px solid #f0f0f0;text-decoration:none;transition:.2s;}
html[data-theme="dark"] .stat-cell .lbl { color: var(--text-light) !important; }
html[data-theme="dark"] .profile-card label,
html[data-theme="dark"] .quick-links a { color: var(--text-muted) !important; }
.quick-links a:last-child{border-bottom:none;}
.quick-links a:hover{color:var(--secondary);}
.quick-links i{width:20px;color:var(--secondary);}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>
<div class="banner-1">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s">My Profile</h1>
    </div>
</div>

<div class="privacy">
    <div class="container">
        <div class="profile-wrap">

            <!-- Sidebar -->
            <div class="profile-sidebar wow fadeInLeft" data-wow-delay=".1s">
                <div style="background:var(--white);border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.08);padding:24px;text-align:center;">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user->FullName ?? 'U', 0, 1)); ?>
                    </div>
                    <h5 style="font-weight:700;color:#333;margin:0 0 4px;"><?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?></h5>
                    <p style="font-size:12px;color:#aaa;margin:0;"><?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?></p>
                    <p style="font-size:11px;color:#ccc;margin-top:6px;">
                        Member since <?php echo date('M Y', strtotime($user->RegDate ?? 'now')); ?>
                    </p>
                </div>
                <div class="stat-grid">
                    <div class="stat-cell">
                        <div class="num"><?php echo $bkStats['total']; ?></div>
                        <div class="lbl">Bookings</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#27ae60;"><?php echo $bkStats['confirmed']; ?></div>
                        <div class="lbl">Confirmed</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#f0ad4e;"><?php echo $bkStats['pending']; ?></div>
                        <div class="lbl">Pending</div>
                    </div>
                    <div class="stat-cell">
                        <div class="num" style="color:#e74c3c;"><?php echo $bkStats['cancelled']; ?></div>
                        <div class="lbl">Cancelled</div>
                    </div>
                </div>
                <div class="quick-links" style="margin-top:16px;">
                    <a href="tour-history.php"><i class="fa fa-history"></i> My Bookings</a>
                    <a href="change-password.php"><i class="fa fa-lock"></i> Change Password</a>
                    <a href="ai-recommend.php"><i class="fa fa-magic"></i> Get Recommendations</a>
                    <a href="package-list.php"><i class="fa fa-search"></i> Browse Packages</a>
                </div>
            </div>

            <!-- Main form -->
            <div class="profile-main wow fadeInRight" data-wow-delay=".1s">
                <div class="profile-card">
                    <h4 style="font-size:1.05em;font-weight:700;color:#333;border-bottom:2px solid #f0f0f0;padding-bottom:12px;margin-bottom:22px;">
                        <i class="fa fa-user-circle" style="color:var(--secondary)"></i> Edit Profile
                    </h4>

                    <?php if ($error): ?>
                        <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php elseif ($msg): ?>
                        <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <?php tms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" class="form-control" required maxlength="100"
                                   value="<?php echo htmlspecialchars($user->FullName ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Mobile Number</label>
                            <input type="text" name="mobileno" class="form-control" maxlength="15"
                                   pattern="[\+0-9]{7,15}"
                                   value="<?php echo htmlspecialchars($user->MobileNumber ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email Address <small style="color:#aaa;">(cannot be changed)</small></label>
                            <input type="email" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->EmailId ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="text" class="form-control" readonly
                                   value="<?php echo htmlspecialchars($user->RegDate ?? '', ENT_QUOTES,'UTF-8'); ?>">
                        </div>
                        <button type="submit" name="submit6" class="btn-save">
                            <i class="fa fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
<?php include('includes/write-us.php'); ?>
</body>
</html>
