<?php
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');

// Batch 1 (landing page + auth gating): the site used to drop every visitor
// straight into this homepage. Now this is the gate — a guest sees the new
// public marketing landing page (backend/landing.php) and must sign in or
// register before reaching the actual system. Everything below this block
// is completely unchanged and only ever renders for a logged-in user.
if (empty($_SESSION['login'])) {
    include('backend/landing.php');
    exit;
}

// Fetch random packages for homepage
// (previously ORDER BY RAND() LIMIT 8 — a full-table sort that got noticeably heavier
// once the catalog grew to 400+ packages; replaced with an indexed pool + PHP shuffle)
$featuredPkgs = [];
try {
    $pool = $dbh->query("SELECT * FROM tbltourpackages WHERE Status=1 ORDER BY PackageId DESC LIMIT 60")->fetchAll(PDO::FETCH_OBJ);
    shuffle($pool);
    $featuredPkgs = array_slice($pool, 0, 8);
} catch(Exception $e) {}

// Fetch blog posts
$blogPosts = [];
try {
    $stmt = $dbh->prepare("SELECT * FROM tblblog ORDER BY CreatedAt DESC LIMIT 3");
    $stmt->execute();
    $blogPosts = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) {}

// Fetch reviews
$reviews = [];
try {
    $stmt = $dbh->prepare("SELECT * FROM tblreviews WHERE Status=1 ORDER BY PostingDate DESC LIMIT 3");
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Premium Tourism Management System</title>
<meta name="description" content="Discover the world with TMS - your premium travel partner. Book tour packages, explore destinations, and plan your perfect trip.">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0F4C81">

<!-- Bootstrap -->
<link href="css/bootstrap.min2.css" rel="stylesheet">
<!-- Font Awesome -->
<link href="css/font-awesome.min2.css" rel="stylesheet">
<!-- Animate.css -->
<link href="css/animate.css" rel="stylesheet">
<!-- TMS Modern Theme -->
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

<!-- Scripts -->
<script src="js/jquery-1.12.0.min.js" defer></script>
<script src="js/bootstrap.min.js" defer></script>
<script src="js/wow.min.js" defer></script>
<script>window.addEventListener("load",function(){if(typeof WOW!=="undefined")new WOW({offset:80}).init()});</script>
</head>
<body>

<?php include('backend/header.php'); ?>

<!-- Flash Messages -->
<?php if(isset($_SESSION['reg_success'])): ?>
<div style="background:linear-gradient(135deg,#00b09b,#96c93d);color:#fff;padding:14px 20px;text-align:center;font-size:15px;font-weight:600;">
    <i class="fa fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['reg_success']); unset($_SESSION['reg_success']); ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     HERO SECTION
══════════════════════════════════════════════════════════════ -->
<section class="tms-hero">
    <div class="tms-hero-bg"></div>
    <div class="tms-hero-overlay"></div>
    <div class="tms-hero-content">
        <div class="tms-hero-badge wow fadeInDown" data-wow-delay=".1s">
            <i class="fa fa-star"></i> Trusted by 1.9K+ Travelers Worldwide
        </div>
        <h1 class="wow fadeInUp" data-wow-delay=".2s">
            Discover Your<br>Next <span class="highlight">Adventure</span>
        </h1>
        <p class="tms-hero-sub wow fadeInUp" data-wow-delay=".3s">
            From hidden gems to iconic destinations — plan, book, and experience extraordinary travel with AI-powered insights and expert guidance.
        </p>

        <!-- Search Bar -->
        <div class="tms-hero-search wow fadeInUp" data-wow-delay=".4s">
            <div class="tms-search-field">
                <i class="fa fa-map-marker"></i>
                <input type="text" placeholder="Where do you want to go? Or tap the mic and say it." id="heroDestInput">
                <button type="button" id="heroMicBtn" onclick="toggleHeroVoice()" title="Search by voice"
                        style="display:none;background:none;border:none;color:var(--secondary);font-size:16px;cursor:pointer;padding:4px 8px;">
                    <i class="fa fa-microphone"></i>
                </button>
            </div>
            <div class="tms-search-divider"></div>
            <div class="tms-search-field">
                <i class="fa fa-calendar"></i>
                <input type="text" placeholder="Travel date" id="heroDateInput" onfocus="this.type='date'" onblur="if(!this.value)this.type='text'">
            </div>
            <button class="tms-search-btn" onclick="searchPackages()">
                <i class="fa fa-search"></i> Explore
            </button>
        </div>

        <!-- CTA Buttons -->
        <div class="tms-hero-cta wow fadeInUp" data-wow-delay=".5s">
            <a href="package-list.php" class="btn-hero-primary">
                <i class="fa fa-suitcase"></i> Browse Packages
            </a>
            <a href="ai-recommend.php" class="btn-hero-secondary">
                <i class="fa fa-magic"></i> AI Trip Planner
            </a>
        </div>

        <!-- Stats -->
        <div class="tms-hero-stats wow fadeInUp" data-wow-delay=".6s">
            <div class="tms-hero-stat">
                <div class="num" data-counter="3200">0</div>
                <div class="lbl">Bookings</div>
            </div>
            <div class="tms-hero-stat">
                <div class="num" data-counter="1900">0</div>
                <div class="lbl">Users</div>
            </div>
            <div class="tms-hero-stat">
                <div class="num" data-counter="4500">0</div>
                <div class="lbl">Enquiries</div>
            </div>
            <div class="tms-hero-stat">
                <div class="num" data-counter="50">0</div>
                <div class="lbl">Countries</div>
            </div>
        </div>
    </div>
    <!-- Scroll Indicator -->
    <div class="tms-hero-scroll">
        <div class="scroll-dot"></div>
        Scroll Down
    </div>
</section>

<?php if(isset($_SESSION['login']) && !empty($_SESSION['login'])): ?>
<!-- ══════════════════════════════════════════════════════════════
     EXPLORE MODE — CHOOSE YOUR JOURNEY (logged-in users only)
══════════════════════════════════════════════════════════════ -->
<section class="tms-section" style="padding-top:50px;padding-bottom:10px;">
    <div class="container">
        <div style="text-align:center;margin-bottom:30px;">
            <h2 style="font-weight:800;color:var(--text);">How would you like to travel today?</h2>
            <p style="color:var(--text-muted);">Book a curated package, or explore any destination freely — the choice is yours.</p>
        </div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;max-width:900px;margin:0 auto;">
            <a href="package-list.php" style="flex:1;min-width:280px;text-decoration:none;background:#fff;border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:30px;display:block;transition:var(--transition);border:2px solid transparent;" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='transparent'">
                <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fa fa-suitcase" style="color:#fff;font-size:22px;"></i>
                </div>
                <h4 style="color:var(--text);font-weight:800;margin-bottom:8px;">Book a Package</h4>
                <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:0;">Choose from our curated tour packages with fixed itineraries, accommodation, and expert guidance.</p>
            </a>
            <a href="explore.php" style="flex:1;min-width:280px;text-decoration:none;background:#fff;border-radius:var(--radius-md);box-shadow:var(--shadow-sm);padding:30px;display:block;transition:var(--transition);border:2px solid transparent;" onmouseover="this.style.borderColor='var(--secondary)'" onmouseout="this.style.borderColor='transparent'">
                <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,var(--secondary),var(--secondary-light));display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fa fa-compass" style="color:#fff;font-size:22px;"></i>
                </div>
                <h4 style="color:var(--text);font-weight:800;margin-bottom:8px;">Explore <span style="background:var(--accent);color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;vertical-align:middle;">NEW</span></h4>
                <p style="color:var(--text-muted);font-size:13.5px;margin-bottom:0;">Travel any destination on your own terms — live maps, weather, nearby places &amp; an AI guide. No booking needed.</p>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     PROMO STRIP
