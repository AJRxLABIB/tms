<?php
/**
 * ai-packages.php — AI Dynamic Package Generator
 * Users describe what they want; AI creates a unique personalized package.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login

require_once 'backend/ai/PackageGenerator.php';

// Load user's previously generated packages
$myPackages = [];
if (!empty($_SESSION['login'])) {
    $gen = new PackageGenerator($dbh);
    $myPackages = $gen->getUserPackages($_SESSION['login']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#104B3A">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Package Generator | TMS</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
.pkg-hero{background:linear-gradient(135deg,#0E2A21 0%,#104B3A 55%,#176B50 100%);padding:80px 0 60px;text-align:center;color:#fff;position:relative;overflow:hidden}
.pkg-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(126,214,178,.14) 0,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(167,139,250,.1) 0,transparent 50%)}
.pkg-hero-content{position:relative;z-index:1}
.pkg-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(126,214,178,.14);border:1px solid rgba(126,214,178,.3);border-radius:50px;padding:7px 18px;font-size:12px;color:#B8E4C9;margin-bottom:20px}
.pkg-badge .dot{width:6px;height:6px;background:#48bb78;border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.pkg-hero h1{font-size:2.6rem;font-weight:900;line-height:1.2;margin-bottom:14px}
.pkg-hero h1 span{background:linear-gradient(135deg,#7ED6B2,#B8E4C9);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.pkg-hero p{color:rgba(255,255,255,.65);font-size:1.05rem;max-width:540px;margin:0 auto}

/* Form */
.gen-form-card{background:#fff;border-radius:22px;padding:36px;box-shadow:0 20px 60px rgba(0,0,0,.12);margin-top:-30px;position:relative;z-index:10}
.section-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#4a5568;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.section-label i{color:#176B50}
.gen-input,.gen-select{width:100%;padding:11px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;outline:none;background:#f8fafc;transition:.2s}
.gen-input:focus,.gen-select:focus{border-color:#176B50;background:#fff;box-shadow:0 0 0 3px rgba(23,107,80,.1)}
.gen-textarea{width:100%;padding:11px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;outline:none;background:#f8fafc;resize:vertical;min-height:80px;transition:.2s}
.gen-textarea:focus{border-color:#176B50;background:#fff}
.gen-label{font-size:12px;font-weight:600;color:#4a5568;margin-bottom:5px;display:block}
.btn-gen-pkg{width:100%;background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;border:none;border-radius:12px;padding:15px;font-size:15px;font-weight:800;cursor:pointer;transition:.3s;display:flex;align-items:center;justify-content:center;gap:10px}
.btn-gen-pkg:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(23,107,80,.4)}
.btn-gen-pkg:disabled{opacity:.6;transform:none;box-shadow:none}

/* Result */
.pkg-result{display:none;margin-top:40px}

/* Package card */
.ai-pkg-card{background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.1);border:1px solid rgba(0,0,0,.06)}
.ai-pkg-header{background:linear-gradient(135deg,#176B50,#104B3A);padding:32px;color:#fff;position:relative}
.ai-pkg-badge{position:absolute;top:20px;right:20px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;backdrop-filter:blur(6px)}
.ai-pkg-name{font-size:1.8rem;font-weight:900;margin-bottom:6px}
.ai-pkg-tagline{opacity:.85;font-size:14px;margin-bottom:16px}
.ai-pkg-meta{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;opacity:.85}
.ai-pkg-meta-item{display:flex;align-items:center;gap:6px}

/* Pricing */
.pricing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1px;background:#f0f0f0}
.pricing-box{background:#fff;padding:18px;text-align:center}
.pricing-box.total-box{background:linear-gradient(135deg,#176B50,#104B3A);color:#fff}
.pricing-amount{font-size:1.3rem;font-weight:900}
.pricing-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
.total-box .pricing-label{color:rgba(255,255,255,.75)}

/* Sections */
.pkg-section{padding:24px 28px;border-bottom:1px solid #f0f0f0}
.pkg-section:last-child{border-bottom:none}
.pkg-section-title{font-size:14px;font-weight:800;color:#1a202c;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.pkg-section-title i{color:#176B50}

/* Itinerary preview */
.itin-day{display:flex;gap:16px;padding:10px 0;border-bottom:1px dashed #f0f0f0}
.itin-day:last-child{border-bottom:none}
.itin-day-num{width:40px;height:40px;background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0}
.itin-day-title{font-size:13px;font-weight:700;color:#1a202c;margin-bottom:4px}
.itin-day-highlights{font-size:12px;color:#4a5568}

/* Accommodation */
.acc-card{background:#f8fafc;border-radius:14px;padding:18px;border:1px solid #e2e8f0}
.acc-name{font-size:15px;font-weight:700;color:#1a202c}
.acc-type{display:inline-block;background:#E7F5EE;color:#0E3B2D;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;margin-bottom:8px}
.acc-features{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.acc-feature{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:3px 10px;font-size:11px;color:#4a5568}

/* Included/excluded */
.incl-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.incl-item{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#276749;padding:4px 0}
.excl-item{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#c53030;padding:4px 0}
.incl-item i{color:#48bb78;flex-shrink:0;margin-top:2px}
.excl-item i{color:#fc8181;flex-shrink:0;margin-top:2px}

/* Eco score */
.eco-bar-wrap{background:#f0f0f0;border-radius:20px;height:10px;overflow:hidden;margin:8px 0}
.eco-bar-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#68d391,#48bb78);transition:width 1s ease}

/* Action buttons */
.pkg-actions{display:flex;gap:12px;padding:24px 28px;background:#f8fafc;flex-wrap:wrap}
.btn-pkg-action{padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;transition:.2s;text-decoration:none;border:none}
.btn-pkg-primary{background:linear-gradient(135deg,#176B50,#104B3A);color:#fff}
.btn-pkg-primary:hover{opacity:.9;color:#fff}
.btn-pkg-outline{background:#fff;color:#176B50;border:2px solid #176B50}
.btn-pkg-outline:hover{background:#176B50;color:#fff}

/* AI reasoning */
.ai-reasoning{background:linear-gradient(135deg,#f8f9ff,#ede9fe);border-radius:14px;padding:18px;border-left:4px solid #176B50;margin-top:16px;font-size:13px;color:#2d3748;line-height:1.7}

/* Thinking */
.ai-thinking-pkg{text-align:center;padding:70px 20px;display:none}
.thinking-orbit{width:80px;height:80px;border:4px solid #e2e8f0;border-top-color:#176B50;border-radius:50%;animation:spin 1.2s linear infinite;margin:0 auto 24px}
@keyframes spin{to{transform:rotate(360deg)}}
.thinking-msgs{color:var(--text-muted);font-size:14px}

/* My packages tab */
.my-pkgs-section{margin-top:50px}
.my-pkg-card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1px solid #f0f0f0;margin-bottom:16px;display:flex;gap:16px;align-items:center;transition:.2s}
.my-pkg-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.1);transform:translateY(-2px)}
.my-pkg-icon{width:50px;height:50px;background:linear-gradient(135deg,#176B50,#104B3A);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.my-pkg-name{font-size:14px;font-weight:700;color:#1a202c;margin-bottom:4px}
.my-pkg-meta{font-size:12px;color:var(--text-muted)}
.my-pkg-price{font-size:16px;font-weight:800;color:#176B50;margin-left:auto;text-align:right;flex-shrink:0}

@media(max-width:768px){.incl-grid{grid-template-columns:1fr}.ai-pkg-meta{gap:10px}.pkg-actions{flex-direction:column}}
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<!-- Hero -->
<div class="pkg-hero">
  <div class="container">
    <div class="pkg-hero-content">
      <div class="pkg-badge"><span class="dot"></span> AI Package Generator</div>
      <h1>Your Trip, <span>Designed by AI</span></h1>
      <p>No two travelers are the same. Describe your dream trip and AI will build a completely unique package just for you — in seconds.</p>
    </div>
  </div>
</div>

<div class="container" style="max-width:920px;padding-bottom:60px;">

  <!-- Generator Form -->
  <div class="gen-form-card">
    <div class="row g-4">

      <div class="col-12">
        <p class="section-label"><i class="fa fa-money"></i> Budget & Group</p>
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_budget">Total Budget (BDT)</label>
            <input type="number" id="g_budget" class="gen-input" placeholder="e.g. 50000" min="1000">
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_travelers">Travelers</label>
            <input type="number" id="g_travelers" class="gen-input" value="2" min="1" max="50">
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_start">Start Date</label>
            <input type="date" id="g_start" class="gen-input">
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_days">Duration (days)</label>
            <input type="number" id="g_days" class="gen-input" value="5" min="1" max="30">
          </div>
        </div>
      </div>

      <div class="col-12">
        <p class="section-label"><i class="fa fa-heart"></i> Travel Preferences</p>
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_style">Travel Style</label>
            <select id="g_style" class="gen-select">
              <option value="leisure">Leisure / Relax</option>
              <option value="adventure">Adventure</option>
              <option value="family">Family</option>
              <option value="couple">Couple / Honeymoon</option>
              <option value="solo">Solo</option>
              <option value="group">Group / Friends</option>
              <option value="business">Business</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_climate">Climate</label>
            <select id="g_climate" class="gen-select">
              <option value="warm">Warm / Tropical</option>
              <option value="beach">Beach</option>
              <option value="mountain">Mountain / Cool</option>
              <option value="cold">Cold / Winter</option>
              <option value="any">No Preference</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_accom">Accommodation</label>
            <select id="g_accom" class="gen-select">
              <option value="hotel">Hotel (3-star)</option>
              <option value="resort">Resort</option>
              <option value="budget">Budget / Hostel</option>
              <option value="luxury">Luxury (4-5 star)</option>
              <option value="eco">Eco / Camping</option>
            </select>
          </div>
          <div class="col-6 col-md-3">
            <label class="gen-label" for="g_activities">Preferred Activities</label>
            <input type="text" id="g_activities" class="gen-input" placeholder="beach, hiking, food…">
          </div>
        </div>
      </div>

      <div class="col-12">
        <label class="gen-label" for="g_special">Describe your dream trip (optional)</label>
        <textarea id="g_special" class="gen-textarea" placeholder="E.g. 'We want a relaxing beach holiday for our anniversary with sunset views and fresh seafood…'"></textarea>
      </div>

      <div class="col-12">
        <button class="btn-gen-pkg" id="genPkgBtn" onclick="generatePackage()">
          <i class="fa fa-magic"></i>
          <span id="genPkgBtnText">Generate My AI Package</span>
        </button>
      </div>

    </div>
  </div>

  <!-- AI Thinking -->
  <div class="ai-thinking-pkg" id="pkgThinking">
    <div class="thinking-orbit"></div>
    <h4 style="font-weight:800;color:#1a202c;margin-bottom:8px;">Designing your package…</h4>
    <div class="thinking-msgs" id="thinkingMsg">Finding the perfect destination for your style</div>
  </div>

  <!-- Package Result -->
  <div class="pkg-result" id="pkgResult"></div>

  <!-- My Generated Packages -->
  <?php if (!empty($myPackages)): ?>
  <div class="my-pkgs-section">
    <h5 style="font-weight:800;color:#1a202c;margin-bottom:20px;"><i class="fa fa-folder-open" style="color:#176B50;"></i> My AI-Generated Packages</h5>
    <?php foreach ($myPackages as $pkg): ?>
    <div class="my-pkg-card">
      <div class="my-pkg-icon">✈️</div>
      <div style="flex:1">
        <div class="my-pkg-name"><?= htmlspecialchars($pkg['package_name'] ?? 'Custom Package') ?></div>
        <div class="my-pkg-meta">
          <i class="fa fa-map-marker"></i> <?= htmlspecialchars($pkg['destination'] ?? '—') ?>
          &nbsp;|&nbsp; <i class="fa fa-calendar"></i> <?= ($pkg['duration'] ?? '—') ?> days
          &nbsp;|&nbsp; <i class="fa fa-users"></i> <?= ($pkg['travelers'] ?? '—') ?> travelers
          &nbsp;|&nbsp; <?= $pkg['created_at'] ?? '' ?>
        </div>
      </div>
      <div class="my-pkg-price">৳<?= number_format($pkg['pricing']['total'] ?? 0) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php include('backend/footer.php'); ?>

<script>
document.getElementById('g_start').valueAsDate = new Date(Date.now() + 7*24*60*60*1000);

const thinkMsgs = [
  'Finding the perfect destination for your style…',
  'Calculating best-value accommodation options…',
  'Planning transport routes and local experiences…',
  'Designing a unique day-by-day schedule…',
  'Calculating total cost breakdown…',
  'Adding hidden gems and local tips…',
  'Finalising your personalised package…'
];

async function generatePackage() {
  const budget = parseInt(document.getElementById('g_budget').value);
  if (!budget || budget < 500) { tmsToast('Please enter a valid budget (minimum ৳500).'); return; }

  document.getElementById('pkgResult').style.display   = 'none';
  document.getElementById('pkgThinking').style.display = 'block';
  document.getElementById('genPkgBtn').disabled = true;
  document.getElementById('genPkgBtnText').textContent = 'AI is thinking…';

  let mi = 0;
  const interval = setInterval(() => {
    document.getElementById('thinkingMsg').textContent = thinkMsgs[mi++ % thinkMsgs.length];
  }, 1800);

  const fd = new FormData();
  fd.append('action',           'generate_package');
  fd.append('budget',           budget);
  fd.append('travelers',        document.getElementById('g_travelers').value);
  fd.append('start_date',       document.getElementById('g_start').value);
  fd.append('duration',         document.getElementById('g_days').value);
  fd.append('travel_type',      document.getElementById('g_style').value);
  fd.append('climate',          document.getElementById('g_climate').value);
  fd.append('accommodation',    document.getElementById('g_accom').value);
  fd.append('activities',       document.getElementById('g_activities').value);
  fd.append('special_requests', document.getElementById('g_special').value);

  try {
    const res  = await fetch('api/ai.php', { method:'POST', body:fd });
    const json = await res.json();
    clearInterval(interval);
    document.getElementById('pkgThinking').style.display = 'none';
    document.getElementById('genPkgBtn').disabled = false;
    document.getElementById('genPkgBtnText').textContent = 'Generate My AI Package';

    if (json.success && json.data.package) {
      renderPackage(json.data.package);
    } else {
      tmsToast(json.error || 'Package generation failed. Please try again.');
    }
  } catch(e) {
    clearInterval(interval);
    document.getElementById('pkgThinking').style.display = 'none';
    document.getElementById('genPkgBtn').disabled = false;
    document.getElementById('genPkgBtnText').textContent = 'Generate My AI Package';
    tmsToast('Network error. Please try again.');
  }
}

function renderPackage(pkg) {
  const pricing      = pkg.pricing || {};
  const acc          = pkg.accommodation || {};
  const transport    = pkg.transport || {};
  const itinDays     = pkg.itinerary_preview || [];
  const included     = pkg.included || [];
  const excluded     = pkg.excluded || [];
  const highlights   = pkg.highlights || [];
  const ecoScore     = pkg.eco_score || 70;

  const diffColors   = { easy:'#48bb78', moderate:'#ed8936', challenging:'#e53e3e' };
  const diffColor    = diffColors[pkg.difficulty_level] || '#718096';

  let html = `
  <div class="ai-pkg-card">

    <!-- Header -->
    <div class="ai-pkg-header">
      <div class="ai-pkg-badge">✨ AI Generated</div>
      <div class="ai-pkg-name">${esc(pkg.package_name || 'Your Custom Package')}</div>
      <div class="ai-pkg-tagline">${esc(pkg.tagline || '')}</div>
      <div class="ai-pkg-meta">
        <span class="ai-pkg-meta-item"><i class="fa fa-map-marker"></i> ${esc(pkg.destination || '')}</span>
        <span class="ai-pkg-meta-item"><i class="fa fa-calendar"></i> ${pkg.duration} days</span>
        <span class="ai-pkg-meta-item"><i class="fa fa-users"></i> ${pkg.travelers} travelers</span>
        <span class="ai-pkg-meta-item"><i class="fa fa-suitcase"></i> ${esc(pkg.travel_style || '')}</span>
        <span class="ai-pkg-meta-item" style="background:rgba(255,255,255,.12);border-radius:20px;padding:3px 10px;">
          ${esc(pkg.age_suitability || 'All ages')}
        </span>
      </div>
    </div>

    <!-- Pricing -->
    <div class="pricing-grid">
      <div class="pricing-box">
        <div class="pricing-amount">৳${fmt(pricing.accommodation_cost)}</div>
        <div class="pricing-label">Accommodation</div>
      </div>
      <div class="pricing-box">
        <div class="pricing-amount">৳${fmt(pricing.transport_cost)}</div>
        <div class="pricing-label">Transport</div>
      </div>
      <div class="pricing-box">
        <div class="pricing-amount">৳${fmt(pricing.food_cost)}</div>
        <div class="pricing-label">Food</div>
      </div>
      <div class="pricing-box">
        <div class="pricing-amount">৳${fmt(pricing.activities_cost)}</div>
        <div class="pricing-label">Activities</div>
      </div>
      <div class="pricing-box total-box">
        <div class="pricing-amount">৳${fmt(pricing.total)}</div>
        <div class="pricing-label">Total (${pkg.travelers} travelers)</div>
      </div>
    </div>

    <!-- Highlights -->
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-star"></i> Trip Highlights</div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        ${highlights.map(h => `<span style="background:#E7F5EE;color:#0E3B2D;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:600;">✓ ${esc(h)}</span>`).join('')}
      </div>
      <div class="ai-reasoning">💡 ${esc(pkg.ai_reasoning || '')}</div>
    </div>

    <!-- Itinerary Preview -->
    ${itinDays.length ? `
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-calendar"></i> Itinerary Preview</div>
      ${itinDays.map(d => `
      <div class="itin-day">
        <div class="itin-day-num">D${d.day}</div>
        <div>
          <div class="itin-day-title">${esc(d.title || '')}</div>
          <div class="itin-day-highlights">${(d.highlights||[]).map(h => esc(h)).join(' &nbsp;·&nbsp; ')}</div>
        </div>
      </div>`).join('')}
      <div style="margin-top:12px;">
        <a href="ai-planner.php" style="font-size:13px;color:#176B50;text-decoration:none;font-weight:600;">
          <i class="fa fa-calendar-plus-o"></i> Generate full day-by-day itinerary →
        </a>
      </div>
    </div>` : ''}

    <!-- Accommodation -->
    ${acc.name ? `
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-bed"></i> Accommodation</div>
      <div class="acc-card">
        <span class="acc-type">${esc(acc.type || 'Hotel')}</span>
        <div class="acc-name">${esc(acc.name)}</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:4px;">
          <i class="fa fa-map-marker"></i> ${esc(acc.location || '')}
          ${acc.estimated_rate ? `&nbsp;|&nbsp; <i class="fa fa-money"></i> ~${esc(acc.estimated_rate)}/night` : ''}
        </div>
        ${(acc.features||[]).length ? `<div class="acc-features">${acc.features.map(f=>`<span class="acc-feature">✓ ${esc(f)}</span>`).join('')}</div>` : ''}
      </div>
    </div>` : ''}

    <!-- Transport -->
    ${transport.to_destination ? `
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-bus"></i> Transport</div>
      <div style="font-size:13px;color:#2d3748;line-height:1.8;">
        <div style="margin-bottom:6px;"><strong>To Destination:</strong> ${esc(transport.to_destination)}</div>
        <div style="margin-bottom:6px;"><strong>Getting Around:</strong> ${esc(transport.local_transport||'')}</div>
        <div><strong>Return Journey:</strong> ${esc(transport.return_journey||'')}</div>
      </div>
    </div>` : ''}

    <!-- Included / Excluded -->
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-list-alt"></i> What's Included</div>
      <div class="incl-grid">
        <div>
          ${included.map(i => `<div class="incl-item"><i class="fa fa-check-circle"></i> ${esc(i)}</div>`).join('')}
        </div>
        <div>
          ${excluded.map(e => `<div class="excl-item"><i class="fa fa-times-circle"></i> ${esc(e)}</div>`).join('')}
        </div>
      </div>
    </div>

    <!-- Eco Score -->
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-leaf"></i> Eco & Sustainability Score</div>
      <div style="display:flex;align-items:center;gap:16px;">
        <div style="font-size:2rem;font-weight:900;color:${ecoScore>=70?'#48bb78':ecoScore>=50?'#ed8936':'#e53e3e'}">${ecoScore}</div>
        <div style="flex:1">
          <div class="eco-bar-wrap"><div class="eco-bar-fill" style="width:${ecoScore}%"></div></div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">${esc(pkg.carbon_note || '')}</div>
        </div>
      </div>
    </div>

    <!-- Additional info -->
    <div class="pkg-section">
      <div class="pkg-section-title"><i class="fa fa-info-circle"></i> Package Details</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:13px;">
        <div><strong>Best for:</strong> ${esc(pkg.best_for||'')}</div>
        <div><strong>Difficulty:</strong> <span style="color:${diffColor};font-weight:700;">${esc(pkg.difficulty_level||'easy')}</span></div>
        <div><strong>Cancellation:</strong> ${esc(pkg.cancellation_policy||'moderate')}</div>
        <div><strong>Book by:</strong> ${esc(pkg.booking_deadline||'—')}</div>
      </div>
    </div>

    <!-- Actions -->
    <div class="pkg-actions">
      <a href="custom-trip.php" class="btn-pkg-action btn-pkg-primary"><i class="fa fa-pencil"></i> Customise & Book</a>
      <button class="btn-pkg-action btn-pkg-outline" onclick="generatePackage()"><i class="fa fa-refresh"></i> Generate Another</button>
      <a href="ai-planner.php" class="btn-pkg-action btn-pkg-outline"><i class="fa fa-calendar"></i> Full Itinerary</a>
      <button class="btn-pkg-action btn-pkg-outline" onclick="downloadPackage()"><i class="fa fa-download"></i> Save PDF</button>
    </div>

  </div>`;

  const resultEl = document.getElementById('pkgResult');
  resultEl.innerHTML = html;
  resultEl.style.display = 'block';
  resultEl.scrollIntoView({ behavior:'smooth' });
}

function downloadPackage() {
  tmsToast('Package saved! In production, this would generate a PDF using the server-side PDF skill.');
}

function fmt(n) { return n ? Number(n).toLocaleString() : '0'; }
function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
</script>
</body>
</html>
