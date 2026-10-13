<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Change Package Image</title>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/admin-v6.css'); ?>" rel="stylesheet">
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">

<?php
$error = '';
$msg = '';
if(strlen($_SESSION['alogin'])==0)
	{
header('location:index.php');
exit; // SECURITY FIX (Round 11): missing exit meant this redirect never actually stopped execution
}
else{
	tms_admin_require_role($dbh, ['package_manager', 'manager']); // Round 20 RBAC (item 46)
	$imgid=intval($_GET['imgid']);
if(isset($_POST['submit']))
{
    tms_csrf_validate();

    // SECURITY FIX (Round 11 audit): this used to move_uploaded_file() the
    // attacker-controlled original filename straight into a web-accessible
    // directory with zero extension check — unlike every other upload path
    // in this codebase (add-package.php, edit-package.php, add-blog.php,
    // reviews.php, wallet.php), which all whitelist extensions and generate
    // a safe filename. That gap allowed two real vulnerabilities at once:
    // (1) uploading a .php file to get code execution, since PackageImage
    // files are served directly from a public folder, and (2) path
    // traversal via a crafted filename like "../../x.php", since the raw
    // $_FILES[...]['name'] was concatenated straight into the destination
    // path. Fixed to match the whitelist + randomized-filename pattern
    // used everywhere else.
    if (empty($_FILES['packageimage']['name'])) {
        $error = 'Please choose an image file.';
    } else {
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($_FILES['packageimage']['name'], PATHINFO_EXTENSION));
        $maxSize = 5 * 1024 * 1024;
        $sigCheck = ['ok' => true, 'error' => null];
        if (in_array($ext, $allowed, true) && ($_FILES['packageimage']['error'] ?? null) === UPLOAD_ERR_OK) {
            $sigCheck = tms_validate_upload_signature($_FILES['packageimage']['tmp_name'], $allowed);
        }

        if (!in_array($ext, $allowed, true)) {
            $error = 'Only JPG, JPEG, PNG, GIF, WEBP images allowed.';
        } elseif ($_FILES['packageimage']['size'] > $maxSize) {
            $error = 'Image must be under 5 MB.';
        } elseif ($_FILES['packageimage']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload failed. Please try again.';
        } elseif (!$sigCheck['ok']) {
            $error = $sigCheck['error'];
        } else {
            $uploadDir = 'pacakgeimages/'; // keep existing folder name
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $pimage = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($_FILES['packageimage']['tmp_name'], $uploadDir . $pimage);
            tms_compress_uploaded_image($uploadDir . $pimage);
            $sql = "update TblTourPackages set PackageImage=:pimage where PackageId=:imgid";
            $query = $dbh->prepare($sql);
            $query->bindParam(':imgid', $imgid, PDO::PARAM_STR);
            $query->bindParam(':pimage', $pimage, PDO::PARAM_STR);
            $query->execute();
            // Audit log coverage (Round 52).
            tms_log_activity($dbh, $_SESSION['alogin'], 'Package Image Changed', "PackageId=$imgid, File: $pimage");
            $msg = "Package image updated successfully.";
        }
    }
}

	?>

   
   <!--/content-inner-->

	   
              <!--header start here-->

							
				     <div class="clearfix"> </div>	
				</div>
<!--heder end here-->
	
		<!--grid-->
 	<div class="grid-form">
 
<!---->
  <div class="grid-form1">
  	       <h3>Update Package Image </h3>
  	        	  <?php if($error){?><div class="errorWrap"><strong>ERROR</strong>:<?php echo htmlentities($error); ?> </div><?php } 
				else if($msg){?><div class="succWrap"><strong>SUCCESS</strong>:<?php echo htmlentities($msg); ?> </div><?php }?>
  	         <div class="tab-content">
						<div class="tab-pane active" id="horizontal-form">
							<form class="form-horizontal" name="package" method="post" enctype="multipart/form-data">
								<?php tms_csrf_field(); ?>
						<?php 
$imgid=intval($_GET['imgid']);
$sql = "SELECT PackageImage from TblTourPackages where PackageId=:imgid";
$query = $dbh -> prepare($sql);
$query -> bindParam(':imgid', $imgid, PDO::PARAM_STR);
$query->execute();
$results=$query->fetchAll(PDO::FETCH_OBJ);
$cnt=1;
if($query->rowCount() > 0)
{
foreach($results as $result)
{	?>	
<div class="form-group">
<label for="focusedinput" class="col-sm-2 control-label"> Package Image </label>
<div class="col-sm-8">
<img loading="lazy" src="<?php $img = trim((string)$result->PackageImage); echo preg_match('#^https?://#i', $img) ? htmlentities($img) : 'pacakgeimages/' . htmlentities($img); ?>" width="200">
</div>
</div>
																					
<div class="form-group">
									<label for="focusedinput" class="col-sm-2 control-label">New Image</label>
									<div class="col-sm-8">
										<input type="file" name="packageimage" id="packageimage" required>
									</div>
								</div>	
								<?php }} ?>

								<div class="row">
			<div class="col-sm-8 col-sm-offset-2">
				<button type="submit" name="submit" class="btn-primary btn">Update</button>

			</div>
		</div>
						
					
						
						
						
					</div>
					
					</form>

     
      

      
      <div class="panel-footer">
		
	 </div>
    </form>
  </div>
 	</div>
 	<!--//grid-->

<!--inner block start here-->



<!--copy rights start here-->

<!--COPY rights end here-->
</div>
</div>
  <!--//content-inner-->
		<!--/sidebar-menu-->
					
							  <div class="clearfix"></div>		
							</div>
<!--js -->


<!-- Bootstrap Core JavaScript -->
   
   <!-- /Bootstrap Core JavaScript -->	   


<?php } ?>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