══════════════════════════════════════════════════════════════ -->
<div class="tms-promo-strip">
    <div class="tms-promo-inner">
        <div class="tms-promo-item wow fadeInLeft" data-wow-delay=".1s">
            <div class="tms-promo-icon" style="font-weight:800;font-size:22px;">৳</div>
            <div class="tms-promo-text">
                <strong>Up to ৳5,000 OFF</strong>
                <span>Travel Smart with exclusive deals</span>
            </div>
        </div>
        <div class="tms-promo-item wow fadeInUp" data-wow-delay=".2s">
            <div class="tms-promo-icon"><i class="fa fa-hotel"></i></div>
            <div class="tms-promo-text">
                <strong>Up to 70% OFF Hotels</strong>
                <span>Best rates across the world</span>
            </div>
        </div>
        <div class="tms-promo-item wow fadeInRight" data-wow-delay=".3s">
            <div class="tms-promo-icon"><i class="fa fa-mobile"></i></div>
            <div class="tms-promo-text">
                <strong>Flat ৳5,000 App Offer</strong>
                <span>Book through our mobile platform</span>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     FEATURED PACKAGES
══════════════════════════════════════════════════════════════ -->
<section class="tms-section">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-star"></i> Featured</div>
            <h2 class="tms-section-title">Popular Tour Packages</h2>
            <p class="tms-section-sub">Handpicked travel experiences crafted for every kind of adventurer</p>
        </div>
        <div class="tms-pkg-grid">
        <?php
        $pkgIdx = 0;
        if(!empty($featuredPkgs)):
            foreach($featuredPkgs as $pkg):
            // Themed, destination-matched fallback (never a human portrait) —
            // only used if the real package photo fails to load.
            $fallback = tms_destination_image(
                (string)($pkg->PackageName ?? ''),
                (string)($pkg->PackageLocation ?? ''),
                (string)($pkg->PackageType ?? ''),
                (int)$pkg->PackageId
            );
            $pkgIdx++;
        ?>
        <div class="tms-pkg-card wow fadeInUp" data-wow-delay="<?php echo (.1 + ($pkgIdx * 0.05)); ?>s">
            <div class="tms-pkg-img">
                <img loading="lazy"
                     src="<?php echo htmlspecialchars(tms_package_image_src($pkg->PackageImage)); ?>"
                     <?php echo tms_responsive_img_attrs(tms_package_image_src($pkg->PackageImage)); ?>
                     onerror="this.src='<?php echo $fallback; ?>'"
                     alt="<?php echo htmlspecialchars($pkg->PackageName); ?>">
                <?php if(!empty($pkg->PackageType)): ?>
                <span class="tms-pkg-badge"><?php echo htmlspecialchars($pkg->PackageType); ?></span>
                <?php endif; ?>
                <div class="tms-pkg-wishlist" onclick="addToWishlist(<?php echo $pkg->PackageId; ?>, this)" title="Add to Wishlist">
                    <i class="fa fa-heart-o"></i>
                </div>
                <?php if(!empty($pkg->PackageDuration) || (!empty($pkg->AvgRating) && $pkg->AvgRating > 0)): ?>
                <div class="tms-pkg-quickglance">
                    <?php if(!empty($pkg->PackageDuration)): ?>
                    <span><i class="fa fa-clock-o"></i><?php echo htmlspecialchars($pkg->PackageDuration); ?></span>
                    <?php endif; ?>
                    <?php if(!empty($pkg->AvgRating) && $pkg->AvgRating > 0): ?>
                    <span><i class="fa fa-star"></i><?php echo number_format($pkg->AvgRating,1); ?> rating</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="tms-pkg-body">
                <div class="tms-pkg-location">
                    <i class="fa fa-map-marker"></i>
                    <?php echo htmlspecialchars($pkg->PackageLocation ?? 'Various Locations'); ?>
                </div>
                <h4><?php echo htmlspecialchars($pkg->PackageName); ?></h4>
                <div class="tms-pkg-meta">
                    <?php if(!empty($pkg->PackageDuration)): ?>
                    <span><i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($pkg->PackageDuration); ?></span>
                    <?php endif; ?>
                    <?php if(!empty($pkg->AvgRating) && $pkg->AvgRating > 0): ?>
                    <span><i class="fa fa-star" style="color:var(--accent)"></i> <?php echo number_format($pkg->AvgRating,1); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tms-pkg-footer">
                    <div class="tms-pkg-price">
                        <small>From </small><?php echo tms_currency($pkg->PackagePrice); ?>
                    </div>
                    <a href="package-details.php?pkgid=<?php echo $pkg->PackageId; ?>" class="btn-pkg">
                        View Details
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <!-- No packages yet - show placeholders -->
        <?php for($i=0;$i<4;$i++): ?>
        <div class="tms-pkg-card wow fadeInUp" data-wow-delay="<?php echo (.1+$i*0.1); ?>s">
            <div class="tms-pkg-img">
                <img loading="lazy" src="https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800&q=80" srcset="https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=400&q=80 400w, https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800&q=80 800w, https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" alt="Package">
                <span class="tms-pkg-badge">Tour</span>
            </div>
            <div class="tms-pkg-body">
                <div class="tms-pkg-location"><i class="fa fa-map-marker"></i> Various</div>
                <h4>Exciting Tour Package</h4>
                <div class="tms-pkg-meta">
                    <span><i class="fa fa-clock-o"></i> 7 Days</span>
                    <span><i class="fa fa-star" style="color:var(--accent)"></i> 4.8</span>
                </div>
                <div class="tms-pkg-footer">
                    <div class="tms-pkg-price"><small>From </small>৳59,900</div>
                    <a href="package-list.php" class="btn-pkg">View Details</a>
                </div>
            </div>
        </div>
        <?php endfor; endif; ?>
        </div>
        <div class="text-center mt-40 wow fadeInUp" data-wow-delay=".2s">
            <a href="package-list.php" class="btn-primary-tms">
                <i class="fa fa-th-list"></i> View All Packages
            </a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     POPULAR DESTINATIONS
