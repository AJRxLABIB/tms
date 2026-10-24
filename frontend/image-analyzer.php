<?php
/**
 * image-analyzer.php — AI Image Analyzer (reviewer ask #11)
 * User uploads a destination photo; Gemini vision (via AIClient::askWithImage)
 * classifies the scene, then api/ai.php?action=image_analyze matches it
 * against real packages in the catalog (PackageType/Fetures/Location/Name),
 * not a hardcoded lookup table.
 */
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user();   // protected travel service: sign in first, then return here (login.php?redirect=...)
// LIVE BUG FIX (customer-facing audit): this page is real browsing/
// marketing content -- it's explicitly allowed in robots.txt and listed
// in sitemap.php for search engines -- but a blanket "require login
// everywhere" change (see backend/config.php's tms_require_user())
// silently gated it too, so Google (and any signed-out visitor) was
// redirected to the homepage instead of seeing this page. Intentionally
// left public; booking/account actions on this page still check
// $_SESSION['login'] individually where needed.
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TourBuddy">
<meta name="mobile-web-app-capable" content="yes">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Image Analyzer | TourBuddy</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
.ia-hero{background:linear-gradient(135deg,var(--primary-dark),var(--primary));padding:60px 0 40px;text-align:center;color:#fff;}
.ia-hero h1{font-family:var(--font-display);font-size:2.1rem;font-weight:700;margin-bottom:8px;}
.ia-hero p{color:rgba(255,255,255,.8);font-size:1rem;max-width:520px;margin:0 auto;}

.ia-card{background:#fff;border-radius:20px;padding:32px;box-shadow:var(--shadow-lg);margin:-30px auto 40px;max-width:640px;}
.ia-drop{border:2.5px dashed #cbd5e1;border-radius:16px;padding:36px 20px;text-align:center;cursor:pointer;transition:var(--transition);background:var(--bg);}
.ia-drop.dragover{border-color:var(--secondary);background:rgba(23,107,80,.06);}
.ia-drop i{font-size:38px;color:var(--secondary);margin-bottom:10px;display:block;}
.ia-drop p{color:var(--text-muted);font-size:13.5px;margin:0;}
.ia-preview{max-width:100%;max-height:280px;border-radius:12px;display:none;margin:0 auto 16px;box-shadow:var(--shadow-sm);}
.ia-btn{background:var(--secondary);color:#fff;border:none;border-radius:12px;padding:13px 28px;font-size:14.5px;font-weight:700;cursor:pointer;width:100%;margin-top:16px;transition:var(--transition);}
.ia-btn:hover{background:var(--secondary-dark, #0c8a8a);transform:translateY(-1px);}
.ia-btn:disabled{opacity:.55;cursor:not-allowed;transform:none;}
.ia-error{background:#fef2f2;border-left:4px solid #ef4444;color:#991b1b;padding:12px 16px;border-radius:8px;font-size:13px;margin-top:14px;display:none;}

.ia-result{display:none;max-width:900px;margin:0 auto 60px;padding:0 15px;}
.ia-scene-banner{background:#fff;border-radius:16px;padding:22px 26px;box-shadow:var(--shadow-sm);margin-bottom:24px;display:flex;align-items:center;gap:18px;}
.ia-scene-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--secondary),var(--primary-light));color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;}
.ia-scene-title{font-family:var(--font-display);font-size:18px;font-weight:700;color:var(--text);text-transform:capitalize;}
.ia-scene-desc{font-size:13px;color:var(--text-muted);margin-top:2px;}
.ia-confidence{margin-left:auto;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;}
.ia-confidence.high{background:#d1fae5;color:#065f46;}
.ia-confidence.medium{background:#fef3c7;color:#92400e;}
.ia-confidence.low{background:#fee2e2;color:#991b1b;}

.ia-matches-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:18px;}
.ia-match-card{background:#fff;border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);text-decoration:none;color:inherit;transition:var(--transition);}
.ia-match-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);text-decoration:none;color:inherit;}
.ia-match-card img{width:100%;height:130px;object-fit:cover;}
.ia-match-body{padding:14px 16px;}
.ia-match-name{font-weight:700;font-size:13.5px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ia-match-loc{font-size:11.5px;color:var(--text-muted);margin:2px 0 8px;}
.ia-match-price{font-family:var(--font-mono);font-weight:700;color:var(--secondary);font-size:13px;}
.ia-no-match{text-align:center;padding:50px 20px;color:var(--text-muted);background:#fff;border-radius:14px;box-shadow:var(--shadow-sm);}
.ia-loading{text-align:center;padding:40px;color:var(--text-muted);display:none;}
.ia-loading i{font-size:28px;color:var(--secondary);margin-bottom:10px;display:block;}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="ia-hero">
    <h1><i class="fa fa-camera"></i> Find Trips by Photo</h1>
    <p>Upload a photo of a place you love — mountains, a beach, a city skyline — and our AI will find matching packages in our catalog.</p>
</div>

<div class="ia-card wow fadeInUp">
    <form id="iaForm">
        <div class="ia-drop" id="iaDrop">
            <img id="iaPreview" class="ia-preview" alt="Preview">
            <div id="iaDropInner">
                <i class="fa fa-cloud-upload"></i>
                <p><strong>Click to upload</strong> or drag a photo here<br>JPG, PNG, or WEBP — up to 6 MB</p>
            </div>
            <input type="file" id="iaFile" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </div>
        <button type="submit" class="ia-btn" id="iaSubmit" disabled><i class="fa fa-magic"></i> Analyze Photo</button>
        <div class="ia-error" id="iaError"></div>
    </form>
</div>

<div class="ia-loading" id="iaLoading">
    <i class="fa fa-spinner fa-spin"></i>
    Analyzing your photo…
</div>

<div class="ia-result" id="iaResult">
    <div class="ia-scene-banner">
        <div class="ia-scene-icon" id="iaSceneIcon"><i class="fa fa-globe"></i></div>
        <div>
            <div class="ia-scene-title" id="iaSceneTitle">—</div>
            <div class="ia-scene-desc" id="iaSceneDesc"></div>
        </div>
        <div class="ia-confidence" id="iaConfidence"></div>
    </div>
    <div id="iaMatchesWrap"></div>
</div>

<?php include('backend/footer.php'); ?>
<?php include('backend/write-us.php'); ?>
<?php include('backend/signin.php'); ?>

<script>
/* Round 14: prices rendered in JavaScript used to hardcode the Taka symbol,
   even though the site has a currency selector and every server-rendered price
   goes through tms_currency(). A visitor on EUR saw "৳5,000" here next to
   "€ 38" for the same figure elsewhere. TMS_CUR carries the active currency
   (and its BDT conversion rate) so client-side prices agree with server ones. */
var TMS_CUR = <?php echo json_encode(tms_currency_js()); ?>;
function tmsMoney(bdtAmount) {
    var n = (Number(bdtAmount) || 0) * TMS_CUR.rate;
    return TMS_CUR.symbol + ' ' + n.toLocaleString(undefined, {
        minimumFractionDigits: TMS_CUR.decimals, maximumFractionDigits: TMS_CUR.decimals
    });
}
</script>
<script>
var SCENE_ICONS = {
    beach: 'fa-umbrella-beach', mountain: 'fa-mountain', forest: 'fa-tree',
    city: 'fa-building', snow: 'fa-snowflake-o', desert: 'fa-sun-o',
    historical: 'fa-landmark', adventure: 'fa-hiking'
};
var dropEl = document.getElementById('iaDrop');
var fileEl = document.getElementById('iaFile');
var previewEl = document.getElementById('iaPreview');
var dropInner = document.getElementById('iaDropInner');
var submitBtn = document.getElementById('iaSubmit');
var errorEl = document.getElementById('iaError');
var selectedFile = null;

dropEl.addEventListener('click', function(){ fileEl.click(); });
dropEl.addEventListener('dragover', function(e){ e.preventDefault(); dropEl.classList.add('dragover'); });
dropEl.addEventListener('dragleave', function(){ dropEl.classList.remove('dragover'); });
dropEl.addEventListener('drop', function(e){
    e.preventDefault(); dropEl.classList.remove('dragover');
    if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
});
fileEl.addEventListener('change', function(){ if (fileEl.files.length) handleFile(fileEl.files[0]); });

function handleFile(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        showError('Please choose a JPG, PNG, or WEBP image.');
        return;
    }
    if (file.size > 6 * 1024 * 1024) {
        showError('That photo is over 6 MB — please choose a smaller one.');
        return;
    }
    errorEl.style.display = 'none';
    selectedFile = file;
    var reader = new FileReader();
    reader.onload = function(e) {
        previewEl.src = e.target.result;
        previewEl.style.display = 'block';
        dropInner.style.display = 'none';
    };
    reader.readAsDataURL(file);
    submitBtn.disabled = false;
}

function showError(msg) {
    errorEl.textContent = msg;
    errorEl.style.display = 'block';
}

document.getElementById('iaForm').addEventListener('submit', function(e){
    e.preventDefault();
    if (!selectedFile) return;
    errorEl.style.display = 'none';
    submitBtn.disabled = true;
    document.getElementById('iaLoading').style.display = 'block';
    document.getElementById('iaResult').style.display = 'none';

    var fd = new FormData();
    fd.append('photo', selectedFile);
    fd.append('csrf_token', <?php echo json_encode(tms_csrf_token()); ?>);

    fetch('api/ai.php?action=image_analyze', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(res){
            document.getElementById('iaLoading').style.display = 'none';
            submitBtn.disabled = false;
            if (!res.success) {
                showError(res.error || 'Could not analyze this photo. Please try another.');
                return;
            }
            renderResult(res.data);
        })
        .catch(function(){
            document.getElementById('iaLoading').style.display = 'none';
            submitBtn.disabled = false;
            showError('Something went wrong reaching the AI service. Please try again.');
        });
});

function renderResult(data) {
    document.getElementById('iaSceneIcon').innerHTML = '<i class="fa ' + (SCENE_ICONS[data.scene] || 'fa-globe') + '"></i>';
    document.getElementById('iaSceneTitle').textContent = data.scene + ' destination';
    document.getElementById('iaSceneDesc').textContent = data.description || '';
    var confEl = document.getElementById('iaConfidence');
    confEl.textContent = data.confidence + ' confidence';
    confEl.className = 'ia-confidence ' + data.confidence;

    var wrap = document.getElementById('iaMatchesWrap');
    if (!data.matches || !data.matches.length) {
        wrap.innerHTML = '<div class="ia-no-match"><i class="fa fa-search" style="font-size:32px;color:#cbd5e1;display:block;margin-bottom:10px;"></i>' +
            (data.match_note || 'No matching packages found yet.') + '</div>';
    } else {
        var html = '<div class="ia-matches-grid">';
        data.matches.forEach(function(p){
            html += '<a class="ia-match-card" href="package-details.php?pkgid=' + p.PackageId + '">' +
                '<img src="' + p.image_url + '" alt="">' +
                '<div class="ia-match-body">' +
                '<div class="ia-match-name">' + escHtml(p.PackageName) + '</div>' +
                '<div class="ia-match-loc">' + escHtml(p.PackageLocation || '') + '</div>' +
                '<div class="ia-match-price">' + tmsMoney(p.PackagePrice) + '</div>' +
                '</div></a>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }
    document.getElementById('iaResult').style.display = 'block';
    document.getElementById('iaResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
</body>
</html>
