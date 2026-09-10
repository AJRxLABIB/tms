<?php
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
if(isset($_POST['submit']))
{
tms_csrf_validate();
$issue=$_POST['issue'];
$description=$_POST['description'];
$email=$_SESSION['login'];
$sql="INSERT INTO  tblissues(UserEmail,Issue,Description) VALUES(:email,:issue,:description)";
$query = $dbh->prepare($sql);
$query->bindParam(':issue',$issue,PDO::PARAM_STR);
$query->bindParam(':description',$description,PDO::PARAM_STR);
$query->bindParam(':email',$email,PDO::PARAM_STR);
$query->execute();
$lastInsertId = $dbh->lastInsertId();
if($lastInsertId)
{
$_SESSION['msg']="Info successfully submited ";
echo "<script type='text/javascript'> document.location = 'thankyou.php'; </script>";
}
else 
{
$_SESSION['msg']="Something went wrong. Please try again.";
echo "<script type='text/javascript'> document.location = 'thankyou.php'; </script>";
}
}
?>	

	<div class="modal fade" id="myModal3" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
				<div class="modal-dialog" role="document">
					<div class="modal-content">
						<div class="modal-header">
							<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>						
						</div>
							<section>
							<form name="help" method="post">
								<?php tms_csrf_field(); ?>
								<div class="modal-body modal-spa">
									<div class="writ">
										<h4><?php echo tms_t('heading_how_can_we_help','HOW CAN WE HELP YOU'); ?></h4>
											<ul>
												
												<li class="na-me">
													<select id="country" name="issue" class="frm-field required sect" required="">
														<option value=""><?php echo tms_t('opt_select_issue','Select Issue'); ?></option> 		
														<option value="Booking Issues"><?php echo tms_t('opt_booking_issues','Booking Issues'); ?></option>
														<option value="Cancellation"> <?php echo tms_t('opt_cancellation','Cancellation'); ?></option>
														<option value="Refund"><?php echo tms_t('opt_refund','Refund'); ?></option>
														<option value="Other"><?php echo tms_t('opt_other','Other'); ?></option>														
													</select>
												</li>
											
												<li class="descrip">
									<input class="special" type="text" placeholder="<?php echo htmlspecialchars(tms_t('ph_description','description'), ENT_QUOTES, 'UTF-8'); ?>"  name="description" required="">
												</li>
													<div class="clearfix"></div>
											</ul>
											<div class="sub-bn">
												<form>
													<button type="submit" name="submit" class="subbtn"><?php echo tms_t('btn_submit','Submit'); ?></button>
												</form>
											</div>
									</div>
								</div>
								</form>
							</section>
					</div>
				</div>
			</div>