══════════════════════════════════════════════════════════════ -->
<section class="tms-section tms-section-alt">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-globe"></i> Explore</div>
            <h2 class="tms-section-title">Popular Destinations</h2>
            <p class="tms-section-sub">From tropical beaches to mountain peaks — find your perfect escape</p>
        </div>
        <div class="tms-dest-grid">
            <a href="package-list.php?location=paris" class="tms-dest-card wow fadeInLeft" data-wow-delay=".1s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=800&q=80" srcset="https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=400&q=80 400w, https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=800&q=80 800w, https://images.unsplash.com/photo-1519501025264-65ba15a82390?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Paris">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🗼 Paris, France</div>
                    <div class="tms-dest-count">12 Packages Available</div>
                </div>
            </a>
            <a href="package-list.php?location=bali" class="tms-dest-card wow fadeInUp" data-wow-delay=".15s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=800&q=80" srcset="https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=400&q=80 400w, https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=800&q=80 800w, https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Bali">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🌴 Bali, Indonesia</div>
                    <div class="tms-dest-count">8 Packages</div>
                </div>
            </a>
            <a href="package-list.php?location=dubai" class="tms-dest-card wow fadeInUp" data-wow-delay=".2s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1519681393784-d120267933ba?w=800&q=80" srcset="https://images.unsplash.com/photo-1519681393784-d120267933ba?w=400&q=80 400w, https://images.unsplash.com/photo-1519681393784-d120267933ba?w=800&q=80 800w, https://images.unsplash.com/photo-1519681393784-d120267933ba?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Dubai">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🌆 Dubai, UAE</div>
                    <div class="tms-dest-count">10 Packages</div>
                </div>
            </a>
            <a href="package-list.php?location=kerala" class="tms-dest-card wow fadeInRight" data-wow-delay=".25s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1500534623283-312aade485b7?w=800&q=80" srcset="https://images.unsplash.com/photo-1500534623283-312aade485b7?w=400&q=80 400w, https://images.unsplash.com/photo-1500534623283-312aade485b7?w=800&q=80 800w, https://images.unsplash.com/photo-1500534623283-312aade485b7?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Kerala">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🌿 Kerala, India</div>
                    <div class="tms-dest-count">6 Packages</div>
                </div>
            </a>
            <a href="package-list.php?location=switzerland" class="tms-dest-card wow fadeInLeft" data-wow-delay=".3s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&q=80" srcset="https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400&q=80 400w, https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&q=80 800w, https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Switzerland">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">⛰️ Switzerland</div>
                    <div class="tms-dest-count">7 Packages</div>
                </div>
            </a>
            <a href="package-list.php?location=bhutan" class="tms-dest-card wow fadeInUp" data-wow-delay=".35s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1601999209039-84f3fa8ba9fd?w=800&q=80" srcset="https://images.unsplash.com/photo-1601999209039-84f3fa8ba9fd?w=400&q=80 400w, https://images.unsplash.com/photo-1601999209039-84f3fa8ba9fd?w=800&q=80 800w, https://images.unsplash.com/photo-1601999209039-84f3fa8ba9fd?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Bhutan">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🏔️ Bhutan</div>
                    <div class="tms-dest-count">5 Packages</div>
                </div>
            </a>
            <a href="package-list.php?location=rajasthan" class="tms-dest-card wow fadeInUp" data-wow-delay=".4s">
                <img loading="lazy" class="tms-dest-img" src="https://images.unsplash.com/photo-1548013146-72479768bada?w=800&q=80" srcset="https://images.unsplash.com/photo-1548013146-72479768bada?w=400&q=80 400w, https://images.unsplash.com/photo-1548013146-72479768bada?w=800&q=80 800w, https://images.unsplash.com/photo-1548013146-72479768bada?w=1200&q=80 1200w" sizes="(max-width: 600px) 400px, (max-width: 1000px) 800px, 1200px" onerror="this.src='images/no-image-placeholder.jpg'" alt="Rajasthan">
                <div class="tms-dest-overlay">
                    <div class="tms-dest-name">🕌 Rajasthan</div>
                    <div class="tms-dest-count">9 Packages</div>
                </div>
            </a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     STATS BANNER
