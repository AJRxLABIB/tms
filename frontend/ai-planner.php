<?php
/**
 * ai-planner.php — AI Autonomous Trip Planner
 * Generates complete day-by-day travel itineraries via Google Gemini
 * (through backend/ai/ItineraryPlanner.php + AIClient.php). Docblock
 * corrected Round 110: previously said "Claude AI", stale documentation
 * left over from an earlier provider (Rule 75).
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
<!DOCTYPE html>
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
<title>AI Trip Planner | TourBuddy</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
.planner-hero {
    background: linear-gradient(135deg, #0E2A21 0%, #104B3A 55%, #176B50 100%);
    padding: 70px 0 50px; text-align: center; color: #fff;
    position: relative; overflow: hidden;
}
.planner-hero::after {
    content: '';
    position: absolute; bottom: -2px; left: 0; right: 0;
    height: 60px;
    background: linear-gradient(to bottom right, transparent 49%, var(--bg) 50%);
}
.planner-hero h1 { font-size: 2.5rem; font-weight: 800; margin-bottom: 12px; }
.planner-hero h1 span { color: #7ED6B2; }
.planner-hero p { color: rgba(255,255,255,.85); font-size: 1.05rem; }

/* Form */
.planner-card {
    background: var(--white); border-radius: 20px; padding: 36px;
    box-shadow: 0 10px 40px rgba(0,0,0,.1);
    margin-top: -20px; margin-bottom: 40px;
}
.field-label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; display: block; }
.field-input, .field-select, .field-textarea {
    width: 100%; padding: 11px 14px; border: 2px solid var(--border);
    border-radius: 10px; font-size: 14px; outline: none; color: var(--text);
    background: var(--bg); transition: .2s;
}
.field-input:focus, .field-select:focus, .field-textarea:focus {
    border-color: #176B50; background: var(--white);
    box-shadow: 0 0 0 3px rgba(23,107,80,.1);
}
.field-textarea { min-height: 80px; resize: vertical; }
.field-input, .field-select { height: 46px; }

.btn-generate {
    background: linear-gradient(135deg, #176B50, #104B3A);
    color: #fff; border: none; border-radius: 12px;
    padding: 14px 32px; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: .3s; width: 100%;
    display: flex; align-items: center; justify-content: center; gap: 10px;
}
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(23,107,80,.4); }
.btn-generate:disabled { opacity: .6; transform: none; }

/* Itinerary output */
.itinerary-wrap { display: none; }
.itin-header {
    background: linear-gradient(135deg, #176B50, #104B3A);
    border-radius: 16px; padding: 28px 32px; color: #fff; margin-bottom: 28px;
}
.itin-title { font-size: 1.8rem; font-weight: 800; margin-bottom: 8px; }
.itin-meta { display: flex; flex-wrap: wrap; gap: 20px; font-size: 13px; opacity: .85; }
.itin-meta-item { display: flex; align-items: center; gap: 6px; }

.cost-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
    gap: 12px; margin-bottom: 28px;
}
.cost-box {
    background: var(--white); border-radius: 12px; padding: 16px;
    border: 1px solid var(--border); text-align: center;
}
.cost-box .amount { font-size: 1.2rem; font-weight: 800; color: var(--text); }
.cost-box .label  { font-size: 11px; color:var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }

