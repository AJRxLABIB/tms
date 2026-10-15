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
<meta name="apple-mobile-web-app-title" content="TMS">
<meta name="mobile-web-app-capable" content="yes">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Trip Planner | TMS</title>
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
                <label class="field-label" for="p_dest">Destination *</label>
                <input type="text" id="p_dest" class="field-input" placeholder="e.g. Cox's Bazar, Bandarban, Thailand…">
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
        <h4 style="font-weight:800;color:var(--text);">AI is crafting your itinerary…</h4>
        <p id="thinkingMsg" style="color:var(--text-muted);">Analyzing destination and optimizing your schedule</p>
    </div>

    <!-- Itinerary Output -->
    <div class="itinerary-wrap" id="itinWrap">
        <div id="itinContent"></div>
        <div style="text-align:center;margin-top:20px;">
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
            renderItinerary(currentItinerary);
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

function renderItinerary(itin) {
    const cost = itin.estimated_cost || {};
    const days  = itin.days || [];

    const typeIcons = {
        sightseeing: '<i class="fa fa-camera"></i>',
        meal:        '<i class="fa fa-cutlery"></i>',
        transport:   '<i class="fa fa-bus"></i>',
        accommodation:'<i class="fa fa-bed"></i>',
        activity:    '<i class="fa fa-star"></i>',
    };

    let html = `
    <div class="itin-header">
        <div class="itin-title">${esc(itin.trip_title || 'Your Trip Plan')}</div>
        <div class="itin-meta">
            <span class="itin-meta-item"><i class="fa fa-map-marker"></i> ${esc(itin.destination)}</span>
            <span class="itin-meta-item"><i class="fa fa-calendar"></i> ${itin.total_duration} days</span>
            <span class="itin-meta-item"><i class="fa fa-money"></i> Est. ৳${Number(cost.total||0).toLocaleString()}</span>
        </div>
    </div>

    <!-- Cost grid -->
    <div class="cost-grid">
        ${costBox('Accommodation', cost.accommodation)}
        ${costBox('Transport', cost.transport)}
        ${costBox('Food', cost.food)}
        ${costBox('Activities', cost.activities)}
        ${costBox('Miscellaneous', cost.miscellaneous)}
        ${costBox('TOTAL', cost.total, true)}
    </div>

    <!-- Days -->
    <h5 style="font-weight:800;color:var(--text);margin-bottom:16px;"><i class="fa fa-list-alt" style="color:#176B50;"></i> Day-by-Day Itinerary</h5>`;

    days.forEach((day, i) => {
        const schedule = (day.schedule || []);
        const meals    = day.meal_suggestions || {};

        html += `
        <div class="day-card">
            <div class="day-header" onclick="toggleDay(${i})">
                <span class="day-number">Day ${day.day}</span>
                <span class="day-title">${esc(day.title || '')}</span>
                <span class="day-weather-note"><i class="fa fa-cloud"></i> ${esc(day.weather_note || '')}</span>
                <span class="day-budget-chip">৳${Number(day.daily_budget||0).toLocaleString()}</span>
            </div>
            <div class="day-body" id="dayBody${i}" style="${i===0?'':'display:none'}">`;

        schedule.forEach(s => {
            const iconClass = s.type || 'sightseeing';
            html += `
            <div class="schedule-item">
                <div class="schedule-time">${esc(s.time||'')}</div>
                <div class="schedule-icon ${iconClass}">${typeIcons[iconClass]||'🔹'}</div>
                <div class="schedule-content">
                    <div class="schedule-activity">${esc(s.activity||'')}</div>
                    <div class="schedule-location"><i class="fa fa-map-marker"></i> ${esc(s.location||'')}</div>
                    <div class="schedule-meta">
                        <span><i class="fa fa-clock-o"></i> ${esc(s.duration||'')}</span>
                        ${s.cost ? `<span><i class="fa fa-money"></i> ৳${Number(s.cost).toLocaleString()}/person</span>` : ''}
                    </div>
                    ${s.notes ? `<div class="schedule-notes">💡 ${esc(s.notes)}</div>` : ''}
                </div>
            </div>`;
        });

        if (meals.breakfast || meals.lunch || meals.dinner) {
            html += `<div class="day-meals">
                🍽️ <strong>Meals:</strong>
                ${meals.breakfast ? `<b>Breakfast:</b> ${esc(meals.breakfast)} ` : ''}
                ${meals.lunch ? `&nbsp;|&nbsp; <b>Lunch:</b> ${esc(meals.lunch)} ` : ''}
                ${meals.dinner ? `&nbsp;|&nbsp; <b>Dinner:</b> ${esc(meals.dinner)}` : ''}
            </div>`;
        }

        if (day.pro_tip) {
            html += `<div class="day-pro-tip"><span>⭐</span><span><strong>Pro Tip:</strong> ${esc(day.pro_tip)}</span></div>`;
        }

        html += `</div></div>`;
    });

    // Extras
    const gems      = itin.hidden_gems || [];
    const tips      = itin.money_saving_tips || [];
    const packing   = itin.packing_essentials || [];
    const emergency = itin.emergency_info || {};

    html += `<div class="extras-grid">
        <div class="extras-card">
            <div class="extras-title"><i class="fa fa-diamond"></i> Hidden Gems</div>
            ${gems.map(g=>`<div class="extras-item">✨ ${esc(g)}</div>`).join('')}
        </div>
        <div class="extras-card">
            <div class="extras-title"><i class="fa fa-piggy-bank"></i> Money Saving Tips</div>
            ${tips.map(t=>`<div class="extras-item">💰 ${esc(t)}</div>`).join('')}
        </div>
        <div class="extras-card">
            <div class="extras-title"><i class="fa fa-suitcase"></i> Packing Essentials</div>
            ${packing.map(p=>`<div class="extras-item">📦 ${esc(p)}</div>`).join('')}
        </div>
        <div class="extras-card">
            <div class="extras-title"><i class="fa fa-phone"></i> Emergency Contacts</div>
            ${emergency.local_police ? `<div class="extras-item">🚔 Police: ${esc(emergency.local_police)}</div>` : ''}
            ${emergency.ambulance ? `<div class="extras-item">🚑 Ambulance: ${esc(emergency.ambulance)}</div>` : ''}
            ${emergency.nearest_hospital ? `<div class="extras-item">🏥 ${esc(emergency.nearest_hospital)}</div>` : ''}
        </div>
    </div>`;

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

    document.getElementById('itinContent').innerHTML = html;
    document.getElementById('itinWrap').style.display = 'block';
    document.getElementById('itinWrap').scrollIntoView({ behavior: 'smooth' });
}

function costBox(label, amount, isTotal = false) {
    return `<div class="cost-box" style="${isTotal?'background:linear-gradient(135deg,#176B50,#104B3A);color:#fff;':''}">
        <div class="amount" style="${isTotal?'color:#fff;':''}">${amount ? '৳'+Number(amount).toLocaleString() : '—'}</div>
        <div class="label" style="${isTotal?'color:rgba(255,255,255,.8);':''}">${label}</div>
    </div>`;
}

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