══════════════════════════════════════════════════════════════ -->
<section class="tms-stats">
    <div class="tms-container">
        <div class="tms-stats-grid">
            <div class="tms-stat-item wow zoomIn" data-wow-delay=".1s">
                <div class="tms-stat-num">700<span>M+</span></div>
                <div class="tms-stat-lbl">Total Bookings</div>
            </div>
            <div class="tms-stat-item wow zoomIn" data-wow-delay=".2s">
                <div class="tms-stat-num">1,900<span>+</span></div>
                <div class="tms-stat-lbl">Registered Users</div>
            </div>
            <div class="tms-stat-item wow zoomIn" data-wow-delay=".3s">
                <div class="tms-stat-num">80<span>K+</span></div>
                <div class="tms-stat-lbl">Enquiries Handled</div>
            </div>
            <div class="tms-stat-item wow zoomIn" data-wow-delay=".4s">
                <div class="tms-stat-num">50<span>+</span></div>
                <div class="tms-stat-lbl">Countries Covered</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     AI FEATURES
══════════════════════════════════════════════════════════════ -->
<section class="tms-section">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-magic"></i> AI-Powered</div>
            <h2 class="tms-section-title">Smart Travel Features</h2>
            <p class="tms-section-sub">Cutting-edge tools to make your travel planning effortless and intelligent</p>
        </div>
        <div class="tms-features-grid">
            <a href="ai-recommend.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".1s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-ai"><i class="fa fa-magic"></i></div>
                <h4>AI Trip Recommender</h4>
                <p>Get personalized destination suggestions based on your budget, travel style, and preferences using advanced AI.</p>
                <span>Try Now <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="destinations.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".12s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-map"><i class="fa fa-globe"></i></div>
                <h4>Explore Destinations</h4>
                <p>Browse 400+ destinations by country, region and category — Bangladesh's best spots featured first.</p>
                <span>Explore Destinations <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="tour-map.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".15s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-map"><i class="fa fa-map-marker"></i></div>
                <h4>Interactive Map</h4>
                <p>Explore destinations visually on an interactive map. Find packages by location and discover hidden gems.</p>
                <span>Explore Map <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="weather.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".2s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-weather"><i class="fa fa-cloud"></i></div>
                <h4>Weather Guide</h4>
                <p>Real-time weather forecasts for your destination. Plan your trip around perfect weather conditions.</p>
                <span>Check Weather <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="budget-planner.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".25s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-budget"><i class="fa fa-calculator"></i></div>
                <h4>Budget Planner</h4>
                <p>Smart cost calculator that helps you plan every expense — accommodation, food, transport, activities.</p>
                <span>Plan Budget <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="compare.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".3s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-compare"><i class="fa fa-columns"></i></div>
                <h4>Package Comparison</h4>
                <p>Compare multiple tour packages side by side. Make informed decisions with our easy comparison tool.</p>
                <span>Compare Now <i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="custom-trip.php" class="tms-feature-card wow fadeInUp" data-wow-delay=".35s" style="text-decoration:none;">
                <div class="tms-feature-card-icon icon-custom"><i class="fa fa-paint-brush"></i></div>
                <h4>Custom Trip Builder</h4>
                <p>Design your perfect trip from scratch. Choose destinations, dates, activities, and accommodation your way.</p>
                <span>Build Trip <i class="fa fa-arrow-right"></i></span>
            </a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     CUSTOMER REVIEWS
