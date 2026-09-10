<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see includes/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.

$error = '';
$msg   = '';

if (isset($_POST['submit1'])) {
    tms_csrf_validate();

    $fname       = tms_clean($_POST['fname']        ?? '');
    $email       = tms_validate_email($_POST['email']  ?? '');
    $mobile      = tms_clean($_POST['mobileno']     ?? '');
    $subject     = tms_clean($_POST['subject']      ?? '');
    $description = tms_clean($_POST['description']  ?? '');

    if (empty($fname) || empty($email) || empty($mobile) || empty($subject) || empty($description)) {
        $error = 'All fields are required.';
    } elseif (strlen($fname) > 100) {
        $error = 'Name is too long.';
    } elseif (empty($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\+?[0-9]{7,15}$/', $mobile)) {
        $error = 'Please enter a valid mobile number.';
    } elseif (strlen($subject) > 200) {
        $error = 'Subject is too long (max 200 characters).';
    } else {
        $sql = "INSERT INTO tblenquiry(FullName,EmailId,MobileNumber,Subject,Description)
                VALUES(:fname,:email,:mobile,:subject,:description)";
        $query = $dbh->prepare($sql);
        $query->bindParam(':fname',       $fname,       PDO::PARAM_STR);
        $query->bindParam(':email',       $email,       PDO::PARAM_STR);
        $query->bindParam(':mobile',      $mobile,      PDO::PARAM_STR);
        $query->bindParam(':subject',     $subject,     PDO::PARAM_STR);
        $query->bindParam(':description', $description, PDO::PARAM_STR);
        $query->execute();

        if ($dbh->lastInsertId()) {
            $msg = 'Your enquiry has been submitted successfully. We will get back to you soon!';
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<title>TMS | Enquiry</title>
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
.errorWrap{padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #dd3d36;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.succWrap {padding:10px;margin:0 0 18px;background:var(--white);border-left:4px solid #5cb85c;box-shadow:0 1px 1px rgba(0,0,0,.1);}
.enquiry-card{max-width:500px;background:var(--white);border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.09);padding:32px;margin:0 auto;}
.enquiry-card .form-group label{font-weight:600;color:#555;font-size:13px;margin-bottom:5px;display:block;}
.enquiry-card .form-control{border:2px solid #e0e0e0;border-radius:7px;font-size:14px;}
.enquiry-card .form-control:focus{border-color:var(--secondary);box-shadow:none;outline:none;}
.btn-enquiry{background:var(--secondary);color:#fff;border:none;border-radius:7px;padding:12px 32px;font-weight:700;font-size:15px;cursor:pointer;transition:.2s;width:100%;margin-top:6px;}
.btn-enquiry:hover{background:#2d6a97;}
.char-counter{font-size:12px;color:#aaa;float:right;margin-top:2px;}
html[data-theme="dark"] .enquiry-card .form-group label { color: var(--text-muted) !important; }
html[data-theme="dark"] .char-counter { color: var(--text-light) !important; }
</style>
</head>
<body>
<?php include('includes/header.php'); ?>
<div class="banner-1">
    <div class="container">
        <h1 class="wow zoomIn animated" data-wow-delay=".1s">Get in Touch</h1>
    </div>
</div>

<div class="privacy">
    <div class="container">
        <div class="enquiry-card wow fadeInUp" data-wow-delay=".1s">
            <h4 style="font-size:1.1em;font-weight:700;color:#333;border-bottom:2px solid #f0f0f0;padding-bottom:12px;margin-bottom:22px;">
                <i class="fa fa-envelope" style="color:var(--secondary)"></i> Send an Enquiry
            </h4>

            <?php if ($error): ?>
                <div class="errorWrap"><strong>ERROR:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES,'UTF-8'); ?></div>
            <?php elseif ($msg): ?>
                <div class="succWrap"><strong>SUCCESS:</strong> <?php echo htmlspecialchars($msg, ENT_QUOTES,'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$msg): ?>
            <form name="enquiry" method="post" id="enquiryForm">
                <?php tms_csrf_field(); ?>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="fname" class="form-control" placeholder="Your full name"
                           required maxlength="100"
                           value="<?php echo htmlspecialchars($_POST['fname'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-control" placeholder="your@email.com"
                           required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label>Mobile Number *</label>
                    <input type="text" name="mobileno" class="form-control" placeholder="e.g. +880123456789"
                           required maxlength="15" pattern="[\+0-9]{7,15}"
                           value="<?php echo htmlspecialchars($_POST['mobileno'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label>Subject *</label>
                    <input type="text" name="subject" class="form-control" placeholder="Brief subject"
                           required maxlength="200"
                           value="<?php echo htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES,'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label>
                        Message *
                        <span class="char-counter"><span id="charCount">0</span>/1000</span>
                    </label>
                    <textarea name="description" class="form-control" rows="5"
                              placeholder="Describe your enquiry in detail…"
                              required maxlength="1000"
                              id="descField"
                              oninput="document.getElementById('charCount').textContent=this.value.length"><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES,'UTF-8'); ?></textarea>
                </div>
                <button type="submit" name="submit1" class="btn-enquiry">
                    <i class="fa fa-paper-plane"></i> Submit Enquiry
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
<?php include('includes/signin.php'); ?>
<?php include('includes/write-us.php'); ?>
</body>
</html>
