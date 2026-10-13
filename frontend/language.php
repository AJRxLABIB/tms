<?php
require_once __DIR__ . '/../backend/session-boot.php';
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');

// ROOT-CAUSE FIX: the UI on this page only ever renders <a href="language.php?lang=...">
// links (GET) — the isset($_POST['setlang']) branch below was unreachable dead code, so
// a logged-in visitor's choice was NEVER actually saved to tblusers.Language through the
// real UI, only to $_SESSION (which resets on logout / a fresh session). There was also no
// cookie, so the requirement "remember language after refresh/login/logout, never reset to
// English automatically" wasn't met. Both paths now set session + a 1-year cookie + (when
// logged in) the DB, so the choice survives logout and a brand-new session on the same browser.
$allowedLangs = ['en','bn','hi','fr','ar','zh','es','de'];

function tms_persist_language(PDO $dbh, string $lang, array $allowed): bool {
    if (!in_array($lang, $allowed, true)) return false;
    $_SESSION['lang'] = $lang;
    setcookie('tms_lang', $lang, time() + 60 * 60 * 24 * 365, '/');
    if (!empty($_SESSION['login'])) {
        $q = $dbh->prepare("UPDATE tblusers SET Language=:lang WHERE EmailId=:email");
        $q->execute([':lang' => $lang, ':email' => $_SESSION['login']]);
    }
    return true;
}

if (isset($_POST['setlang'])) {
    if (tms_persist_language($dbh, tms_clean($_POST['lang'] ?? ''), $allowedLangs)) {
        header('location: index.php');
        exit;
    }
}