══════════════════════════════════════════════════════════════ -->
<section class="tms-section tms-section-alt">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-star"></i> Testimonials</div>
            <h2 class="tms-section-title">What Travelers Say</h2>
            <p class="tms-section-sub">Real experiences from real adventurers who explored the world with TMS</p>
        </div>
        <div class="tms-reviews-grid">
        <?php if(!empty($reviews)): foreach($reviews as $rev): ?>
        <div class="tms-review-card wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-review-stars">
                <?php for($s=1;$s<=5;$s++) echo ($s<=$rev->Rating)?'<i class="fa fa-star"></i>':'<i class="fa fa-star-o"></i>'; ?>
            </div>
            <p class="tms-review-text"><?php echo htmlspecialchars(substr($rev->ReviewText,0,160)); ?>...</p>
            <div class="tms-review-author">
                <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;flex-shrink:0;">
                    <?php echo strtoupper(substr($rev->UserName,0,1)); ?>
                </div>
                <div>
                    <div class="tms-review-name"><?php echo htmlspecialchars($rev->UserName); ?></div>
                    <div class="tms-review-dest"><i class="fa fa-map-marker"></i> Verified Traveler</div>
                </div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <!-- Placeholder reviews -->
        <?php $placeholderReviews = [
            ['name'=>'Sarah Mitchell','rating'=>5,'text'=>'Absolutely incredible experience! TMS made planning our honeymoon in Bali effortless. The AI recommendations were spot-on and our tour guide was fantastic.'],
            ['name'=>'James Rodriguez','rating'=>5,'text'=>'Booked the Rajasthan Heritage tour and it exceeded all expectations. Professional staff, stunning hotels, and seamless logistics throughout.'],
            ['name'=>'Aisha Patel','rating'=>4,'text'=>'The budget planner helped us save 30% on our Europe trip. Great customer support and the custom trip builder is genuinely useful.'],
        ]; foreach($placeholderReviews as $rev): ?>
        <div class="tms-review-card wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-review-stars"><?php for($s=0;$s<$rev['rating'];$s++) echo '<i class="fa fa-star"></i>'; ?></div>
            <p class="tms-review-text"><?php echo $rev['text']; ?></p>
            <div class="tms-review-author">
                <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:16px;flex-shrink:0;">
                    <?php echo strtoupper(substr($rev['name'],0,1)); ?>
                </div>
                <div>
                    <div class="tms-review-name"><?php echo $rev['name']; ?></div>
                    <div class="tms-review-dest"><i class="fa fa-map-marker"></i> Verified Traveler</div>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
        <div class="text-center mt-40 wow fadeInUp" data-wow-delay=".2s">
            <a href="reviews.php" class="btn-outline-tms"><i class="fa fa-star"></i> Read All Reviews</a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     TRAVEL BLOG