.day-card {
    background: var(--white); border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0,0,0,.07);
    margin-bottom: 20px; overflow: hidden;
    border: 1px solid var(--border);
}
.day-header {
    background: var(--bg-dark);
    padding: 18px 24px; display: flex; align-items: center;
    justify-content: space-between; cursor: pointer;
}
.day-number {
    background: linear-gradient(135deg, #176B50, #104B3A);
    color: #fff; border-radius: 8px; padding: 4px 12px;
    font-size: 13px; font-weight: 800; margin-right: 12px;
}
.day-title { font-size: 1rem; font-weight: 700; color: var(--text); }
.day-budget-chip { background: #E7F5EE; color: #0E3B2D; border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600; }
.day-weather-note { font-size: 12px; color:var(--text-muted); margin-left: auto; padding-right: 16px; }

.day-body { padding: 0 24px 20px; }
.schedule-item {
    display: flex; gap: 16px; padding: 14px 0;
    border-bottom: 1px dashed var(--border);
}
.schedule-item:last-child { border-bottom: none; }
.schedule-time { width: 56px; font-size: 12px; font-weight: 700; color: var(--secondary); flex-shrink: 0; padding-top: 2px; }
.schedule-icon {
    width: 36px; height: 36px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.schedule-icon.sightseeing { background: #E7F5EE; color: #0E3B2D; }
.schedule-icon.meal        { background: #fffbeb; color: #92400e; }
.schedule-icon.transport   { background: #f0fff4; color: #276749; }
.schedule-icon.accommodation{ background: #E7F5EE; color: #104B3A; }
.schedule-icon.activity    { background: #fff5f5; color: #c53030; }
.schedule-content { flex: 1; }
.schedule-activity { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.schedule-location { font-size: 12px; color: var(--secondary); margin-bottom: 4px; }
.schedule-meta { display: flex; gap: 12px; font-size: 11px; color:var(--text-muted); }
.schedule-notes { font-size: 12px; color: var(--text-muted); margin-top: 4px; font-style: italic; }

.day-meals {
    background: var(--warning-bg); color: var(--warning-text); border-radius: 10px; padding: 12px 16px;
    margin-top: 12px; font-size: 13px;
}
.day-pro-tip {
    background: #E7F5EE;
    border-radius: 10px; padding: 12px 16px;
    font-size: 13px; color: #0E3B2D; margin-top: 10px;
    display: flex; align-items: flex-start; gap: 8px;
}

/* Extras */
.extras-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 28px; }
.extras-card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 16px rgba(0,0,0,.06); }
.extras-title { font-size: 14px; font-weight: 800; color: var(--text); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.extras-title i { color: var(--secondary); }
.extras-item { font-size: 13px; color: var(--text-muted); padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
.extras-item:last-child { border-bottom: none; }

.btn-download {
    background: var(--white); border: 2px solid var(--secondary); color: var(--secondary);
    border-radius: 10px; padding: 10px 20px; font-size: 13px; font-weight: 700;
    cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 8px;
}
.btn-download:hover { background: #176B50; border-color: #176B50; color: #fff; }
.btn-regen {
    background: #176B50; color: #fff;
    border: none; border-radius: 10px; padding: 10px 20px; font-size: 13px; font-weight: 700;
    cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 8px; margin-left: 10px;
}
.btn-regen:hover { opacity: .9; }

/* Thinking */
.ai-thinking { text-align: center; padding: 60px 20px; display: none; }
.ai-spinner {
    width: 60px; height: 60px; border: 4px solid var(--border);
    border-top-color: #176B50; border-radius: 50%;
    animation: spin 1s linear infinite; margin: 0 auto 20px;
}
@keyframes spin { to{transform:rotate(360deg)} }

@media(max-width:768px) { .extras-grid { grid-template-columns: 1fr; } .itin-meta { gap:12px; } }

/* Bootstrap-3 grid has no col-12/g-3: lay the form out with CSS grid */
.planner-card .row::before, .planner-card .row::after { display: none !important; content: none; }
.planner-card .row { display: grid; grid-template-columns: repeat(12, 1fr); gap: 16px; margin: 0; }
.planner-card .row > [class*="col-"] { float: none; width: auto; padding: 0; min-width: 0; }
.planner-card .col-md-6 { grid-column: span 6; } .planner-card .col-md-4 { grid-column: span 4; }
.planner-card .col-md-3 { grid-column: span 3; } .planner-card .col-md-2 { grid-column: span 2; }
.planner-card .col-12 { grid-column: 1 / -1; }
@media (max-width: 768px) { .planner-card { padding: 22px 18px; } .planner-card .row > [class*="col-"] { grid-column: 1 / -1; } }

/* ---- detailed trip guide ---- */
.tp-h { font-weight:800; color:var(--text); margin:26px 0 12px; }
.tp-h i { color:#2E9E78; margin-right:6px; }
.tp-overview { color:rgba(255,255,255,.92); margin:8px 0 10px; font-size:15px; line-height:1.55; }
.tp-best { background:rgba(255,255,255,.12); border-radius:10px; padding:8px 12px; font-size:13.5px; margin-top:8px; }
.tp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:12px; }
.tp-card { background:var(--white); border:1px solid rgba(127,127,127,.25); border-radius:14px; padding:14px; }
.tp-card-top { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px; }
.tp-card-title { font-weight:800; color:var(--text); margin-bottom:4px; }
.tp-line { font-size:13.5px; color:var(--text-muted); margin-top:3px; }
.tp-pill { background:#176B50; color:#fff; border-radius:999px; padding:2px 10px; font-size:12px; font-weight:700; }
.tp-pill-soft { background:rgba(46,158,120,.18); color:#2E9E78; }
.tp-badge { background:#2E9E78; color:#04261b; border-radius:6px; padding:1px 8px; font-size:11.5px; font-weight:800; }
.tp-money { color:#2E9E78; font-weight:700; }
.tp-cost { display:grid; grid-template-columns:1.6fr 1fr; gap:16px; background:var(--white); border:1px solid rgba(127,127,127,.25); border-radius:14px; padding:16px; }
.tp-cost-row { display:grid; grid-template-columns:150px 1fr 90px; gap:10px; align-items:center; margin:7px 0; font-size:13.5px; }
.tp-cost-label { color:var(--text-muted); }
.tp-cost-bar { background:rgba(127,127,127,.2); border-radius:6px; height:8px; overflow:hidden; display:block; }
.tp-cost-bar span { display:block; height:100%; background:#2E9E78; border-radius:6px; }
.tp-cost-val { text-align:right; font-weight:700; color:var(--text); }
.tp-cost-total { display:flex; flex-direction:column; gap:10px; justify-content:center; border-left:1px dashed rgba(127,127,127,.4); padding-left:16px; }
.tp-cost-total small { display:block; color:var(--text-muted); }
.tp-cost-total strong { font-size:24px; color:var(--text); }
.tp-ok { color:#2E9E78; font-weight:700; } .tp-over { color:#e5534b; font-weight:700; }
.tp-fine { font-size:12px; color:var(--text-muted); margin-top:6px; }
.tp-days-head { display:flex; justify-content:space-between; align-items:center; margin:26px 0 12px; flex-wrap:wrap; gap:8px; }
.tp-link { background:none; border:0; color:#2E9E78; font-weight:700; cursor:pointer; padding:0; }
.tp-date { color:var(--text-muted); font-weight:500; margin-left:6px; }
.tp-day-overview { color:var(--text); margin:6px 0 10px; line-height:1.55; }
.tp-day-facts { display:flex; flex-wrap:wrap; gap:8px 16px; font-size:13px; color:var(--text-muted); margin-bottom:12px; }
.tp-what { color:var(--text); font-size:14px; line-height:1.55; margin:6px 0; }
.tp-hop { font-size:12.5px; color:var(--text-muted); margin-top:5px; }
.tp-maplink { font-size:12px; margin-left:6px; color:#2E9E78; font-weight:700; }
.tp-book { display:inline-block; margin-top:8px; background:#176B50; color:#fff !important; border-radius:8px; padding:6px 12px; font-size:13px; font-weight:700; text-decoration:none; }
.tp-meals { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin:14px 0 8px; }
.tp-meal { background:rgba(46,158,120,.08); border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:2px; font-size:13.5px; color:var(--text); }
.tp-meal b { color:#2E9E78; text-transform:uppercase; font-size:11.5px; letter-spacing:.04em; }
.tp-meal em { color:var(--text-muted); } .tp-meal small { color:#2E9E78; font-weight:700; }
.tp-stay { background:rgba(23,107,80,.14); border-radius:10px; padding:10px 12px; margin:8px 0; font-size:14px; color:var(--text); }
.schedule-meta { display:flex; flex-wrap:wrap; gap:6px 14px; align-items:flex-start; }
.schedule-meta .tp-badge { align-self:flex-start; white-space:nowrap; }
.tp-tip { font-size:13px; color:var(--text-muted); margin-top:6px; line-height:1.45; }
.tp-banner { border-radius:12px; padding:14px 16px; margin-bottom:16px; }
.tp-banner-warn { background:#fff4d6; color:#5c3b00; border:1px solid #f0c36d; }
.tp-btn { margin-top:8px; background:#176B50; color:#fff; border:0; border-radius:8px; padding:6px 14px; font-weight:700; cursor:pointer; }
@media (max-width:700px) { .tp-cost { grid-template-columns:1fr; } .tp-cost-total { border-left:0; padding-left:0; } .tp-cost-row { grid-template-columns:110px 1fr 76px; } }
@media print {
  body * { visibility:hidden !important; }
  #itinWrap, #itinWrap * { visibility:visible !important; }
  #itinWrap { position:absolute; left:0; top:0; width:100%; }
  .day-body { display:block !important; }
  .btn-download, .btn-regen, #regenBox, .tp-days-head span, .tp-btn, .tp-book { display:none !important; }
}
</style>
</head>
<body>

<?php include('backend/header.php'); ?>

<div class="planner-hero">
    <div class="container">
        <h1>AI Trip <span>Planner</span></h1>
        <p>Describe your trip and AI will generate a complete day-by-day itinerary — tailored exactly to you.</p>
    </div>
</div>

<div class="container" style="max-width:860px; margin-top:40px; padding-bottom:60px;">
    <!-- Form -->
    <div class="planner-card">
        <h5 style="font-weight:800;color:var(--text);margin-bottom:24px;"><i class="fa fa-magic" style="color:#176B50;"></i> Plan My Trip</h5>
        <?php
        // FIX (Round 110, live CRITICAL bug found during the AI-upgrade audit):
        // this page has never rendered a CSRF token, and api/ai.php's
        // handleItinerary() -- the only handler action=itinerary reaches --
        // unconditionally calls tms_csrf_validate() (enforced, not just
        // logged, since the Phase-1 CSRF hardening pass). Live-reproduced:
        // every single "Generate My Itinerary" click on this real, public,
        // sitemap-listed page has been getting HTTP 403 "Invalid or expired
        // form submission" back from api/ai.php since that hardening pass --
        // the JS never sent a csrf_token field because this page never had
        // one to send, so the AI Trip Planner has not generated a single
        // real itinerary for any visitor. Rendering the token here and
        // sending it in generatePlan()'s FormData below (both the initial
        // generate and Regenerate, since both hit the same handler) is the
        // fix -- same fix already applied to custom-trip.php's own AI
        // itinerary preview call in Round 20 (see that file's own comment).
        tms_csrf_field();
        ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="field-label" for="p_origin">Starting from</label>
                <input type="text" id="p_origin" class="field-input" data-place-suggest="place" placeholder="e.g. Dhaka, Bangladesh" value="Dhaka, Bangladesh">
            </div>
            <div class="col-md-6">
                <label class="field-label" for="p_dest">Destination *</label>
                <input type="text" id="p_dest" class="field-input" data-place-suggest="place" placeholder="e.g. Cox's Bazar, Bandarban, Thailand…">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_start">Start Date *</label>
                <input type="date" id="p_start" class="field-input">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_days">Duration (days)</label>
                <input type="number" id="p_days" class="field-input" value="5" min="1" max="30">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_travelers">Travelers</label>
                <input type="number" id="p_travelers" class="field-input" value="2" min="1" max="50">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_budget">Total Budget (BDT)</label>
                <input type="number" id="p_budget" class="field-input" placeholder="e.g. 40000">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_engine">Planner engine</label>
                <select id="p_engine" class="field-input">
                    <option value="">Automatic (AI, with built-in backup)</option>
                    <option value="brain">TourBuddy Brain (instant, offline)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_style">Travel Style</label>
                <select id="p_style" class="field-select">
                    <option value="leisure">Leisure</option>
                    <option value="family">Family</option>
                    <option value="couple">Couple</option>
                    <option value="solo">Solo</option>
                    <option value="adventure">Adventure</option>
                    <option value="group">Group</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_climate">Climate</label>
                <select id="p_climate" class="field-select">
                    <option value="warm">Warm / Tropical</option>
                    <option value="cold">Cold / Cool</option>
                    <option value="beach">Beach</option>
                    <option value="mountain">Mountain</option>
                    <option value="any">Any</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="field-label" for="p_activities">Preferred Activities</label>
                <input type="text" id="p_activities" class="field-input" placeholder="sightseeing, beach, hiking, food…">
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_accommodation">Accommodation</label>
                <select id="p_accommodation" class="field-select">
                    <option value="hotel">Hotel</option>
                    <option value="resort">Resort</option>
                    <option value="budget">Budget</option>
                    <option value="luxury">Luxury</option>
                    <option value="camping">Eco/Camping</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="field-label" for="p_transport">Transport</label>
                <select id="p_transport" class="field-select">
                    <option value="mixed">Mixed (optimal)</option>
                    <option value="private">Private car</option>
                    <option value="public">Public transport</option>
                    <option value="flights">Flights</option>
                </select>
            </div>
            <div class="col-12">
                <label class="field-label" for="p_special">Special Requests (optional)</label>
                <textarea id="p_special" class="field-textarea" placeholder="Dietary needs, accessibility, specific places to visit, things to avoid…"></textarea>
            </div>
            <div class="col-12">
                <button class="btn-generate" id="genBtn" onclick="generatePlan()">
                    <i class="fa fa-calendar"></i>
                    <span id="genBtnText">Generate My Itinerary</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Thinking -->
    <div class="ai-thinking" id="thinkingDiv">
        <div class="ai-spinner"></div>
        <h4 style="font-weight:800;color:var(--text);">AI is writing your full trip guide…</h4>
        <p id="thinkingMsg" style="color:var(--text-muted);">This takes up to a minute: it plans every stop, fare and meal</p>
    </div>

    <!-- Itinerary Output -->
    <div class="itinerary-wrap" id="itinWrap">
        <div id="itinContent"></div>
        <div style="text-align:center;margin-top:20px;">
            <button class="btn-download" onclick="printItinerary()"><i class="fa fa-print"></i> Print / Save as PDF</button>
            <button class="btn-download" onclick="downloadItinerary()"><i class="fa fa-download"></i> Download Plan</button>
            <button class="btn-regen" id="regenToggleBtn" onclick="toggleRegenBox()"><i class="fa fa-refresh"></i> Regenerate</button>
        </div>
        <!-- NEW (AI-trip-planning round): "Regenerate" used to just re-run the
             exact same generation call with identical inputs -- since the
             backend caches by trip details, that could silently hand back the
             same itinerary. This box lets the traveler say what should change,
             wired to ItineraryPlanner::regenerate() via the new
             action=itinerary_regenerate endpoint (real, previously-dead code
             on the backend, now reachable). Feedback is optional -- leaving it
             blank still asks for a genuinely different alternative, it just
             won't be steered toward anything specific. -->
        <div id="regenBox" style="display:none;max-width:640px;margin:16px auto 0;text-align:left;">
            <label class="field-label" for="regenFeedback">What would you like changed? (optional)</label>
            <textarea id="regenFeedback" class="field-textarea" placeholder="e.g. less walking on day 2, a cheaper hotel, add a beach day, more free time in the evenings…"></textarea>
            <button class="btn-generate" style="margin-top:10px;width:100%;" id="regenGoBtn" onclick="regeneratePlan()">
                <i class="fa fa-magic"></i> <span id="regenGoBtnText">Regenerate With This Feedback</span>
            </button>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>

<script>
document.getElementById('p_start').valueAsDate = new Date(Date.now() + 7*24*60*60*1000);

let currentItinerary = null;
const thinkMsgs = [
    'Analyzing destination highlights…',
    'Checking local events and festivals…',
    'Optimizing daily schedule for efficiency…',
    'Calculating budget breakdown…',
    'Adding hidden gems and local tips…',
    'Finalizing your perfect itinerary…'
];

// Shared by generatePlan() and regeneratePlan() (AI-trip-planning round) so
// both build the exact same trip-details FormData instead of two copies that
// can silently drift apart. `extra` adds/overrides fields for regeneration
// (action=itinerary_regenerate, feedback=...).
function buildPlanFormData(extra) {
    const fd = new FormData();
    fd.append('action', 'itinerary');
    fd.append('destination',     document.getElementById('p_dest').value.trim());
    fd.append('origin',          (document.getElementById('p_origin') || {value: ''}).value.trim());
    fd.append('engine',          (document.getElementById('p_engine') || {value: ''}).value);
    fd.append('duration',        document.getElementById('p_days').value);
    fd.append('travelers',       document.getElementById('p_travelers').value);
    fd.append('budget',          document.getElementById('p_budget').value || 0);
    fd.append('start_date',      document.getElementById('p_start').value);
    fd.append('travel_type',     document.getElementById('p_style').value);
    fd.append('climate',         document.getElementById('p_climate').value);
    fd.append('activities',      document.getElementById('p_activities').value);
    fd.append('accommodation',   document.getElementById('p_accommodation').value);
    fd.append('transport',       document.getElementById('p_transport').value);
    fd.append('special_requests',document.getElementById('p_special').value);
    // FIX (Round 110): see the tms_csrf_field() comment above this page's
    // <form> markup -- without this, api/ai.php rejects every request here
    // with a 403 before it ever reaches the AI.
    fd.append('csrf_token',      (document.querySelector('[name="csrf_token"]') || {}).value || '');
    if (extra) { for (const k in extra) fd.set(k, extra[k]); }
    return fd;
}

// Shared request/loading-state logic for both the initial generate and a
// regenerate call -- `busyEls` lets each caller disable/relabel its own
// trigger button while the request is in flight.
async function runPlanRequest(fd, busyBtn, busyBtnText, busyLabel, idleLabel) {
    document.getElementById('itinWrap').style.display   = 'none';
    document.getElementById('thinkingDiv').style.display = 'block';
    busyBtn.disabled = true;
    busyBtnText.textContent = busyLabel;

    let mi = 0;
    const interval = setInterval(() => {
        document.getElementById('thinkingMsg').textContent = thinkMsgs[mi++ % thinkMsgs.length];
    }, 2000);

    try {
        const res  = await fetch('api/ai.php', { method: 'POST', body: fd });
        const json = await res.json();
        clearInterval(interval);
        document.getElementById('thinkingDiv').style.display = 'none';
        busyBtn.disabled = false;
        busyBtnText.textContent = idleLabel;

        if (json.success && json.data.itinerary) {
            currentItinerary = json.data.itinerary;
            showItinerary(currentItinerary);
            return true;
        }
        tmsToast(json.error || 'Failed to generate itinerary. Please try again.');
        return false;
    } catch(e) {
        clearInterval(interval);
        document.getElementById('thinkingDiv').style.display = 'none';
        busyBtn.disabled = false;
        busyBtnText.textContent = idleLabel;
        tmsToast('Network error. Please try again.');
        return false;
    }
}

async function generatePlan() {
    const dest = document.getElementById('p_dest').value.trim();
    if (!dest) { tmsToast('Please enter a destination.'); return; }

    const genBtn = document.getElementById('genBtn');
    const genBtnText = document.getElementById('genBtnText');
    await runPlanRequest(buildPlanFormData(), genBtn, genBtnText, 'Generating…', 'Generate My Itinerary');
}

// NEW (AI-trip-planning round): see the #regenBox markup above -- wires the
// "Regenerate" flow to ItineraryPlanner::regenerate() (via the new
// action=itinerary_regenerate endpoint) instead of generatePlan() calling
// itself with identical inputs, which the backend's own caching could return
// unchanged.
function toggleRegenBox() {
    const box = document.getElementById('regenBox');
    const show = box.style.display === 'none';
    box.style.display = show ? 'block' : 'none';
    if (show) document.getElementById('regenFeedback').focus();
}

async function regeneratePlan() {
    if (!currentItinerary) { tmsToast('Generate an itinerary first.'); return; }
    const feedback = document.getElementById('regenFeedback').value.trim();
    const fd = buildPlanFormData({ action: 'itinerary_regenerate', feedback: feedback });

    const goBtn = document.getElementById('regenGoBtn');
    const goBtnText = document.getElementById('regenGoBtnText');
    const ok = await runPlanRequest(fd, goBtn, goBtnText, 'Regenerating…', 'Regenerate With This Feedback');
    if (ok) {
        document.getElementById('regenFeedback').value = '';
        // Left open (rather than hidden) so the traveler can immediately give
        // another round of feedback if the new plan still isn't quite right.
    }
}

function renderChecks(itin) {
    let html = '';
    if (itin.validation && itin.validation.status) {
        // Computed in code (PlanValidator), not by the model: status, what was changed, trade-offs, and which places are bookable.
        const v = itin.validation, cc = itin.computed_cost || {}, bc = itin.budget_check || null;
        const label = { ok: 'No problems found', adjusted: 'Adjusted by our checks', infeasible: 'Does not work as asked' }[v.status] || v.status;
        const colour = v.status === 'infeasible' ? '#b91c1c' : (v.status === 'adjusted' ? '#92400e' : '#166534');
        const reasons = (v.reasons || []).map(r => '<li>' + esc(r.message) + '</li>').join('');
        const adj = (v.adjustments || []).slice(0, 6).map(a => '<li>' + (a.day != null ? 'Day ' + esc(String(a.day)) + ': ' : '') + esc(a.message) + '</li>').join('');
        const trade = bc && bc.status === 'over_budget' ? (bc.trade_offs || []).map(t => '<li>' + esc(t) + '</li>').join('') : '';
        html += `<div role="note" style="background:#f8fafc;color:#1f2937;border:1px solid #cbd5e1;border-left:4px solid ${colour};border-radius:12px;padding:16px;margin-top:20px;font-size:13px;">
            <strong style="color:${colour};">Checked by code: ${esc(label)}</strong> <span style="color:#475569;">(${esc(v.mode || 'standard')} mode)</span>
            ${cc.total ? `<div style="margin-top:6px;">Cost recalculated by us: <strong>৳${Number(cc.total).toLocaleString()}</strong> (${Number(cc.verified_from_catalogue || 0).toLocaleString()} from our catalogue prices, the rest are estimates).${bc ? ' Budget ' + (bc.status === 'within_budget' ? 'respected.' : 'exceeded by ৳' + Number(bc.over_by).toLocaleString() + '.') : ''}</div>` : ''}
            ${reasons ? '<ul style="margin:6px 0 0 18px;padding:0;color:#b91c1c;">' + reasons + '</ul>' : ''}
            ${adj ? '<div style="margin-top:6px;">What we changed:</div><ul style="margin:2px 0 0 18px;padding:0;color:#92400e;">' + adj + '</ul>' : ''}
            ${trade ? '<div style="margin-top:6px;">Ways to bring it under budget:</div><ul style="margin:2px 0 0 18px;padding:0;">' + trade + '</ul>' : ''}
            <div style="margin-top:6px;color:#475569;font-size:12px;">Places marked "suggestion (not bookable)" are not in our catalogue, so their prices are estimates. Times, totals and order were checked by code, not by the AI. This is not a booking or a quote.</div></div>`;
    }

    if (itin.plan_check) {
        const pc = itin.plan_check, items = pc.issues || [];
        html += `<div role="note" style="background:#f1f5f9;color:#1f2937;border:1px solid #cbd5e1;border-radius:12px;padding:16px;margin-top:20px;font-size:13px;">
            <strong>Plan check:</strong> ${items.length ? items.length + ' thing' + (items.length === 1 ? '' : 's') + ' to look at before you rely on this plan.' : 'no timing, date or cost contradictions found by our automatic checks. That does not guarantee the plan works in practice.'}
            ${items.length ? '<ul style="margin:6px 0 0 18px;padding:0;color:#92400e;">' + items.slice(0, 8).map(i => '<li>' + (i.day != null ? 'Day ' + esc(String(i.day)) + ': ' : '') + esc(i.message) + '</li>').join('') + '</ul>' : ''}
            <div style="margin-top:6px;color:#475569;font-size:12px;">Automatic checks of the plan's own times, dates and costs. They cannot tell whether the plan is good, only where it contradicts itself.</div></div>`;
    }

    if (itin.ai_notes) {
        html += `<div style="background:#fffbeb;border-radius:12px;padding:16px;margin-top:20px;font-size:13px;color:#744210;border-left:4px solid #f6ad55;">
            <strong>🤖 AI Notes:</strong> ${esc(itin.ai_notes)}</div>`;
    }

    return html;
}

function fmtMoney(n) { n = Number(n); return (isFinite(n) && n > 0) ? '৳' + Math.round(n).toLocaleString() : ''; }
function mapsLink(q) { return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q); }
function listItems(arr, icon) {
    return (arr || []).filter(Boolean).map(x => `<div class="extras-item">${icon} ${esc(typeof x === 'string' ? x : JSON.stringify(x))}</div>`).join('');
}

function renderItinerary(itin) {
    const cost = itin.estimated_cost || {};
    const days = itin.days || [];
    const trav = Number((document.getElementById('p_travelers') || {}).value) || 2;
    const destName = itin.destination || (document.getElementById('p_dest') || {}).value || '';
    const grand = Number(cost.grand_total || cost.total || 0);
    const typeIcons = {
        sightseeing: '<i class="fa fa-camera"></i>', meal: '<i class="fa fa-cutlery"></i>', transport: '<i class="fa fa-bus"></i>',
        accommodation: '<i class="fa fa-bed"></i>', activity: '<i class="fa fa-star"></i>'
    };
    let html = '';

    if (itin.is_fallback) {
        html += `<div class="tp-banner tp-banner-warn" role="alert"><strong><i class="fa fa-exclamation-triangle"></i> This is a basic draft, not the full AI guide.</strong>
            <div>${esc(itin.ai_notes || 'The AI planner could not finish this time.')}${itin.ai_error ? ' <em>(' + esc(itin.ai_error) + ')</em>' : ''}</div>
            <button type="button" class="tp-btn" onclick="generatePlan()"><i class="fa fa-refresh"></i> Try again</button></div>`;
    }

    if (itin.engine === 'brain') {
        html += `<div class="tp-banner" role="note"><strong><i class="fa fa-bolt"></i> Planned by the TourBuddy Brain</strong> <span>Built-in travel engine: instant, works offline, no outside AI. Prices are estimates.</span></div>`;
    }

    html += `<div class="itin-header">
        <div class="itin-title">${esc(itin.trip_title || 'Your Trip Plan')}</div>
        ${itin.overview ? `<p class="tp-overview">${esc(itin.overview)}</p>` : ''}
        <div class="itin-meta">
            <span class="itin-meta-item"><i class="fa fa-map-marker"></i> ${esc(destName)}</span>
            <span class="itin-meta-item"><i class="fa fa-calendar"></i> ${esc(String(itin.total_duration || days.length))} days</span>
            <span class="itin-meta-item"><i class="fa fa-users"></i> ${trav} traveller${trav === 1 ? '' : 's'}</span>
            ${grand ? `<span class="itin-meta-item"><i class="fa fa-money"></i> Est. ${fmtMoney(grand)} total · ${fmtMoney(cost.per_person || grand / trav)} each</span>` : ''}
        </div>
        ${itin.best_visiting_time ? `<div class="tp-best"><i class="fa fa-sun-o"></i> <strong>Best time to visit:</strong> ${esc(itin.best_visiting_time)}</div>` : ''}
    </div>`;

    // ---- getting there
    const gt = (itin.getting_there || []).filter(o => o && typeof o === 'object');
    if (gt.length) {
        html += `<h5 class="tp-h"><i class="fa fa-road"></i> Getting there</h5><div class="tp-grid">` + gt.map(o => `
            <div class="tp-card">
                <div class="tp-card-top"><span class="tp-pill">${esc(o.mode || 'Travel')}</span>${o.comfort ? `<span class="tp-pill tp-pill-soft">${esc(o.comfort)}</span>` : ''}</div>
                <div class="tp-card-title">${esc(o.route || '')}</div>
                <div class="tp-line"><i class="fa fa-clock-o"></i> ${esc(o.duration || '')}${o.departure_tip ? ' · ' + esc(o.departure_tip) : ''}</div>
                ${Number(o.fare_per_person_one_way) > 0 ? `<div class="tp-line tp-money"><i class="fa fa-money"></i> ${fmtMoney(o.fare_per_person_one_way)} per person one way</div>` : ''}
                ${o.operators ? `<div class="tp-line"><i class="fa fa-bus"></i> ${esc(o.operators)}</div>` : ''}
                ${o.tip ? `<div class="tp-tip">💡 ${esc(o.tip)}</div>` : ''}
            </div>`).join('') + `</div>`;
    }

    // ---- cost summary
    const parts = [['Accommodation', cost.accommodation], ['Getting there (return)', cost.getting_there], ['Local transport', cost.transport], ['Food', cost.food], ['Activities & entry', cost.activities], ['Buffer & misc.', cost.miscellaneous]];
    const maxPart = Math.max(1, ...parts.map(p => Number(p[1]) || 0));
    html += `<h5 class="tp-h"><i class="fa fa-money"></i> What it will cost</h5>
    <div class="tp-cost">
        <div class="tp-cost-rows">${parts.map(p => `<div class="tp-cost-row"><span class="tp-cost-label">${esc(p[0])}</span>
            <span class="tp-cost-bar"><span style="width:${Math.round(100 * (Number(p[1]) || 0) / maxPart)}%"></span></span>
            <span class="tp-cost-val">${fmtMoney(p[1]) || '—'}</span></div>`).join('')}</div>
        <div class="tp-cost-total"><div><small>Total for ${trav}</small><strong>${fmtMoney(grand) || '—'}</strong></div>
            <div><small>Per person</small><strong>${fmtMoney(cost.per_person || (grand && grand / trav)) || '—'}</strong></div>
            ${itin.budget_check_total ? (itin.budget_check_total.status === 'within_budget'
                ? `<div class="tp-ok"><i class="fa fa-check-circle"></i> ${fmtMoney(itin.budget_check_total.difference)} under your budget</div>`
                : `<div class="tp-over"><i class="fa fa-exclamation-circle"></i> ${fmtMoney(itin.budget_check_total.difference)} over your budget</div>`) : ''}</div>
    </div>
    <div class="tp-fine">Amounts are estimates in BDT. Items matched to the TourBuddy catalogue use its real prices; the journey is the cheapest option listed above, both ways; ${esc(cost.buffer_basis || 'a small buffer is added')}.</div>`;

    // ---- days
    html += `<div class="tp-days-head"><h5 class="tp-h" style="margin:0;"><i class="fa fa-list-alt"></i> Day-by-day guide</h5>
        <span><button type="button" class="tp-link" onclick="toggleAllDays(true)">Expand all</button> · <button type="button" class="tp-link" onclick="toggleAllDays(false)">Collapse all</button></span></div>`;

    days.forEach((day, i) => {
        const schedule = (day.schedule || []);
        const md = day.meal_details || {};
        const stay = (day.stay && typeof day.stay === 'object') ? day.stay : null;
        html += `
        <div class="day-card">
            <div class="day-header" onclick="toggleDay(${i})">
                <span class="day-number">Day ${esc(String(day.day))}</span>
                <span class="day-title">${esc(day.title || '')}${day.date ? ` <small class="tp-date">${esc(day.date)}</small>` : ''}</span>
                <span class="day-budget-chip">${fmtMoney(day.daily_budget) || '—'}</span>
            </div>
            <div class="day-body" id="dayBody${i}">
                ${day.overview ? `<p class="tp-day-overview">${esc(day.overview)}</p>` : ''}
                <div class="tp-day-facts">
                    ${day.area ? `<span><i class="fa fa-map-signs"></i> ${esc(day.area)}</span>` : ''}
                    ${day.weather_note ? `<span><i class="fa fa-cloud"></i> ${esc(day.weather_note)}</span>` : ''}
                    ${Number(day.daily_budget) > 0 ? `<span><i class="fa fa-money"></i> ${fmtMoney(day.daily_budget)} for the group · about ${fmtMoney(day.daily_budget / trav)} each</span>` : ''}
                </div>`;

        schedule.forEach(s => {
            const t = s.type || 'sightseeing';
            const hop = (s.how_to_get_there && typeof s.how_to_get_there === 'object') ? s.how_to_get_there : null;
            const place = s.location || s.activity || '';
            const book = (s.catalogue_id && (t === 'accommodation' || s.source === 'catalogue'))
                ? (t === 'accommodation' ? `hotel-details.php?id=${encodeURIComponent(s.catalogue_id)}` : `activity-details.php?id=${encodeURIComponent(s.catalogue_id)}`) : '';
            html += `
            <div class="schedule-item">
                <div class="schedule-time">${esc(s.time || '')}</div>
                <div class="schedule-icon ${esc(t)}">${typeIcons[t] || '🔹'}</div>
                <div class="schedule-content">
                    <div class="schedule-activity">${esc(s.activity || '')}</div>
                    <div class="schedule-location"><i class="fa fa-map-marker"></i> ${esc(s.location || '')}${s.area ? ' · ' + esc(s.area) : ''}
                        ${place ? ` <a class="tp-maplink" href="${mapsLink(place + ' ' + destName)}" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i> Map</a>` : ''}</div>
                    ${s.what_to_do ? `<div class="tp-what">${esc(s.what_to_do)}</div>` : ''}
                    <div class="schedule-meta">
                        ${s.duration ? `<span><i class="fa fa-clock-o"></i> ${esc(s.duration)}</span>` : ''}
                        ${Number(s.cost) > 0 ? `<span class="tp-money"><i class="fa fa-money"></i> ${fmtMoney(s.cost)} per person${s.cost_note ? ' · ' + esc(s.cost_note) : ''}</span>` : (s.cost_note ? `<span class="tp-money"><i class="fa fa-money"></i> ${esc(s.cost_note)}</span>` : '')}
                        ${s.booking ? `<span><i class="fa fa-ticket"></i> ${esc(s.booking)}</span>` : ''}
                        ${s.source === 'catalogue' ? '<span class="tp-badge">On TourBuddy</span>' : ''}
                    </div>
                    ${hop ? `<div class="tp-hop"><i class="fa fa-arrow-right"></i> ${hop.from ? 'From ' + esc(hop.from) + ': ' : ''}${esc(hop.mode || '')}${hop.duration ? ', ' + esc(hop.duration) : ''}${Number(hop.cost_group) > 0 ? ', about ' + fmtMoney(hop.cost_group) + ' for the group' : ''}</div>` : ''}
                    ${s.tip ? `<div class="tp-tip">💡 ${esc(s.tip)}</div>` : ''}
                    ${s.alternative ? `<div class="tp-tip">↺ Alternative: ${esc(s.alternative)}</div>` : ''}
                    ${(s.notes && s.notes !== s.tip) ? `<div class="tp-tip">${esc(s.notes)}</div>` : ''}
                    ${book ? `<a class="tp-book" href="${book}"><i class="fa fa-calendar-check-o"></i> View &amp; book on TourBuddy</a>` : ''}
                </div>
            </div>`;
        });

        const meals = ['breakfast', 'lunch', 'dinner'].map(k => {
            const m = md[k]; const flat = (day.meal_suggestions || {})[k];
            if (m && typeof m === 'object') return `<div class="tp-meal"><b>${k.charAt(0).toUpperCase() + k.slice(1)}</b><span>${esc(m.place || '')}</span><em>${esc(m.dish || '')}</em>${Number(m.cost_per_person) > 0 ? `<small>${fmtMoney(m.cost_per_person)} each</small>` : ''}</div>`;
            if (typeof flat === 'string' && flat) return `<div class="tp-meal"><b>${k.charAt(0).toUpperCase() + k.slice(1)}</b><span>${esc(flat)}</span></div>`;
            return '';
        }).join('');
        if (meals) html += `<div class="tp-meals">${meals}</div>`;

        if (stay && stay.name) {
            html += `<div class="tp-stay"><i class="fa fa-bed"></i> <strong>Tonight:</strong> ${esc(stay.name)}${stay.area ? ' · ' + esc(stay.area) : ''}
                ${Number(stay.price_group_per_night) > 0 ? ` · about ${fmtMoney(stay.price_group_per_night)} per night for the group` : ''}${stay.booking_tip ? ` <span class="tp-fine">${esc(stay.booking_tip)}</span>` : ''}</div>`;
        }
        if (day.pro_tip) html += `<div class="day-pro-tip"><span>⭐</span><span><strong>Pro tip:</strong> ${esc(day.pro_tip)}</span></div>`;
        html += `</div></div>`;
    });

    // ---- where to stay / eat
    const hotels = (itin.hotel_recommendations || []).filter(h => h && typeof h === 'object');
    const rests = (itin.restaurant_recommendations || []).filter(h => h && typeof h === 'object');
    if (hotels.length || rests.length) {
        const card = (h, kind) => `<div class="tp-card"><div class="tp-card-top"><span class="tp-pill tp-pill-soft">${esc(h.tier || h.cuisine || kind)}</span>${h.verified ? '<span class="tp-badge">Verified on map</span>' : ''}</div>
            <div class="tp-card-title">${esc(h.name || '')}</div>
            ${h.area ? `<div class="tp-line"><i class="fa fa-map-marker"></i> ${esc(h.area)}</div>` : ''}
            ${h.price_range ? `<div class="tp-line tp-money"><i class="fa fa-money"></i> ${esc(h.price_range)}</div>` : ''}
            ${(h.why || h.must_try) ? `<div class="tp-line">${esc(h.why || ('Must try: ' + h.must_try))}</div>` : ''}</div>`;
        html += `<h5 class="tp-h"><i class="fa fa-bed"></i> Where to stay and eat</h5><div class="tp-grid">${hotels.map(h => card(h, 'Hotel')).join('')}${rests.map(h => card(h, 'Restaurant')).join('')}</div>`;
    }

    // ---- extras
    const emergency = itin.emergency_info || {};
    const before = itin.before_you_go || [];
    html += `<div class="extras-grid">
        ${before.length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-check-square-o"></i> Before you go</div>${listItems(before, '✅')}</div>` : ''}
        ${(itin.transportation_suggestions || []).length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-bus"></i> Getting around</div>${listItems(itin.transportation_suggestions, '🚌')}</div>` : ''}
        ${(itin.hidden_gems || []).length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-diamond"></i> Hidden gems</div>${listItems(itin.hidden_gems, '✨')}</div>` : ''}
        ${(itin.money_saving_tips || []).length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-money"></i> Money-saving tips</div>${listItems(itin.money_saving_tips, '💰')}</div>` : ''}
        ${(itin.safety_tips || []).length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-shield"></i> Safety</div>${listItems(itin.safety_tips, '🛡️')}</div>` : ''}
        ${(itin.packing_essentials || []).length ? `<div class="extras-card"><div class="extras-title"><i class="fa fa-suitcase"></i> What to pack</div>${listItems(itin.packing_essentials, '📦')}</div>` : ''}
        <div class="extras-card"><div class="extras-title"><i class="fa fa-phone"></i> Emergency</div>
            ${emergency.local_police ? `<div class="extras-item">🚔 Police: ${esc(emergency.local_police)}</div>` : ''}
            ${emergency.ambulance ? `<div class="extras-item">🚑 Ambulance: ${esc(emergency.ambulance)}</div>` : ''}
            ${emergency.nearest_hospital ? `<div class="extras-item">🏥 ${esc(emergency.nearest_hospital)}</div>` : ''}
            ${emergency.embassy_if_abroad && !/not applicable/i.test(emergency.embassy_if_abroad) ? `<div class="extras-item">🏛️ ${esc(emergency.embassy_if_abroad)}</div>` : ''}</div>
    </div>`;

    return html + renderChecks(itin);
}

function showItinerary(itin) {
    document.getElementById('itinContent').innerHTML = renderItinerary(itin);
    document.getElementById('itinWrap').style.display = 'block';
    document.getElementById('itinWrap').scrollIntoView({ behavior: 'smooth' });
}

function costBox(label, amount, isTotal = false) {
    return `<div class="cost-box" style="${isTotal?'background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;':''}">
        <div class="amount" style="${isTotal?'color:#fff;':''}">${amount ? '৳'+Number(amount).toLocaleString() : '—'}</div>
        <div class="label" style="${isTotal?'color:rgba(255,255,255,.8);':''}">${label}</div>
    </div>`;
}

function toggleAllDays(open) {
    document.querySelectorAll('.day-body').forEach(b => { b.style.display = open ? 'block' : 'none'; });
}

function printItinerary() { window.print(); }

function toggleDay(i) {
    const body = document.getElementById('dayBody'+i);
    body.style.display = body.style.display === 'none' ? 'block' : 'none';
}

function downloadItinerary() {
    if (!currentItinerary) return;
    const blob = new Blob([JSON.stringify(currentItinerary, null, 2)], {type:'application/json'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'my-trip-plan.json';
    a.click();
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>
</body>
</html>
