<?php
/**
 * backend/home-sections.php — the single home page body, shared by the signed-out landing (backend/landing.php)
 * and the signed-in home (frontend/index.php). Needs $dbh, config.php helpers and (optionally) $tms_home_signed_in.
 * Only real data is shown: counts, destinations, packages and quotes come from the database; sample/demo rows are flagged.
 * Styles: css/tms-pages.css (+ theme tokens). Motion: js/tms-motion.js (.tms-reveal, .tms-count, .tms-hero-slides).
 */
if (!isset($dbh) || !($dbh instanceof PDO)) { return; }
$tms_home_signed_in = !empty($tms_home_signed_in);
$hs_e = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$hs = ['pkgs' => 0, 'dests' => 0, 'hotels' => 0, 'reviews' => 0, 'avg' => null, 'featured' => [], 'destinations' => [], 'quotes' => []];
try {
    $r = $dbh->query("SELECT COUNT(*) AS c, COUNT(DISTINCT NULLIF(TRIM(PackageLocation),'')) AS d FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL)->fetch(PDO::FETCH_ASSOC);
    $hs['pkgs'] = (int)($r['c'] ?? 0);
    $hs['dests'] = (int)($r['d'] ?? 0);
    $r = $dbh->query("SELECT COUNT(*) AS c, AVG(Rating) AS a FROM tblreviews WHERE Status=1 AND Rating BETWEEN 1 AND 5")->fetch(PDO::FETCH_ASSOC);
    $hs['reviews'] = (int)($r['c'] ?? 0);
    $hs['avg'] = $hs['reviews'] > 0 ? round((float)$r['a'], 1) : null;
    $hs['featured'] = $dbh->query("SELECT PackageId, PackageName, PackageType, PackageLocation, PackagePrice, PackageImage, PackageDuration, AvgRating, PackageMeta
        FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . "
        ORDER BY (PackageImage IS NOT NULL AND PackageImage <> '') DESC, IsFeatured DESC, PackageId DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    // Destinations = real package locations, busiest first, each with one real package photo.
    $hs['destinations'] = $dbh->query("SELECT PackageLocation AS place, COUNT(*) AS n, MAX(NULLIF(PackageImage,'')) AS img
        FROM tbltourpackages WHERE " . TMS_PKG_PUBLIC_SQL . " AND PackageLocation IS NOT NULL AND TRIM(PackageLocation) <> ''
        GROUP BY PackageLocation ORDER BY n DESC, place ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    $hs['quotes'] = $dbh->query("SELECT r.Rating, r.ReviewText, r.UserName, p.PackageId, p.PackageName
        FROM tblreviews r JOIN tbltourpackages p ON p.PackageId = r.PackageId
        WHERE r.Status=1 AND r.Rating >= 4 AND CHAR_LENGTH(r.ReviewText) >= 40 ORDER BY r.PostingDate DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    try { $hs['hotels'] = (int)$dbh->query("SELECT COUNT(*) FROM tblhotels")->fetchColumn(); } catch (Throwable $e) { $hs['hotels'] = 0; }
} catch (Throwable $e) {
    error_log('[TourBuddy home] data load failed: ' . $e->getMessage());
}

// Hero photos: any local file that actually exists. If none, the .tms-scene SVG fallback shows.
$hs_root = dirname(__DIR__);
$hs_slides = [];
foreach (['images/hero/hero.jpg', 'images/hero/hero-2.jpg', 'images/hero/hero-3.jpg', 'images/h2.jpg', 'images/h5.jpg', 'images/6.jpg', 'images/c2.jpg', 'images/c3.jpg', 'images/4.jpg'] as $p) {
    if (is_file($hs_root . '/' . $p)) { $hs_slides[] = $p; }
}
// Signed-in visitors with real history see personalised picks (deterministic SQL, see PersonalizedHomepage) instead.
$hs_pkg_title = 'Featured packages';
$hs_pkg_sub = '';
if ($tms_home_signed_in && !empty($recommended['packages'])) {
    $hs['featured'] = array_map(static function ($o) { return (array)$o; }, array_slice($recommended['packages'], 0, 6));
    $hs_pkg_title = 'Recommended for you';
    $hs_pkg_sub = (string)($recommendedReasonLabel ?? '');
}
$hs_ph = 'images/no-image-placeholder.jpg';
$hs_way_img = [];
foreach (['images/c1.jpg', 'images/c2.jpg', 'images/c3.jpg'] as $i => $p) { $hs_way_img[$i] = is_file($hs_root . '/' . $p) ? $p : $hs_ph; }
// url() inside a CSS variable resolves against the stylesheet (css/), so give it a site-absolute path.
$hs_cta_bg = is_file($hs_root . '/images/c4.jpg') ? (rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/images/c4.jpg') : '';
?>
<!-- ═══ HERO ═══ -->
<section class="tms-hero tms-hero-home" aria-labelledby="hsTitle">
    <?php if ($hs_slides): ?><div class="tms-hero-slides" aria-hidden="true" data-images="<?php echo $hs_e(implode(',', $hs_slides)); ?>"></div><?php endif; ?>
    <div class="tms-hero-content hs-hero-inner">
        <h1 id="hsTitle" class="hs-title">Discover more.<br><span class="hs-title-em">Travel your way.</span></h1>
        <p class="hs-sub">Find destinations, nearby attractions, routes and weather, then plan your own trip. No package needed.</p>

        <?php /* Destination discovery is a protected service: a visitor who searches is sent to sign in and returns to the results (login.php?redirect=). */ ?>
        <form class="hs-search" action="explore.php" method="get" role="search" aria-label="Search destinations">
            <div class="hs-field hs-field-grow">
                <label for="heroDestInput"><i class="fa fa-map-marker" aria-hidden="true"></i> Where to?</label>
                <div class="hs-input-wrap">
                    <input type="search" id="heroDestInput" name="q" maxlength="100" placeholder="Search destinations, landmarks, cities, or attractions..." autocomplete="off" required>
                    <button type="button" id="heroMicBtn" onclick="toggleHeroVoice()" title="Search by voice" aria-label="Search by voice" hidden><i class="fa fa-microphone" aria-hidden="true"></i></button>
                </div>
            </div>
            <button type="submit" class="hs-go"><i class="fa fa-compass" aria-hidden="true"></i> Explore destinations</button>
        </form>
        <p class="hs-quiet"><a href="plan.php">Plan your trip</a><span aria-hidden="true">·</span><a href="tour-map.php">Open the map</a><span aria-hidden="true">·</span><a href="package-list.php">Browse tour packages</a></p>
    </div>
    <?php if (!$hs_slides): ?><div class="tms-scene" aria-hidden="true"></div><?php endif; ?>
    <svg class="hs-plane tms-float" viewBox="0 0 64 64" width="44" height="44" aria-hidden="true"><path fill="currentColor" d="M60 8 4 30l16 6 6 18 8-12 14 10z" opacity=".9"/></svg>
</section>

<!-- ═══ 1. THREE EASY WAYS ═══ -->
<section class="hs-section" aria-labelledby="hsWays">
    <div class="hs-wrap">
        <h2 id="hsWays" class="hs-h2 tms-reveal">Three easy ways to travel</h2>
        <div class="hs-ways">
            <?php
            $ways = [
                ['package-list.php', 'Book a package', 'Fixed itinerary, hotel and guide in one price.', 'fa-suitcase'],
                ['tour-map.php', 'Explore the map', 'See places, hotels and routes around you.', 'fa-map'],
                ['ai-recommend.php', 'Plan with AI', 'Tell us your budget and days, get a draft plan.', 'fa-magic'],
            ];
            foreach ($ways as $i => $w): ?>
            <a class="hs-way tms-reveal" data-delay="<?php echo $i * 90; ?>" href="<?php echo $hs_e($w[0]); ?>">
                <img src="<?php echo $hs_e($hs_way_img[$i]); ?>" alt="" width="480" height="320" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $hs_ph; ?>'">
                <span class="hs-way-body">
                    <i class="fa <?php echo $w[3]; ?>" aria-hidden="true"></i>
                    <strong><?php echo $hs_e($w[1]); ?></strong>
                    <span><?php echo $hs_e($w[2]); ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if ($hs['destinations']): ?>
<!-- ═══ 2. POPULAR DESTINATIONS ═══ -->
<section class="hs-section hs-alt" aria-labelledby="hsDest">
    <div class="hs-wrap">
        <div class="hs-head tms-reveal"><h2 id="hsDest" class="hs-h2">Popular destinations</h2><a class="hs-more" href="destinations.php">See all</a></div>
        <div class="hs-dests">
            <?php foreach ($hs['destinations'] as $i => $d): ?>
            <a class="hs-dest tms-reveal" data-delay="<?php echo $i * 60; ?>" href="package-list.php?search=<?php echo urlencode((string)$d['place']); ?>">
                <img src="<?php echo $hs_e(tms_package_image_src((string)$d['img'])); ?>" alt="" width="400" height="500" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $hs_ph; ?>'">
                <span class="hs-dest-label"><strong><?php echo $hs_e($d['place']); ?></strong><small><?php echo (int)$d['n']; ?> package<?php echo (int)$d['n'] === 1 ? '' : 's'; ?></small></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($hs['featured']): ?>
<!-- ═══ 3. FEATURED PACKAGES ═══ -->
<section class="hs-section" aria-labelledby="hsPkg">
    <div class="hs-wrap">
        <div class="hs-head tms-reveal"><h2 id="hsPkg" class="hs-h2"><?php echo $hs_e($hs_pkg_title); ?></h2><a class="hs-more" href="package-list.php">View all</a></div>
        <?php if ($hs_pkg_sub !== ''): ?><p class="hs-subline"><?php echo $hs_e($hs_pkg_sub); ?></p><?php endif; ?>
        <div class="tms-card-grid">
            <?php foreach ($hs['featured'] as $i => $p): ?>
            <article class="tms-card tms-reveal" data-delay="<?php echo ($i % 3) * 80; ?>">
                <a class="tms-card-img" href="package-details.php?pkgid=<?php echo (int)$p['PackageId']; ?>" tabindex="-1" aria-hidden="true">
                    <img src="<?php echo $hs_e(tms_package_image_src((string)$p['PackageImage'])); ?>" alt="" width="640" height="420" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $hs_ph; ?>'">
                    <?php $hs_meta = !empty($p['PackageMeta']) ? json_decode((string)$p['PackageMeta'], true) : null;
                    if (function_exists('tms_meta_is_sample') && tms_meta_is_sample($hs_meta)): ?><span class="tms-card-badge tms-card-sample">Sample</span>
                    <?php elseif (!empty($p['PackageType'])): ?><span class="tms-card-badge"><?php echo $hs_e($p['PackageType']); ?></span><?php endif; ?>
                </a>
                <?php if ($tms_home_signed_in): ?>
                <button type="button" class="tms-card-save" onclick="addToWishlist(<?php echo (int)$p['PackageId']; ?>, this)" aria-label="Save <?php echo $hs_e($p['PackageName']); ?>"><i class="fa fa-heart-o" aria-hidden="true"></i></button>
                <?php endif; ?>
                <div class="tms-card-body">
                    <h3 class="tms-card-title"><a href="package-details.php?pkgid=<?php echo (int)$p['PackageId']; ?>"><?php echo $hs_e($p['PackageName']); ?></a></h3>
                    <p class="tms-card-meta"><i class="fa fa-map-marker" aria-hidden="true"></i> <?php echo $hs_e($p['PackageLocation'] ?: 'Various locations'); ?>
                        <?php if (!empty($p['AvgRating']) && $p['AvgRating'] > 0): ?><span class="tms-card-rate"><i class="fa fa-star" aria-hidden="true"></i> <?php echo number_format((float)$p['AvgRating'], 1); ?></span><?php endif; ?></p>
                    <div class="tms-card-foot">
                        <span class="tms-card-price"><?php echo tms_currency((float)$p['PackagePrice']); ?></span>
                        <a class="tms-btn-view" href="package-details.php?pkgid=<?php echo (int)$p['PackageId']; ?>">View</a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══ 4. WHY TourBuddy ═══ -->
<section class="hs-section hs-alt" aria-labelledby="hsWhy">
    <div class="hs-wrap">
        <h2 id="hsWhy" class="hs-h2 tms-reveal">Why TourBuddy</h2>
        <div class="hs-why">
            <div class="hs-why-item tms-reveal"><i class="fa fa-check-circle" aria-hidden="true"></i><h3>Real listings</h3>
                <p><?php if ($hs['pkgs'] > 0): ?><span class="tms-count" data-to="<?php echo $hs['pkgs']; ?>"><?php echo $hs['pkgs']; ?></span> package<?php echo $hs['pkgs'] === 1 ? '' : 's'; ?><?php if ($hs['dests'] > 0): ?> across <span class="tms-count" data-to="<?php echo $hs['dests']; ?>"><?php echo $hs['dests']; ?></span> place<?php echo $hs['dests'] === 1 ? '' : 's'; ?><?php endif; ?>. Sample items are always labelled.<?php else: ?>Packages from agencies, with clear prices. Sample items are always labelled.<?php endif; ?></p></div>
            <div class="hs-why-item tms-reveal" data-delay="90"><i class="fa fa-lock" aria-hidden="true"></i><h3>Clear booking</h3>
                <p>See the full price, cancellation terms and your booking status before and after you pay.</p></div>
            <div class="hs-why-item tms-reveal" data-delay="180"><i class="fa fa-star" aria-hidden="true"></i><h3>Honest reviews</h3>
                <p><?php if ($hs['reviews'] > 0 && $hs['avg'] !== null): ?>Average <?php echo $hs_e(number_format($hs['avg'], 1)); ?>/5 from <span class="tms-count" data-to="<?php echo $hs['reviews']; ?>"><?php echo $hs['reviews']; ?></span> approved review<?php echo $hs['reviews'] === 1 ? '' : 's'; ?>.<?php else: ?>Only approved traveller reviews are shown. None have been posted yet.<?php endif; ?></p></div>
        </div>
        <?php if ($hs['quotes']): ?>
        <div class="hs-quotes">
            <?php foreach ($hs['quotes'] as $q): ?>
            <figure class="hs-quote tms-reveal">
                <blockquote>“<?php echo $hs_e(mb_strimwidth((string)$q['ReviewText'], 0, 180, '…')); ?>”</blockquote>
                <figcaption><?php echo $hs_e($q['UserName'] ?: 'Traveller'); ?> · <a href="package-details.php?pkgid=<?php echo (int)$q['PackageId']; ?>"><?php echo $hs_e($q['PackageName']); ?></a></figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ═══ 5. FINAL CTA ═══ -->
<section class="hs-cta tms-wave-top tms-wave-bottom"<?php echo $hs_cta_bg ? ' style="--hs-cta-img:url(\'' . $hs_e($hs_cta_bg) . '\')"' : ''; ?> aria-labelledby="hsCta">
    <div class="hs-wrap hs-cta-inner">
        <h2 id="hsCta">Ready when you are.</h2>
        <p><?php echo $tms_home_signed_in ? 'Pick up where you left off, or start something new.' : 'Create a free account to save trips and book in minutes.'; ?></p>
        <?php if ($tms_home_signed_in): ?>
            <a class="hs-go hs-go-lg" href="package-list.php">Browse packages</a>
        <?php else: ?>
            <a class="hs-go hs-go-lg" href="javascript:void(0)" onclick="openModal('signupModal')">Get started free</a>
            <a class="hs-ghost" href="javascript:void(0)" onclick="openModal('signinModal')">Sign in</a>
        <?php endif; ?>
    </div>
</section>

<script>
var TMS_CSRF_TOKEN = <?php echo json_encode(function_exists('tms_csrf_token') ? tms_csrf_token() : ''); ?>;
// Voice search (browser Web Speech API; hidden when unsupported). "under 20,000 taka" becomes a max_price filter.
var heroRecognition = null, heroListening = false;
var HeroSpeechAPI = window.SpeechRecognition || window.webkitSpeechRecognition;
(function () {
    var mic = document.getElementById('heroMicBtn');
    if (!HeroSpeechAPI || !mic) return;
    mic.hidden = false;
    heroRecognition = new HeroSpeechAPI();
    heroRecognition.continuous = false; heroRecognition.interimResults = false; heroRecognition.lang = 'en-US';
    heroRecognition.onstart = function () { heroListening = true; mic.classList.add('listening'); };
    heroRecognition.onend = heroRecognition.onerror = function () { heroListening = false; mic.classList.remove('listening'); };
    heroRecognition.onresult = function (ev) {
        var parsed = parseVoiceQuery(ev.results[0][0].transcript);
        var url = 'package-list.php?search=' + encodeURIComponent(parsed.destination);
        if (parsed.maxPrice) url += '&max_price=' + parsed.maxPrice;
        window.location.href = url;
    };
})();
function toggleHeroVoice() { if (!heroRecognition) return; if (heroListening) { heroRecognition.stop(); } else { heroRecognition.start(); } }
function parseVoiceQuery(text) {
    var m = text.match(/(?:under|below|less than|within)\s*([\d,]+)\s*(?:taka|tk|bdt)?/i), maxPrice = null, dest = text;
    if (m) { maxPrice = parseInt(m[1].replace(/,/g, ''), 10); dest = (text.slice(0, m.index) + text.slice(m.index + m[0].length)).trim(); }
    dest = dest.replace(/\b(find me|show me|search for|packages?|please|i want)\b/gi, '').replace(/\s+/g, ' ').trim();
    return { destination: dest, maxPrice: maxPrice };
}
function searchPackages() {
    var d = document.getElementById('heroDestInput').value;
    window.location.href = 'package-list.php' + (d ? '?search=' + encodeURIComponent(d) : '');
}
<?php if ($tms_home_signed_in): ?>
function addToWishlist(pkgId, el) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'wishlist.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        if (xhr.status === 200) { el.innerHTML = '<i class="fa fa-heart" aria-hidden="true"></i>'; el.classList.add('is-saved'); el.title = 'Saved'; }
        else { el.title = 'Could not update wishlist, please try again'; }
    };
    xhr.onerror = function () { el.title = 'Could not update wishlist, please try again'; };
    xhr.send('toggle_wish=1&pkgid=' + encodeURIComponent(pkgId) + '&csrf_token=' + encodeURIComponent(TMS_CSRF_TOKEN));
}
<?php endif; ?>
</script>