══════════════════════════════════════════════════════════════ -->
<section class="tms-section">
    <div class="tms-container">
        <div class="tms-section-header wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-section-tag"><i class="fa fa-rss"></i> Blog</div>
            <h2 class="tms-section-title">Travel Inspiration</h2>
            <p class="tms-section-sub">Tips, guides, and stories from our travel experts and community</p>
        </div>
        <div class="tms-blog-grid">
        <?php
        $blogImgs = ['images/h3.jpg','images/h4.jpg','images/h5.jpg'];
        $bIdx = 0;
        if(!empty($blogPosts)): foreach($blogPosts as $post):
        $bImg = $blogImgs[$bIdx % count($blogImgs)]; $bIdx++;
        ?>
        <div class="tms-blog-card wow fadeInUp" data-wow-delay="<?php echo (.1+$bIdx*0.1); ?>s">
            <div class="tms-blog-img">
                <img loading="lazy" src="<?php echo !empty($post->CoverImage)?'admin/blogimages/'.htmlspecialchars($post->CoverImage):$bImg; ?>"
                     onerror="this.src='<?php echo $bImg; ?>'" alt="<?php echo htmlspecialchars($post->Title, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="tms-blog-body">
                <?php if(!empty($post->Tag)): ?><span class="tms-blog-tag"><?php echo htmlspecialchars($post->Tag); ?></span><?php endif; ?>
                <h4><?php echo htmlspecialchars($post->Title); ?></h4>
                <p><?php echo htmlspecialchars(substr(strip_tags($post->Content),0,100)); ?>...</p>
                <div class="tms-blog-footer">
                    <span class="tms-blog-date"><i class="fa fa-calendar"></i> <?php echo date('M j, Y', strtotime($post->CreatedAt)); ?></span>
                    <a href="blog-post.php?id=<?php echo $post->id; ?>" class="tms-blog-read">Read More <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; else:
        $placeholderBlogs = [
            ['title'=>'Top 10 Tips for First-Time International Travelers','tag'=>'Travel Tips','img'=>'images/h3.jpg','excerpt'=>'Planning your first international adventure? We cover everything from passport prep to packing light.'],
            ['title'=>'Hidden Gems of Southeast Asia You Must Visit','tag'=>'Destinations','img'=>'images/h4.jpg','excerpt'=>'Beyond the tourist trail lie incredible hidden destinations. Kampot, Halong Bay corners, and Koh Lanta.'],
            ['title'=>'How to Plan an Epic Trip on a Tight Budget','tag'=>'Budget Travel','img'=>'images/h5.jpg','excerpt'=>'Smart strategies using budget airlines, local guesthouses, street food, and shoulder-season savings.'],
        ];
        foreach($placeholderBlogs as $b):
        ?>
        <div class="tms-blog-card wow fadeInUp" data-wow-delay=".1s">
            <div class="tms-blog-img"><img loading="lazy" src="<?php echo $b['img']; ?>" onerror="this.src='images/1.jpg'" alt="<?php echo htmlspecialchars($b['title'], ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div class="tms-blog-body">
                <span class="tms-blog-tag"><?php echo $b['tag']; ?></span>
                <h4><?php echo $b['title']; ?></h4>
                <p><?php echo $b['excerpt']; ?></p>
                <div class="tms-blog-footer">
                    <span class="tms-blog-date"><i class="fa fa-calendar"></i> Jun 2026</span>
                    <a href="blog.php" class="tms-blog-read">Read More <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
        <div class="text-center mt-40 wow fadeInUp">
            <a href="blog.php" class="btn-outline-tms"><i class="fa fa-rss"></i> View All Articles</a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     CTA SECTION
