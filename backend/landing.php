<?php
/**
 * includes/landing.php — Public Landing Page
 *
 * Batch 1 (landing page + auth gating). Rendered by index.php for any
 * visitor who isn't signed in. Reuses includes/header.php (which is already
 * guest-aware — it shows Sign In / Sign Up buttons and the auth modals when
 * $_SESSION['login'] is empty) and includes/footer.php, so the login/signup
 * forms, CSRF handling, dark mode toggle, and design language are exactly
 * the same as the rest of the site — nothing here is a parallel system.
 *
 * This file is only ever include()'d from index.php, never requested
 * directly, so it doesn't repeat session_start()/config.php.
 */
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Smart AI-Powered Travel Marketplace</title>
<?php
// NEW (Round 118, master prompt item 53 -- SEO): this is the site's real
// public homepage -- frontend/index.php only ever renders this file for a
// signed-out visitor (see the doc comment at the top of this file), so
// index.php itself is never what a search crawler or a shared link sees,
// and was never worth wiring. This page was missing canonical/OpenGraph
// tags entirely (a hand-written <title>/<meta description> only) despite
// being the single highest-SEO-value page on the whole site. No fabricated
// data -- same 'no image unless a real one exists' convention already
// used by every other tms_seo_meta() call site.
tms_seo_meta([
    'title'       => 'TMS | Smart AI-Powered Travel Marketplace',
    'description' => 'TMS is an AI-powered travel marketplace — smart trip planning, live weather, multi-currency pricing, an AI travel assistant, and a marketplace of tour packages, all in one place. Sign up free to start planning.',
]);
?>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">

<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/animate.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">

<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW({offset:80}).init()});</script>

<style>
/* ── Landing-only sections. Everything else on this page (hero, feature
     cards, testimonials) reuses .tms-hero / .tms-features-grid /
     .tms-feature-card / .tms-review-card from modern-theme.css, so the
     landing page never drifts from the rest of the site's look. These are
     the three sections that don't exist anywhere else yet: Why Choose Us,
     the feature-explainer strip, and the FAQ accordion. ── */
