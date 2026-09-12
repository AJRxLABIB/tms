<?php
/**
 * wallet.php — Digital Travel Wallet
 *
 * Brings together two things a traveler needs while on a trip: their booking
 * e-tickets (already existed via ticket.php, just not aggregated anywhere)
 * and now a secure place to store personal travel documents (passport scan,
 * visa, insurance, ID) for quick access.
 *
 * SECURITY: uploaded documents are personal, sensitive PII (passport/visa
 * scans). Files are stored in uploads/wallet/, which has a .htaccess denying
 * all direct web access — the ONLY way to retrieve a file is through
 * wallet-document.php, which re-checks session ownership on every request.
 * There is no direct/guessable public URL to any uploaded document.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();

$email = $_SESSION['login'];
$msg = ''; $error = '';

const DOC_TYPES = ['passport'=>'Passport','visa'=>'Visa','insurance'=>'Travel Insurance','id'=>'National ID','vaccination'=>'Vaccination Record','other'=>'Other'];
const ALLOWED_EXT  = ['jpg','jpeg','png','pdf'];
const ALLOWED_MIME = ['image/jpeg','image/png','application/pdf'];
const MAX_DOC_SIZE = 8 * 1024 * 1024; // 8 MB

// ─── Upload ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_doc'])) {
    tms_csrf_validate();
    $docType = array_key_exists($_POST['doc_type'] ?? '', DOC_TYPES) ? $_POST['doc_type'] : 'other';
    $notes   = tms_clean($_POST['notes'] ?? '');
    // Real structured expiry date (Rule 28), validated rather than trusted
    // as-is -- an invalid/garbage date is silently dropped (stored NULL)
    // rather than saved as something strtotime() would later misinterpret.
    $expiryRaw = trim($_POST['expiry_date'] ?? '');
    $expiryDate = null;
    if ($expiryRaw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $expiryRaw);
        if ($d && $d->format('Y-m-d') === $expiryRaw) {
            $expiryDate = $expiryRaw;
        }
    }

    if (empty($_FILES['document']['size'])) {
        $error = 'Choose a file to upload.';
    } else {
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $size = (int)$_FILES['document']['size'];
        $tmpPath = $_FILES['document']['tmp_name'];

        // Two layers of validation: extension (cheap, user-facing error
        // messages) AND real content-type sniffing via finfo (can't be
        // spoofed by just renaming a file — a .jpg that's actually an HTML
        // file gets caught here even if it passed the extension check).
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo ? finfo_file($finfo, $tmpPath) : null;
        if ($finfo) finfo_close($finfo);

        if (!in_array($ext, ALLOWED_EXT, true)) {
            $error = 'Only JPG, PNG, or PDF files are allowed.';
        } elseif ($size > MAX_DOC_SIZE) {
            $error = 'File must be under 8 MB.';
        } elseif (!$realMime || !in_array($realMime, ALLOWED_MIME, true)) {
            $error = "That file's actual content doesn't match an allowed document type.";
        } else {
            if (!is_dir('uploads/wallet/')) mkdir('uploads/wallet/', 0755, true);
            $stored = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($tmpPath, 'uploads/wallet/' . $stored)) {
                $stmt = $dbh->prepare(
                    "INSERT INTO tbltraveldocuments (UserEmail, DocType, OriginalFilename, StoredFilename, MimeType, FileSize, Notes, ExpiryDate)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$email, $docType, tms_clean($_FILES['document']['name']), $stored, $realMime, $size, $notes, $expiryDate]);
                $msg = 'Document uploaded securely.';
                tms_notify($dbh, $email, "📁 Your \"" . $docType . "\" document was added to your Travel Wallet.", 'wallet.php');
            } else {
                $error = 'Upload failed — please try again.';
            }
        }
    }
}

// ─── Delete ─────────────────────────────────────────────────────────────────
if (isset($_POST['delete_doc'])) {
    tms_csrf_validate();
    $docId = tms_int($_POST['doc_id'] ?? 0, 1);
    // Scoped to UserEmail so a document can never be deleted (or its file
    // path discovered) by anyone but its owner, even with a guessed DocId.
    $stmt = $dbh->prepare("SELECT StoredFilename FROM tbltraveldocuments WHERE DocId = ? AND UserEmail = ?");
    $stmt->execute([$docId, $email]);
    $stored = $stmt->fetchColumn();
    if ($stored) {
        $dbh->prepare("DELETE FROM tbltraveldocuments WHERE DocId = ? AND UserEmail = ?")->execute([$docId, $email]);
        $path = 'uploads/wallet/' . $stored;
        if (is_file($path)) @unlink($path);
        $msg = 'Document removed.';
    }
}

$docsStmt = $dbh->prepare("SELECT * FROM tbltraveldocuments WHERE UserEmail = ? ORDER BY UploadedAt DESC");
$docsStmt->execute([$email]);
$documents = $docsStmt->fetchAll(PDO::FETCH_OBJ);

$ticketsStmt = $dbh->prepare(
    "SELECT b.BookingId, b.FromDate, b.ToDate, b.status, p.PackageName, p.PackageLocation
     FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
     WHERE b.UserEmail = ? AND b.status = 1 ORDER BY b.RegDate DESC"
);
$ticketsStmt->execute([$email]);
$tickets = $ticketsStmt->fetchAll(PDO::FETCH_OBJ);

$docIcons = ['passport'=>'book','visa'=>'id-card','insurance'=>'shield','id'=>'id-badge','vaccination'=>'medkit','other'=>'file'];
?>
<!DOCTYPE HTML><html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>"><head>
<title>TMS | Travel Wallet</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<link href="css/bootstrap.min2.css" rel='stylesheet'/>
<link href="css/style.min.css" rel='stylesheet'/>
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<style>
.page-title{background:linear-gradient(135deg,var(--primary-dark),var(--primary));color:#fff;padding:40px 0;margin-bottom:30px;font-family:var(--font-display);}
.page-title h2{font-family:var(--font-display);font-weight:600;}
.wallet-card{background:#fff;border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:20px;margin-bottom:24px;border-top:3px solid var(--secondary);}
.wallet-card h4{font-size:15px;font-weight:700;margin:0 0 16px;color:var(--primary);}
.doc-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px dashed var(--bg-dark);}
.doc-row:last-child{border-bottom:none;}
.doc-icon{width:38px;height:38px;border-radius:9px;background:var(--bg-dark);color:var(--secondary);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.security-note{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;padding:10px 14px;border-radius:8px;margin-bottom:18px;display:flex;gap:8px;align-items:flex-start;}
.ticket-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px dashed var(--bg-dark);}
.ticket-row:last-child{border-bottom:none;}
.wallet-card .btn-primary{background:var(--secondary);border-color:var(--secondary);font-family:var(--font-body);font-weight:700;}
.wallet-card .btn-primary:hover{background:var(--primary);border-color:var(--primary);}
</style>
</head><body>
<?php include('backend/header.php'); ?>
<div class="page-title">
  <div class="container"><h2 style="margin:0;"><i class="fa fa-briefcase"></i> Travel Wallet</h2>
  <p style="margin:6px 0 0;opacity:.9;font-size:13.5px;">Your tickets and travel documents, all in one secure place.</p></div>
</div>
<div class="container" style="padding-bottom:50px;">
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="security-note">
  <i class="fa fa-lock" style="margin-top:2px;"></i>
  <span>Uploaded documents are stored securely and are only ever accessible from your own account — there's no public link to any file you upload.</span>
</div>

<div class="row">
  <div class="col-md-5">
    <div class="wallet-card">
      <h4><i class="fa fa-upload"></i> Add a Document</h4>
      <form method="post" enctype="multipart/form-data">
        <?php tms_csrf_field(); ?>
        <div class="form-group">
          <label for="wallet_doc_type">Document Type</label>
          <select id="wallet_doc_type" name="doc_type" class="form-control">
            <?php foreach (DOC_TYPES as $key => $label): ?>
            <option value="<?= $key ?>"><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="wallet_document">File (JPG, PNG, or PDF — max 8MB)</label>
          <input type="file" id="wallet_document" name="document" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
        </div>
        <div class="form-group">
          <label for="wallet_expiry_date">Expiry date (optional)</label>
          <input type="date" id="wallet_expiry_date" name="expiry_date" class="form-control">
          <small style="color:#9ca3af;">We'll remind you before this expires.</small>
        </div>
        <div class="form-group">
          <label for="wallet_notes">Notes (optional)</label>
          <input type="text" id="wallet_notes" name="notes" class="form-control" maxlength="255" placeholder="e.g. Multi-entry visa">
        </div>
        <button type="submit" name="upload_doc" class="btn btn-primary btn-block"><i class="fa fa-lock"></i> Upload Securely</button>
      </form>
    </div>
  </div>

  <div class="col-md-7">
    <div class="wallet-card">
      <h4><i class="fa fa-folder"></i> My Documents</h4>
      <?php if (empty($documents)): ?>
        <div style="color:#9ca3af;font-size:13px;">No documents uploaded yet.</div>
      <?php else: foreach ($documents as $d): ?>
        <div class="doc-row">
          <div class="doc-icon"><i class="fa fa-<?= $docIcons[$d->DocType] ?? 'file' ?>"></i></div>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars(DOC_TYPES[$d->DocType] ?? 'Document') ?></div>
            <div style="font-size:11px;color:#9ca3af;"><?= htmlspecialchars($d->OriginalFilename) ?> &middot; <?= date('d M Y', strtotime($d->UploadedAt)) ?><?= $d->Notes ? ' &middot; ' . htmlspecialchars($d->Notes) : '' ?></div>
            <?php if (!empty($d->ExpiryDate)):
              $daysLeft = (int)floor((strtotime($d->ExpiryDate) - strtotime(date('Y-m-d'))) / 86400);
              $expColor = $daysLeft < 0 ? '#e74c3c' : ($daysLeft <= 30 ? '#e67e22' : '#9ca3af');
            ?>
            <div style="font-size:11px;color:<?= $expColor ?>;font-weight:<?= $daysLeft<=30?'700':'400' ?>;">
              <i class="fa fa-calendar"></i>
              <?= $daysLeft < 0 ? 'Expired ' . date('d M Y', strtotime($d->ExpiryDate)) : 'Expires ' . date('d M Y', strtotime($d->ExpiryDate)) . " ({$daysLeft} days)" ?>
            </div>
            <?php endif; ?>
          </div>
          <a href="wallet-document.php?id=<?= (int)$d->DocId ?>" class="btn btn-xs btn-default"><i class="fa fa-download"></i> Download</a>
          <form method="post" style="display:inline;margin:0;">
            <?php tms_csrf_field(); ?>
            <input type="hidden" name="doc_id" value="<?= (int)$d->DocId ?>">
            <button type="submit" name="delete_doc" class="btn btn-xs btn-link" style="color:#dc2626;" onclick="return confirm('Delete this document?')"><i class="fa fa-trash"></i></button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<div class="wallet-card">
  <h4><i class="fa fa-ticket"></i> My Tickets</h4>
  <?php if (empty($tickets)): ?>
    <div style="color:#9ca3af;font-size:13px;">No confirmed bookings yet.</div>
  <?php else: foreach ($tickets as $t): ?>
    <div class="ticket-row">
      <div class="doc-icon" style="background:#f0fdf4;color:#059669;"><i class="fa fa-check"></i></div>
      <div style="flex:1;">
        <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($t->PackageName) ?></div>
        <div style="font-size:11px;color:#9ca3af;"><?= htmlspecialchars($t->PackageLocation) ?><?= $t->FromDate ? ' &middot; ' . htmlspecialchars($t->FromDate) : '' ?></div>
      </div>
      <a href="ticket.php?bkid=<?= (int)$t->BookingId ?>" class="btn btn-xs btn-primary" target="_blank"><i class="fa fa-download"></i> View Ticket</a>
    </div>
  <?php endforeach; endif; ?>
</div>

</div>
<?php include('backend/footer.php'); ?>
</body></html>