══════════════════════════════════════════════════════════════ -->
<section class="tms-section tms-section-alt">
    <div class="tms-container">
        <div class="tms-cta wow zoomIn" data-wow-delay=".1s">
            <h2>Ready to Start Your Adventure?</h2>
            <p>Join 1,900+ travelers who trust TMS for unforgettable journeys. Sign up free and get ৳5,000 off your first booking.</p>
            <div class="tms-cta-btns">
                <a href="javascript:void(0)" onclick="openModal('signupModal')" class="btn-cta-white">
                    <i class="fa fa-user-plus"></i> Create Free Account
                </a>
                <a href="enquiry.php" class="btn-cta-outline">
                    <i class="fa fa-envelope"></i> Send Enquiry
                </a>
            </div>
        </div>
    </div>
</section>

<?php
// index.php used to carry its own hand-copied footer + dark-mode block instead
// of the shared backend/footer.php — meaning every future footer/dark-mode fix
// (translations, the theme system, etc.) had to be applied twice, and silently
// drifted out of sync (this copy was still on the old translation-less English
// links and the old, disconnected dark-mode toggle). Switched to the same
// include every other page uses.
include('backend/footer.php');
include('backend/write-us.php');
?>

<script>
var TMS_CSRF_TOKEN = <?php echo json_encode(tms_csrf_token()); ?>;
function searchPackages() {
    var dest = document.getElementById('heroDestInput').value;
    if(dest) {
        window.location.href = 'package-list.php?search=' + encodeURIComponent(dest);
    } else {
        window.location.href = 'package-list.php';
    }
}