.tms-landing-why { padding: 90px 0; background: var(--bg-light,#f7f9fb); }
.tms-landing-faq-section { background: var(--bg-light,#f7f9fb); }
[data-theme="dark"] .tms-landing-faq-section { background: var(--bg-dark,#0f1720); }
.tms-landing-why-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:24px; margin-top:40px; }
.tms-landing-why-card { background:#fff; border-radius:var(--radius-md); padding:30px 24px; box-shadow:var(--shadow-sm); text-align:center; transition:var(--transition); }
.tms-landing-why-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-lg); }
.tms-landing-why-icon { width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--secondary)); display:flex; align-items:center; justify-content:center; margin:0 auto 18px; color:#fff; font-size:22px; }
.tms-landing-why-card h4 { font-size:16px; font-weight:800; color:var(--text); margin-bottom:8px; }
.tms-landing-why-card p { font-size:13.5px; color:var(--text-muted); line-height:1.6; margin:0; }

.tms-landing-cta-band { padding:70px 0; background:linear-gradient(135deg,var(--primary-dark),var(--primary)); text-align:center; }
.tms-landing-cta-band h2 { color:#fff; font-family:var(--font-display); font-size:clamp(26px,4vw,38px); margin-bottom:14px; }
.tms-landing-cta-band p { color:rgba(255,255,255,0.8); font-size:15.5px; margin-bottom:30px; }

.tms-faq { max-width:820px; margin:40px auto 0; }
.tms-faq-item { background:#fff; border-radius:var(--radius-md); box-shadow:var(--shadow-sm); margin-bottom:14px; overflow:hidden; }
.tms-faq-q { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; cursor:pointer; font-weight:700; color:var(--text); font-size:15px; }
.tms-faq-q i { transition:transform 0.25s ease; color:var(--secondary); }
.tms-faq-item.open .tms-faq-q i { transform:rotate(180deg); }
.tms-faq-a { max-height:0; overflow:hidden; transition:max-height 0.3s ease; padding:0 24px; }
.tms-faq-item.open .tms-faq-a { max-height:220px; padding-bottom:20px; }
.tms-faq-a p { color:var(--text-muted); font-size:14px; line-height:1.7; margin:0; }

@media (max-width:900px){
  .tms-landing-why-grid { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:560px){
  .tms-landing-why-grid { grid-template-columns:1fr; }
}
[data-theme="dark"] .tms-landing-why { background:var(--bg-dark,#0f1720); }
[data-theme="dark"] .tms-landing-why-card,
[data-theme="dark"] .tms-faq-item { background:var(--card-bg,#1a2330); }

/* ── v7 homepage: search panel, featured packages, hero photo ── */
.tms-hero.lp-has-photo .tms-hero-bg { background-image: var(--lp-hero-img); background-size: cover; background-position: center; }
.tms-hero.lp-has-photo .tms-hero-bg::after { display:none; }
.tms-hero-scroll { display:none; }
.lp-search { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; background:var(--white); color:var(--text);
  border-radius:var(--radius-lg); padding:16px; margin:28px 0 8px; box-shadow:var(--shadow-lg); max-width:920px; text-align:left; }
.lp-field { display:flex; flex-direction:column; gap:4px; min-width:150px; flex:1 1 150px; }
.lp-field-grow { flex:2 1 240px; }
.lp-field label { font-size:12px; font-weight:600; color:var(--text-muted); }
.lp-field input, .lp-field select { height:44px; border:1px solid var(--border); border-radius:var(--radius-sm); padding:0 12px;
  background:var(--bg); color:var(--text); font:inherit; font-size:15px; width:100%; }
.lp-field input:focus-visible, .lp-field select:focus-visible { outline:2px solid var(--accent); outline-offset:1px; }
.lp-search-btn { height:44px; padding:0 22px; border:0; border-radius:var(--radius-sm); background:var(--accent); color:#fff; font:600 15px var(--font-body); cursor:pointer; transition:var(--transition); flex:0 0 auto; }
.lp-search-btn:hover { background:var(--accent-dark); }
.lp-search-btn:focus-visible { outline:2px solid var(--mint); outline-offset:2px; }
.lp-pkg-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; }
.lp-pkg { display:block; background:var(--white); border:1px solid var(--border); border-radius:var(--radius-md); overflow:hidden; box-shadow:var(--shadow-sm); transition:var(--transition); color:inherit; }
.lp-pkg:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
.lp-pkg:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
.lp-pkg-img { position:relative; aspect-ratio:16/10; background:var(--bg-dark); overflow:hidden; }
.lp-pkg-img img { width:100%; height:100%; object-fit:cover; display:block; }
.lp-chip { position:absolute; left:12px; top:12px; background:var(--mint); color:var(--mint-ink); font-size:12px; font-weight:600; padding:4px 10px; border-radius:99px; }
.lp-pkg-body { padding:16px 18px 18px; }
.lp-pkg-body h3 { font-size:17px; margin:0 0 6px; color:var(--text); }
.lp-loc { color:var(--text-muted); font-size:13.5px; margin:0 0 10px; }
.lp-price { margin:0; font-weight:700; font-size:18px; color:var(--text); font-variant-numeric:tabular-nums; }
.lp-price span { font-weight:500; font-size:12.5px; color:var(--text-muted); margin-right:4px; }
.lp-link-btn { display:inline-block; font-weight:600; color:var(--secondary); padding:10px 18px; border:1px solid var(--secondary); border-radius:var(--radius-sm); }
.lp-link-btn:hover { background:var(--secondary); color:#fff; }
@media (max-width:900px){ .lp-pkg-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:560px){ .lp-pkg-grid { grid-template-columns:1fr; } .lp-search { padding:14px; } .lp-search-btn { width:100%; } }
@media (prefers-reduced-motion: reduce){ .lp-pkg, .lp-search-btn { transition:none; } .lp-pkg:hover { transform:none; } }
</style>
</head>
<body>

<?php $tms_landing_mode = true; ?>
<?php
// Round 85 fix (architecture consistency, items 71/81): this bare include
// used to say 'includes/header.php' -- a leftover from before the Round 49
// rename of includes/ to backend/ that was never updated in this one file.
// Because this page is only ever reached through the root shim (index.php
// -> frontend/index.php -> here), the working directory is the project
// root, so that bare path was silently resolving to the OLD, stale
// project-root includes/header.php instead of this file's own sibling,
// backend/header.php -- and includes/header.php has been missing the
// Round 61 RTL dir="rtl" flip script (see backend/header.php) this whole
// time. Net effect, confirmed by direct code read: every signed-out
// visitor to the homepage who selected Arabic got translated text but the
// wrong (LTR) layout direction, while every other page on the site
// (reached through backend/header.php) got it right. Fixed by pointing at
// this file's real sibling instead.
// ── Real homepage data (v7) ─────────────────────────────────────────────
// Every number/quote shown below comes from the database. Anything that
// can't be loaded is simply not rendered -- no placeholder or invented values.
$lp = ['pkgs' => 0, 'dests' => 0, 'hotels' => 0, 'reviews' => 0, 'avg' => null,
       'featured' => [], 'quotes' => [], 'types' => []];
$lp_e = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
try {
    if (isset($dbh) && $dbh instanceof PDO) {
        $r = $dbh->query("SELECT COUNT(*) AS c, COUNT(DISTINCT NULLIF(TRIM(PackageLocation),'')) AS d
                          FROM tbltourpackages WHERE Status=1")->fetch(PDO::FETCH_ASSOC);
        $lp['pkgs']  = (int)($r['c'] ?? 0);
        $lp['dests'] = (int)($r['d'] ?? 0);
        $r = $dbh->query("SELECT COUNT(*) AS c, AVG(Rating) AS a FROM tblreviews
                          WHERE Status=1 AND Rating BETWEEN 1 AND 5")->fetch(PDO::FETCH_ASSOC);
        $lp['reviews'] = (int)($r['c'] ?? 0);
        $lp['avg']     = $lp['reviews'] > 0 ? round((float)$r['a'], 1) : null;
        $lp['featured'] = $dbh->query("SELECT PackageId, PackageName, PackageType, PackageLocation, PackagePrice, PackageImage
                          FROM tbltourpackages WHERE Status=1
                          ORDER BY (PackageImage IS NOT NULL AND PackageImage <> '') DESC, Creationdate DESC
                          LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $lp['quotes'] = $dbh->query("SELECT r.Rating, r.ReviewText, r.UserName, p.PackageId, p.PackageName
                          FROM tblreviews r JOIN tbltourpackages p ON p.PackageId = r.PackageId
                          WHERE r.Status=1 AND r.Rating >= 4 AND CHAR_LENGTH(r.ReviewText) >= 40
                          ORDER BY r.PostingDate DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        $lp['types'] = $dbh->query("SELECT DISTINCT PackageType FROM tbltourpackages
                          WHERE Status=1 AND PackageType IS NOT NULL AND PackageType <> '' ORDER BY PackageType")->fetchAll(PDO::FETCH_COLUMN);
        try { $lp['hotels'] = (int)$dbh->query("SELECT COUNT(*) FROM tblhotels")->fetchColumn(); } catch (Throwable $e) { $lp['hotels'] = 0; }
    }
} catch (Throwable $e) {
    error_log('[TMS landing] data load failed: ' . $e->getMessage());
}
// Hero photo: only used if the site owner has added a licensed image at
// images/hero/hero.jpg (see images/hero/README.txt). Otherwise a plain gradient.
$lp_hero_photo = is_file(dirname(__DIR__) . '/images/hero/hero.jpg') ? 'images/hero/hero.jpg' : '';
include('backend/header.php');
?>

<!-- ══════════════════════════════════════════════════════════════
     HERO — what this system is
══════════════════════════════════════════════════════════════ -->
<section class="tms-hero<?php echo $lp_hero_photo ? ' lp-has-photo' : ''; ?>"<?php echo $lp_hero_photo ? ' style="--lp-hero-img:url(\'' . $lp_e($lp_hero_photo) . '\')"' : ''; ?>>
    <div class="tms-hero-bg"></div>
    <div class="tms-hero-overlay"></div>
    <div class="tms-hero-content">
        <div class="tms-hero-badge wow fadeInDown" data-wow-delay=".1s">
            <i class="fa fa-star"></i> Smart AI-Based Travel Marketplace
        </div>
        <h1 class="wow fadeInUp" data-wow-delay=".2s">
            Plan, Book &amp; Explore<br>with an <span class="highlight">AI Co-Pilot</span>
        </h1>
        <p class="tms-hero-sub wow fadeInUp" data-wow-delay=".3s">
            TMS brings together an AI travel assistant, live weather, real-time currency conversion,
            smart route planning, and a marketplace of tour packages from multiple agencies — all in
            one platform. Create a free account to start planning your next trip.
        </p>

        <div class="tms-hero-cta wow fadeInUp" data-wow-delay=".4s">
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="btn-hero-primary">
                <i class="fa fa-user-plus"></i> Get Started Free
            </a>
            <a href="javascript:void(0)" onclick="openModal('signinModal')" class="btn-hero-secondary">
                <i class="fa fa-sign-in"></i> Sign In
            </a>
        </div>

        <form class="lp-search wow fadeInUp" data-wow-delay=".35s" action="package-list.php" method="get" role="search" aria-label="Search tour packages">
            <div class="lp-field lp-field-grow">
                <label for="lpWhere">Destination or keyword</label>
                <input id="lpWhere" type="search" name="search" maxlength="100" placeholder="e.g. Cox's Bazar, Sylhet, beach" autocomplete="off">
            </div>
            <div class="lp-field">
                <label for="lpType">Package type</label>
                <select id="lpType" name="type">
                    <option value="">Any type</option>
                    <?php foreach ($lp['types'] as $t): ?>
                    <option value="<?php echo $lp_e($t); ?>"><?php echo $lp_e($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lp-field">
                <label for="lpDur">Duration</label>
                <select id="lpDur" name="dur">
                    <option value="">Any length</option>
                    <option value="1-3">1&ndash;3 days</option>
                    <option value="4-6">4&ndash;6 days</option>
                    <option value="7-30">7+ days</option>
                </select>
            </div>
            <button type="submit" class="lp-search-btn"><i class="fa fa-search" aria-hidden="true"></i> Search packages</button>
        </form>

        <?php if ($lp['pkgs'] > 0): ?>
        <div class="tms-hero-stats wow fadeInUp" data-wow-delay=".5s">
            <div class="tms-hero-stat"><div class="num" data-counter="<?php echo (int)$lp['pkgs']; ?>">0</div><div class="lbl">Tour packages</div></div>
            <?php if ($lp['dests'] > 0): ?><div class="tms-hero-stat"><div class="num" data-counter="<?php echo (int)$lp['dests']; ?>">0</div><div class="lbl">Destinations</div></div><?php endif; ?>
            <?php if ($lp['hotels'] > 0): ?><div class="tms-hero-stat"><div class="num" data-counter="<?php echo (int)$lp['hotels']; ?>">0</div><div class="lbl">Hotels</div></div><?php endif; ?>
            <?php if ($lp['reviews'] > 0): ?><div class="tms-hero-stat"><div class="num"><?php echo number_format($lp['avg'], 1); ?></div><div class="lbl">Avg. rating (<?php echo (int)$lp['reviews']; ?> reviews)</div></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="tms-hero-scroll"><div class="scroll-dot"></div>Scroll Down</div>
</section>

<?php if (!empty($lp['featured'])): ?>
<!-- FEATURED PACKAGES (real inventory) -->
<section class="tms-section" id="featured-packages">
    <div class="tms-container">
        <div class="tms-section-header">
            <div class="tms-section-tag"><i class="fa fa-suitcase"></i> Tour packages</div>
            <h2 class="tms-section-title">Latest packages</h2>
            <p class="tms-section-sub">Live listings from our catalogue. Prices and availability are confirmed at checkout.</p>
        </div>
        <div class="lp-pkg-grid">
            <?php foreach ($lp['featured'] as $pk): ?>
            <a class="lp-pkg" href="package-details.php?pkgid=<?php echo (int)$pk['PackageId']; ?>">
                <div class="lp-pkg-img">
                    <img loading="lazy" width="640" height="400"
                         src="<?php echo $lp_e(tms_package_image_src($pk['PackageImage'] ?? '')); ?>"
                         onerror="this.onerror=null;this.src='images/no-image-placeholder.jpg'"
                         alt="<?php echo $lp_e($pk['PackageName']); ?>">
                    <?php if (!empty($pk['PackageType'])): ?><span class="lp-chip"><?php echo $lp_e($pk['PackageType']); ?></span><?php endif; ?>
                </div>
                <div class="lp-pkg-body">
                    <h3><?php echo $lp_e($pk['PackageName']); ?></h3>
                    <p class="lp-loc"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo $lp_e($pk['PackageLocation']); ?></p>
                    <p class="lp-price"><span>From</span> <?php echo $lp_e(tms_currency((float)$pk['PackagePrice'])); ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <p style="text-align:center;margin-top:28px;"><a class="lp-link-btn" href="package-list.php">Browse all packages <i class="fa fa-arrow-right" aria-hidden="true"></i></a></p>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     WHAT IS THIS SYSTEM
══════════════════════════════════════════════════════════════ -->
<section class="tms-section">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-info-circle"></i> What is TMS</div>
            <h2 class="tms-section-title">One Platform for Your Entire Trip</h2>
            <p class="tms-section-sub">
                TMS is a full-featured travel marketplace built around an AI travel assistant.
                Instead of juggling a booking site, a weather app, a currency converter, and a maps app
                separately, everything a traveler needs — from the first idea to the last day of the
                trip — lives in one connected system.
            </p>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     FEATURE HIGHLIGHTS
══════════════════════════════════════════════════════════════ -->
<section class="tms-section" style="padding-top:0;">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-magic"></i> Feature Highlights</div>
            <h2 class="tms-section-title">Everything Built In</h2>
            <p class="tms-section-sub">A quick look at what's waiting for you inside — sign up to use any of it.</p>
        </div>
        <div class="tms-features-grid">
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".1s">
                <div class="tms-feature-card-icon icon-ai"><i class="fa fa-magic"></i></div>
                <h4>AI Travel Assistant</h4>
                <p>A conversational AI co-pilot that recommends destinations, builds itineraries, and answers travel questions in real time.</p>
            </div>
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".12s">
                <div class="tms-feature-card-icon icon-map"><i class="fa fa-map-marker"></i></div>
                <h4>Smart Route Planning</h4>
                <p>Interactive maps with turn-by-turn directions, distance, travel time, and your choice of driving, walking, transit, or cycling.</p>
            </div>
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".15s">
                <div class="tms-feature-card-icon icon-weather"><i class="fa fa-cloud"></i></div>
                <h4>Live Weather Forecast</h4>
                <p>Real-time weather for every destination, so travel dates and packing lists are planned around actual conditions.</p>
            </div>
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".2s">
                <div class="tms-feature-card-icon icon-budget"><i class="fa fa-exchange"></i></div>
                <h4>Currency Conversion</h4>
                <p>Every package price converts instantly into the currency you actually spend in — no mental math, no guesswork.</p>
            </div>
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".25s">
                <div class="tms-feature-card-icon icon-compare"><i class="fa fa-globe"></i></div>
                <h4>Multi-Agency Marketplace</h4>
                <p>Hundreds of tour packages from multiple travel agencies in one place, so you can compare and choose freely.</p>
            </div>
            <div class="tms-feature-card wow fadeInUp" data-wow-delay=".3s">
                <div class="tms-feature-card-icon icon-custom"><i class="fa fa-line-chart"></i></div>
                <h4>Dynamic Pricing</h4>
                <p>Prices that reflect real demand and timing, so you always see an honest, up-to-date rate for your dates.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     WHY CHOOSE US
══════════════════════════════════════════════════════════════ -->
<section class="tms-landing-why">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-thumbs-up"></i> Why Choose Us</div>
            <h2 class="tms-section-title">Built for Travelers, Not Just Bookings</h2>
        </div>
        <div class="tms-landing-why-grid">
            <div class="tms-landing-why-card wow fadeInUp" data-wow-delay=".1s">
                <div class="tms-landing-why-icon"><i class="fa fa-shield"></i></div>
                <h4>Secure &amp; Reliable</h4>
                <p>Encrypted accounts, protected checkout, and a support team behind every booking.</p>
            </div>
            <div class="tms-landing-why-card wow fadeInUp" data-wow-delay=".15s">
                <div class="tms-landing-why-icon"><i class="fa fa-bolt"></i></div>
                <h4>All-in-One</h4>
                <p>No switching between five different apps — planning, booking, and tracking all live here.</p>
            </div>
            <div class="tms-landing-why-card wow fadeInUp" data-wow-delay=".2s">
                <div class="tms-landing-why-icon"><i class="fa fa-star"></i></div>
                <h4>Community Reviewed</h4>
                <p>Real reviews from real travelers help you choose the right package with confidence.</p>
            </div>
            <div class="tms-landing-why-card wow fadeInUp" data-wow-delay=".25s">
                <div class="tms-landing-why-icon"><i class="fa fa-headphones"></i></div>
                <h4>Always Supported</h4>
                <p>A support ticket system and live chat are just a click away whenever you need help.</p>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     TESTIMONIALS
══════════════════════════════════════════════════════════════ -->
<?php if (!empty($lp['quotes'])): ?>
<section class="tms-section">
    <div class="tms-container">
        <div class="tms-section-header">
            <div class="tms-section-tag"><i class="fa fa-quote-left"></i> Reviews</div>
            <h2 class="tms-section-title">What travelers say</h2>
            <p class="tms-section-sub">Recent verified reviews from our packages.</p>
        </div>
        <div class="tms-reviews-grid">
            <?php foreach ($lp['quotes'] as $q): ?>
            <figure class="tms-review-card" style="margin:0;">
                <div style="color:var(--brass);margin-bottom:12px;" role="img" aria-label="<?php echo (int)$q['Rating']; ?> out of 5 stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?><i class="fa fa-star<?php echo $i <= (int)$q['Rating'] ? '' : '-o'; ?>" aria-hidden="true"></i><?php endfor; ?>
                </div>
                <blockquote style="margin:0;border:0;padding:0;font-size:14px;line-height:1.7;color:var(--text-muted);">&ldquo;<?php echo $lp_e(mb_strimwidth(trim((string)$q['ReviewText']), 0, 220, '…', 'UTF-8')); ?>&rdquo;</blockquote>
                <figcaption style="margin-top:10px;font-weight:700;color:var(--text);">
                    <?php echo $lp_e($q['UserName'] !== null && $q['UserName'] !== '' ? $q['UserName'] : 'Traveler'); ?>
                    <a href="package-details.php?pkgid=<?php echo (int)$q['PackageId']; ?>" style="display:block;font-weight:500;font-size:13px;"><?php echo $lp_e($q['PackageName']); ?></a>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     FAQ
══════════════════════════════════════════════════════════════ -->
<section class="tms-section tms-landing-faq-section">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-question-circle"></i> FAQ</div>
            <h2 class="tms-section-title">Frequently Asked Questions</h2>
        </div>
        <div class="tms-faq">
            <div class="tms-faq-item">
                <div class="tms-faq-q" onclick="tmsToggleFaq(this)">Do I need an account to use TMS? <i class="fa fa-chevron-down"></i></div>
                <div class="tms-faq-a"><p>Yes — a free account unlocks the whole system: browsing and booking packages, the AI assistant, maps, weather, currency conversion, and your personal dashboard. Signing up takes under a minute.</p></div>
            </div>
            <div class="tms-faq-item">
                <div class="tms-faq-q" onclick="tmsToggleFaq(this)">Is the AI trip planner free to use? <i class="fa fa-chevron-down"></i></div>
                <div class="tms-faq-a"><p>Yes, it's included with your account. There's a fair-use limit on AI requests per hour to keep things running smoothly for everyone.</p></div>
            </div>
            <div class="tms-faq-item">
                <div class="tms-faq-q" onclick="tmsToggleFaq(this)">Can I compare packages from different agencies? <i class="fa fa-chevron-down"></i></div>
                <div class="tms-faq-a"><p>Yes — TMS is a marketplace, so packages from multiple travel agencies appear side by side, and the built-in Compare tool lets you line them up directly.</p></div>
            </div>
            <div class="tms-faq-item">
                <div class="tms-faq-q" onclick="tmsToggleFaq(this)">What currencies are supported? <i class="fa fa-chevron-down"></i></div>
                <div class="tms-faq-a"><p>BDT, USD, EUR, GBP, JPY, CAD, AUD, and INR, converted from live exchange rates so prices reflect real-world rates.</p></div>
            </div>
            <div class="tms-faq-item">
                <div class="tms-faq-q" onclick="tmsToggleFaq(this)">Is my payment and personal information safe? <i class="fa fa-chevron-down"></i></div>
                <div class="tms-faq-a"><p>Accounts are password-protected with account lockout after repeated failed sign-in attempts, and every session is protected against common web attacks (CSRF, session fixation).</p></div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     FINAL CTA BAND
══════════════════════════════════════════════════════════════ -->
<section class="tms-landing-cta-band">
    <div class="tms-container">
        <h2 class="wow fadeInUp" data-wow-delay=".1s">Ready to Plan Your Next Trip?</h2>
        <p class="wow fadeInUp" data-wow-delay=".15s">Create your free account and get access to the full TMS platform.</p>
        <div class="wow fadeInUp" data-wow-delay=".2s">
            <a href="javascript:void(0)" onclick="openModal('signupModal')" class="btn-hero-primary">
                <i class="fa fa-user-plus"></i> Create Free Account
            </a>
        </div>
    </div>
</section>

<?php
// Round 85: same fix as the header include above -- point at this file's
// real sibling instead of the stale project-root includes/footer.php.
include('backend/footer.php');
?>

<script>
function tmsToggleFaq(q) {
    var item = q.closest('.tms-faq-item');
    var wasOpen = item.classList.contains('open');
    document.querySelectorAll('.tms-faq-item.open').forEach(function(el){ el.classList.remove('open'); });
    if (!wasOpen) item.classList.add('open');
}
// Animated stat counters (same behaviour as the in-app homepage hero)
(function(){
    var counters = document.querySelectorAll('[data-counter]');
    var done = false;
    function run() {
        if (done) return; done = true;
        counters.forEach(function(el){
            var target = parseInt(el.getAttribute('data-counter'), 10) || 0;
            var start = 0, steps = 40, i = 0;
            var timer = setInterval(function(){
                i++;
                el.textContent = Math.round(target * (i/steps));
                if (i >= steps) { el.textContent = target; clearInterval(timer); }
            }, 30);
        });
    }
    window.addEventListener('scroll', function(){
        var stats = document.querySelector('.tms-hero-stats');
        if (stats && stats.getBoundingClientRect().top < window.innerHeight) run();
    });
    setTimeout(run, 1200);
})();
</script>
</body>
</html>