if (isset($_GET['lang'])) {
    if (tms_persist_language($dbh, tms_clean($_GET['lang']), $allowedLangs)) {
        // FIX (Round 14): this redirected to any URL supplied in the query
        // string, so language.php?lang=en&redirect=https://evil.example sent the
        // visitor straight off-site from a trusted page. login.php already had
        // the correct guard; this reuses it.
        $redirect = isset($_GET['redirect']) ? (string)$_GET['redirect'] : 'index.php';
        $safeRedirect = ($redirect !== '' && !str_starts_with($redirect, '//') && !str_contains($redirect, '://'))
            ? $redirect : 'index.php';
        header('location: ' . $safeRedirect);
        exit;
    }
}
?>
<!DOCTYPE HTML>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<title>TMS | Language Settings</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="css/bootstrap.min2.css" rel='stylesheet' type='text/css' />
<link href="<?php echo tms_asset_ver('css/style.min.css'); ?>" rel='stylesheet' type='text/css' />
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="css/animate.css" rel="stylesheet">
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<style>
.lang-hero { background: linear-gradient(135deg,#4568dc,#b06ab3); color:#fff; padding:55px 0 30px; text-align:center; }
.lang-hero h1 { font-size:2.3em; }
.lang-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:20px; padding:30px 0; }
.lang-card { background:var(--white); border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:28px 20px; text-align:center; cursor:pointer; border:3px solid transparent; transition:0.3s; text-decoration:none; display:block; }
.lang-card:hover { transform:translateY(-4px); box-shadow:0 12px 35px rgba(0,0,0,0.12); border-color:#4568dc; text-decoration:none; }
.lang-card.current { border-color:#4568dc; background: linear-gradient(135deg,#f0f4ff,#f8f0ff); }
.lang-flag { font-size:48px; display:block; margin-bottom:10px; }
.lang-name { font-size:1.1em; font-weight:700; color:#333; display:block; }
.lang-native { font-size:0.9em; color:var(--text-muted); display:block; margin-top:3px; }
html[data-theme="dark"] .lang-name { color: var(--text) !important; }
html[data-theme="dark"] .lang-native { color: var(--text-muted) !important; }
.lang-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; margin-top:8px; background:#e8f0ff; color:#4568dc; }
.lang-badge.current-badge { background:#d4edda; color:#155724; }
.preview-section { background:#f8f9fa; border-radius:12px; padding:25px; margin:20px 0; }
.preview-section h4 { color:#333; margin-bottom:15px; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="lang-hero">
    <div class="container">
        <h1><i class="fa fa-language"></i> Choose Your Language</h1>
        <p>TMS is available in multiple languages for a better experience</p>
    </div>
</div>

<div class="container">
    <?php
    $currentLang = tms_active_language();
    $languages = [
        ['code'=>'en','flag'=>'🇬🇧','name'=>'English','native'=>'English','dir'=>'ltr'],
        ['code'=>'bn','flag'=>'🇧🇩','name'=>'Bengali','native'=>'বাংলা','dir'=>'ltr'],
        ['code'=>'hi','flag'=>'🇮🇳','name'=>'Hindi','native'=>'हिन्दी','dir'=>'ltr'],
        ['code'=>'fr','flag'=>'🇫🇷','name'=>'French','native'=>'Français','dir'=>'ltr'],
        ['code'=>'ar','flag'=>'🇸🇦','name'=>'Arabic','native'=>'العربية','dir'=>'rtl'],
        ['code'=>'zh','flag'=>'🇨🇳','name'=>'Chinese','native'=>'中文','dir'=>'ltr'],
        ['code'=>'es','flag'=>'🇪🇸','name'=>'Spanish','native'=>'Español','dir'=>'ltr'],
        ['code'=>'de','flag'=>'🇩🇪','name'=>'German','native'=>'Deutsch','dir'=>'ltr'],
    ];
    ?>

    <div class="lang-grid">
        <?php foreach($languages as $lang):
            $isCurrent = ($lang['code'] == $currentLang);
            $redirect = isset($_SERVER['HTTP_REFERER']) ? urlencode(basename($_SERVER['HTTP_REFERER'])) : 'index.php';
        ?>
        <a href="language.php?lang=<?php echo $lang['code']; ?>&redirect=index.php"
           class="lang-card wow fadeInUp <?php echo $isCurrent ? 'current' : ''; ?>">
            <span class="lang-flag"><?php echo $lang['flag']; ?></span>
            <span class="lang-name"><?php echo $lang['name']; ?></span>
            <span class="lang-native" dir="<?php echo $lang['dir']; ?>"><?php echo $lang['native']; ?></span>
            <?php if($isCurrent): ?>
            <span class="lang-badge current-badge"><i class="fa fa-check"></i> Current</span>
            <?php else: ?>
            <span class="lang-badge">Select</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Translation Preview -->
    <div class="preview-section wow fadeInUp">
        <h4><i class="fa fa-eye" style="color:#4568dc"></i> Translation Preview</h4>
        <p style="color:var(--text-muted);font-size:14px;">Here's how key phrases appear in the selected language:</p>
        <div class="table-responsive"><table class="table table-bordered" style="font-size:14px;" id="previewTable">
            <thead style="background:#4568dc;color:#fff;">
                <tr><th>English</th><th id="langHeader">Selected Language</th></tr>
            </thead>
            <tbody id="previewBody"></tbody>
        </table></div>
    </div>
</div>

<script>
var translations = {
    en: { name: 'English', phrases: [
        ['Welcome','Welcome'],['Tour Packages','Tour Packages'],['Book Now','Book Now'],
        ['Our Services','Our Services'],['Contact Us','Contact Us'],['Sign In','Sign In'],
        ['Sign Up','Sign Up'],['About Us','About Us'],['Search','Search']
    ]},
    bn: { name: 'বাংলা', phrases: [
        ['Welcome','স্বাগতম'],['Tour Packages','ট্যুর প্যাকেজ'],['Book Now','এখনই বুক করুন'],
        ['Our Services','আমাদের সেবা'],['Contact Us','যোগাযোগ করুন'],['Sign In','সাইন ইন'],
        ['Sign Up','সাইন আপ'],['About Us','আমাদের সম্পর্কে'],['Search','খুঁজুন']
    ]},
    hi: { name: 'हिन्दी', phrases: [
        ['Welcome','स्वागत है'],['Tour Packages','टूर पैकेज'],['Book Now','अभी बुक करें'],
        ['Our Services','हमारी सेवाएं'],['Contact Us','संपर्क करें'],['Sign In','साइन इन'],
        ['Sign Up','साइन अप'],['About Us','हमारे बारे में'],['Search','खोजें']
    ]},
    fr: { name: 'Français', phrases: [
        ['Welcome','Bienvenue'],['Tour Packages','Forfaits Touristiques'],['Book Now','Réservez Maintenant'],
        ['Our Services','Nos Services'],['Contact Us','Contactez-Nous'],['Sign In','Se Connecter'],
        ['Sign Up','S\'inscrire'],['About Us','À Propos'],['Search','Rechercher']
    ]},
    ar: { name: 'العربية', phrases: [
        ['Welcome','أهلاً وسهلاً'],['Tour Packages','باقات السياحة'],['Book Now','احجز الآن'],
        ['Our Services','خدماتنا'],['Contact Us','اتصل بنا'],['Sign In','تسجيل الدخول'],
        ['Sign Up','إنشاء حساب'],['About Us','معلومات عنا'],['Search','بحث']
    ]},
    zh: { name: '中文', phrases: [
        ['Welcome','欢迎'],['Tour Packages','旅游套餐'],['Book Now','立即预订'],
        ['Our Services','我们的服务'],['Contact Us','联系我们'],['Sign In','登录'],
        ['Sign Up','注册'],['About Us','关于我们'],['Search','搜索']
    ]},
    es: { name: 'Español', phrases: [
        ['Welcome','Bienvenido'],['Tour Packages','Paquetes Turísticos'],['Book Now','Reservar Ahora'],
        ['Our Services','Nuestros Servicios'],['Contact Us','Contáctenos'],['Sign In','Iniciar Sesión'],
        ['Sign Up','Registrarse'],['About Us','Sobre Nosotros'],['Search','Buscar']
    ]},
    de: { name: 'Deutsch', phrases: [
        ['Welcome','Willkommen'],['Tour Packages','Reisepakete'],['Book Now','Jetzt Buchen'],
        ['Our Services','Unsere Dienste'],['Contact Us','Kontakt'],['Sign In','Anmelden'],
        ['Sign Up','Registrieren'],['About Us','Über Uns'],['Search','Suchen']
    ]},
};

function showPreview(code) {
    var t = translations[code] || translations['en'];
    document.getElementById('langHeader').textContent = t.name;
    var rows = '';
    t.phrases.forEach(function(p) {
        rows += '<tr><td>'+p[0]+'</td><td>'+p[1]+'</td></tr>';
    });
    document.getElementById('previewBody').innerHTML = rows;
}

// Show current language preview
showPreview('<?php echo $currentLang; ?>');

// Update preview on hover
document.querySelectorAll('.lang-card').forEach(function(card) {
    card.addEventListener('mouseenter', function() {
        var code = this.href.split('lang=')[1].split('&')[0];
        showPreview(code);
    });
});

window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW().init()});
</script>

<?php include('backend/footer.php'); ?>
<?php include('backend/signin.php'); ?>
<?php include('backend/write-us.php'); ?>
</body>
</html>