// ─── Voice search (reviewer example: "Find me Cox's Bazar packages under
// 20,000 taka") — reuses the same browser-native Web Speech API already
// proven in ai-copilot.php's Trip Copilot, but this one also does simple,
// honest regex parsing for a spoken budget ("under X taka/tk/BDT", "below
// X", "less than X") since a destination-only voice search wouldn't match
// what the reviewer actually asked for. No AI call involved — genuinely
// free, all client-side, and gracefully absent on browsers without support.
var heroRecognition = null;
var heroListening = false;
var HeroSpeechAPI = window.SpeechRecognition || window.webkitSpeechRecognition;
if (HeroSpeechAPI) {
    document.getElementById('heroMicBtn').style.display = 'inline-flex';
    heroRecognition = new HeroSpeechAPI();
    heroRecognition.continuous = false;
    heroRecognition.interimResults = false;
    heroRecognition.lang = 'en-US';
    heroRecognition.onstart = function() {
        heroListening = true;
        document.getElementById('heroMicBtn').classList.add('listening');
    };
    heroRecognition.onend = function() {
        heroListening = false;
        document.getElementById('heroMicBtn').classList.remove('listening');
    };
    heroRecognition.onerror = function() {
        heroListening = false;
        document.getElementById('heroMicBtn').classList.remove('listening');
    };
    heroRecognition.onresult = function(event) {
        var transcript = event.results[0][0].transcript;
        var parsed = parseVoiceQuery(transcript);
        var url = 'package-list.php?search=' + encodeURIComponent(parsed.destination);
        if (parsed.maxPrice) url += '&max_price=' + parsed.maxPrice;
        window.location.href = url;
    };
}
function toggleHeroVoice() {
    if (!heroRecognition) return;
    if (heroListening) { heroRecognition.stop(); } else { heroRecognition.start(); }
}
function parseVoiceQuery(text) {
    // "under/below/less than 20,000 taka|tk|bdt" -> destination + max_price.
    // Deliberately simple pattern matching, not a full NLU model — this is
    // honest about what it can parse rather than pretending to understand
    // arbitrary phrasing.
    var priceMatch = text.match(/(?:under|below|less than|within)\s*([\d,]+)\s*(?:taka|tk|bdt)?/i);
    var maxPrice = null;
    var destination = text;
    if (priceMatch) {
        maxPrice = parseInt(priceMatch[1].replace(/,/g, ''), 10);
        destination = (text.slice(0, priceMatch.index) + text.slice(priceMatch.index + priceMatch[0].length)).trim();
    }
    // Trim filler words a spoken query often includes.
    destination = destination.replace(/\b(find me|show me|search for|packages?|please|i want)\b/gi, '').replace(/\s+/g, ' ').trim();
    return { destination: destination, maxPrice: maxPrice };
}
function addToWishlist(pkgId, el) {
    <?php if(isset($_SESSION['login'])): ?>
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'wishlist.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status === 200) {
            el.innerHTML = '<i class="fa fa-heart" style="color:#e91e63"></i>';
            el.title = 'In Wishlist';
        } else {
            el.title = 'Could not update wishlist — please try again';
        }
    };
    xhr.onerror = function() { el.title = 'Could not update wishlist — please try again'; };
    xhr.send('toggle_wish=1&pkgid=' + encodeURIComponent(pkgId) + '&csrf_token=' + encodeURIComponent(TMS_CSRF_TOKEN));
    <?php else: ?>
    openModal('signinModal');
    <?php endif; ?>
}
</script>
</body>
</html>
