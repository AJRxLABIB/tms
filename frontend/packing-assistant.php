<?php
/**
 * packing-assistant.php — AI Smart Packing Checklist
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('backend/config.php');
tms_require_user(); // Batch 1 (landing/auth gating): whole app now requires login
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
<title>AI Packing Assistant | TMS</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
.pack-hero { background: linear-gradient(135deg,#134e5e,#71b280); padding:60px 0 40px; text-align:center; color:#fff; }
.pack-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:10px; }
.pack-hero p { color:rgba(255,255,255,.8); font-size:1rem; }

.pack-form-card { background:#fff; border-radius:20px; padding:32px; box-shadow:0 10px 40px rgba(0,0,0,.1); margin:-30px auto 40px; max-width:700px; }
.f-label { font-size:12px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; display:block; }
.f-input,.f-select { width:100%; padding:10px 14px; border:2px solid #e2e8f0; border-radius:10px; font-size:14px; outline:none; background:#f8fafc; transition:.2s; }
.f-input:focus,.f-select:focus { border-color:#134e5e; background:#fff; box-shadow:0 0 0 3px rgba(19,78,94,.1); }
.btn-pack { background:linear-gradient(135deg,#134e5e,#71b280); color:#fff; border:none; border-radius:12px; padding:14px 32px; font-size:15px; font-weight:700; cursor:pointer; width:100%; transition:.3s; }
.btn-pack:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(19,78,94,.4); }
.btn-pack:disabled { opacity:.6; transform:none; }

/* Checklist output */
.checklist-wrap { display:none; max-width:900px; margin:0 auto; padding-bottom:60px; }
.checklist-header { text-align:center; margin-bottom:28px; }
.checklist-header h2 { font-size:1.8rem; font-weight:800; color:#1a202c; }

.category-card { background:#fff; border-radius:16px; padding:24px; margin-bottom:20px; box-shadow:0 4px 16px rgba(0,0,0,.07); }
.cat-title { font-size:15px; font-weight:800; color:#1a202c; margin-bottom:16px; display:flex; align-items:center; gap:10px; }
.cat-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
.cat-icon.essentials    { background:#ebf8ff; }
.cat-icon.clothing      { background:#faf5ff; }
.cat-icon.toiletries    { background:#f0fff4; }
.cat-icon.electronics   { background:#fffbeb; }
.cat-icon.documents     { background:#fff5f5; }
.cat-icon.medicines     { background:#fef3c7; }
.cat-icon.destination   { background:#e6fffa; }

.item-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:8px; }
.pack-item {
    display:flex; align-items:center; gap:10px;
    padding:10px 12px; border-radius:10px; border:1px solid #f0f0f0;
    cursor:pointer; transition:.2s; background:#f8fafc;
}
.pack-item:hover { border-color:#134e5e; background:#f0faf5; }
.pack-item.checked { background:#f0fff4; border-color:#48bb78; }
.pack-item.checked .item-name { text-decoration:line-through; color:#a0aec0; }
.item-checkbox { width:18px; height:18px; border:2px solid #cbd5e0; border-radius:4px; flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:.2s; }
.pack-item.checked .item-checkbox { background:#48bb78; border-color:#48bb78; }
.pack-item.checked .item-checkbox::after { content:'✓'; color:#fff; font-size:11px; font-weight:800; }
.item-info { flex:1; }
.item-name { font-size:13px; font-weight:600; color:#2d3748; }
.item-qty  { font-size:11px; color:#718096; }
.priority-badge { font-size:10px; padding:2px 6px; border-radius:10px; flex-shrink:0; }
.priority-badge.must   { background:#fed7d7; color:#c53030; }
.priority-badge.should { background:#fefcbf; color:#744210; }
.priority-badge.nice   { background:#e2e8f0; color:#4a5568; }

.dest-specific-card { background:linear-gradient(135deg,#ebf8ff,#e9d8fd); border-radius:16px; padding:24px; margin-bottom:20px; }
.dest-specific-card h5 { font-weight:800; color:#1a202c; margin-bottom:14px; }

.progress-bar-wrap { background:#e2e8f0; border-radius:10px; height:10px; margin-bottom:6px; overflow:hidden; }
.progress-fill { height:100%; border-radius:10px; background:linear-gradient(90deg,#48bb78,#38a169); transition:.3s; }

.actions-bar { display:flex; gap:12px; justify-content:center; margin-bottom:30px; }
.btn-action { padding:10px 22px; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; transition:.2s; border:none; display:flex; align-items:center; gap:8px; }
.btn-action.print   { background:#f8fafc; border:2px solid #e2e8f0; color:#4a5568; }
.btn-action.print:hover { background:#edf2f7; }
.btn-action.save    { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
.btn-action.save:hover { opacity:.9; }

/* Spinner */
.loading-wrap { text-align:center; padding:60px 20px; display:none; }
.loading-spin { width:50px; height:50px; border:3px solid #e2e8f0; border-top-color:#134e5e; border-radius:50%; animation:spin 1s linear infinite; margin:0 auto 16px; }
@keyframes spin { to{transform:rotate(360deg)} }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div class="pack-hero">
    <div class="container">
        <h1>🧳 AI Packing Assistant</h1>
        <p>Tell us where you're going — AI generates your perfect packing list in seconds</p>
    </div>
</div>

<div class="container">
    <div class="pack-form-card">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="f-label" for="p_dest">Destination *</label>
                <input type="text" id="p_dest" class="f-input" placeholder="e.g. Bandarban, Cox's Bazar, Bali…">
            </div>
            <div class="col-md-3">
                <label class="f-label" for="p_days">Duration (days)</label>
                <input type="number" id="p_days" class="f-input" value="5" min="1" max="30">
            </div>
            <div class="col-md-3">
                <label class="f-label" for="p_climate">Climate</label>
                <select id="p_climate" class="f-select">
                    <option value="warm">Warm / Tropical</option>
                    <option value="cold">Cold / Mountainous</option>
                    <option value="beach">Beach</option>
                    <option value="rainy">Rainy Season</option>
                    <option value="mixed">Mixed</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="f-label" for="p_style">Travel Style</label>
                <select id="p_style" class="f-select">
                    <option value="leisure">Leisure / Sightseeing</option>
                    <option value="adventure">Adventure / Trekking</option>
                    <option value="beach">Beach Holiday</option>
                    <option value="business">Business Trip</option>
                    <option value="family">Family with Kids</option>
                    <option value="couple">Couple / Romantic</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="f-label" for="p_special">Special needs (optional)</label>
                <input type="text" id="p_special" class="f-input" placeholder="e.g. infant, medical equipment, photography…">
            </div>
            <div class="col-12">
                <button class="btn-pack" id="genBtn" onclick="generatePacking()">
                    <i class="fa fa-magic"></i> Generate My Packing List
                </button>
            </div>
        </div>
    </div>

    <!-- Loading -->
    <div class="loading-wrap" id="loadingWrap">
        <div class="loading-spin"></div>
        <h5 style="font-weight:800;color:#1a202c;">Building your packing list…</h5>
        <p style="color:#718096;" id="loadMsg">Checking destination weather and activities</p>
    </div>

    <!-- Checklist -->
    <div class="checklist-wrap" id="checklistWrap">
        <div class="checklist-header">
            <h2 id="checklistTitle">Your AI Packing List</h2>
            <p style="color:#718096;" id="checklistSubtitle"></p>
        </div>

        <!-- Progress -->
        <div style="background:#fff;border-radius:16px;padding:20px 24px;margin-bottom:20px;box-shadow:0 4px 16px rgba(0,0,0,.07);">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;font-weight:700;color:#1a202c;">
                <span>Packing Progress</span>
                <span id="progressText">0 of 0 packed</span>
            </div>
            <div class="progress-bar-wrap"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
        </div>

        <!-- Action buttons -->
        <div class="actions-bar">
            <button class="btn-action print" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
            <button class="btn-action save" onclick="saveChecklist()"><i class="fa fa-bookmark"></i> Save Progress</button>
        </div>

        <!-- Category cards rendered by JS -->
        <div id="categoriesContainer"></div>
    </div>
</div>

<?php include('backend/footer.php'); ?>

<script>
let packingData = null;
let totalItems  = 0;
let checkedItems = {};

const loadMsgs = ['Checking destination requirements…','Analysing weather conditions…','Reviewing activity list…','Generating category-by-category list…','Adding destination-specific items…'];
let loadIdx = 0, loadInt;

async function generatePacking() {
    const dest = document.getElementById('p_dest').value.trim();
    if (!dest) { tmsToast('Please enter a destination.'); return; }

    document.getElementById('checklistWrap').style.display = 'none';
    document.getElementById('loadingWrap').style.display   = 'block';
    document.getElementById('genBtn').disabled = true;

    loadIdx = 0;
    loadInt = setInterval(() => { document.getElementById('loadMsg').textContent = loadMsgs[loadIdx++ % loadMsgs.length]; }, 1800);

    const fd = new FormData();
    fd.append('action', 'packing_list');
    fd.append('destination', dest);
    fd.append('duration', document.getElementById('p_days').value);
    fd.append('climate', document.getElementById('p_climate').value);
    fd.append('travel_type', document.getElementById('p_style').value);

    try {
        const res  = await fetch('api/ai.php', { method: 'POST', body: fd });
        const json = await res.json();
        clearInterval(loadInt);
        document.getElementById('loadingWrap').style.display = 'none';
        document.getElementById('genBtn').disabled = false;

        if (json.success && json.data.packing_list) {
            packingData = json.data.packing_list;
            renderChecklist(packingData, dest);
        } else {
            tmsToast(json.error || 'Failed to generate packing list. Please try again.');
        }
    } catch(e) {
        clearInterval(loadInt);
        document.getElementById('loadingWrap').style.display = 'none';
        document.getElementById('genBtn').disabled = false;
        tmsToast('Network error. Please try again.');
    }
}

const catConfig = {
    essentials:  { label: 'Essentials',           icon: '🔑', cls: 'essentials' },
    clothing:    { label: 'Clothing',              icon: '👕', cls: 'clothing'   },
    toiletries:  { label: 'Toiletries',            icon: '🧴', cls: 'toiletries' },
    electronics: { label: 'Electronics & Gadgets', icon: '📱', cls: 'electronics'},
    documents:   { label: 'Documents & Money',     icon: '📄', cls: 'documents'  },
    medicines:   { label: 'Health & Medicines',    icon: '💊', cls: 'medicines'  },
    destination_specific: { label: 'Destination Specific', icon: '🌍', cls: 'destination' },
};

function renderChecklist(data, dest) {
    document.getElementById('checklistTitle').textContent     = `Packing List for ${dest}`;
    document.getElementById('checklistSubtitle').textContent  = `${parseInt(document.getElementById('p_days').value)}-day ${document.getElementById('p_style').value} trip`;
    document.getElementById('checklistWrap').style.display    = 'block';
    document.getElementById('checklistWrap').scrollIntoView({behavior:'smooth'});

    const container = document.getElementById('categoriesContainer');
    container.innerHTML = '';
    totalItems = 0;

    Object.entries(catConfig).forEach(([key, cfg]) => {
        const items = data[key];
        if (!items || !items.length) return;
        totalItems += items.length;

        const itemsHtml = items.map((item, idx) => {
            const id = `${key}_${idx}`;
            const qty = item.quantity ? `× ${item.quantity}` : '';
            const p   = item.priority || 'should';
            const pLabel = { must:'Must Have', should:'Recommended', nice:'Nice to Have' }[p] || p;
            // Destination-specific may have 'reason'
            const reasonHtml = item.reason ? `<div style="font-size:11px;color:#718096;margin-top:2px;">${esc(item.reason)}</div>` : '';
            return `<div class="pack-item" id="item_${id}" onclick="toggleItem('${id}')">
                <div class="item-checkbox" id="chk_${id}"></div>
                <div class="item-info">
                    <div class="item-name">${esc(item.item || item)}</div>
                    ${qty ? `<div class="item-qty">${qty}</div>` : ''}
                    ${reasonHtml}
                </div>
                <span class="priority-badge ${p}">${pLabel}</span>
            </div>`;
        }).join('');

        container.insertAdjacentHTML('beforeend', `
        <div class="category-card">
            <div class="cat-title">
                <div class="cat-icon ${cfg.cls}">${cfg.icon}</div>
                ${cfg.label}
                <span style="margin-left:auto;font-size:12px;font-weight:400;color:#718096;">${items.length} items</span>
            </div>
            <div class="item-grid">${itemsHtml}</div>
        </div>`);
    });

    // Packing tips
    if (data.packing_tips?.length) {
        const tipsHtml = data.packing_tips.map(t => `<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px;color:#4a5568;"><span>💡</span><span>${esc(t)}</span></div>`).join('');
        container.insertAdjacentHTML('beforeend', `
        <div class="category-card">
            <div class="cat-title"><div class="cat-icon essentials">💡</div> Packing Tips</div>
            ${tipsHtml}
        </div>`);
    }

    updateProgress();
}

function toggleItem(id) {
    checkedItems[id] = !checkedItems[id];
    const el  = document.getElementById('item_' + id);
    if (checkedItems[id]) el.classList.add('checked');
    else el.classList.remove('checked');
    updateProgress();
}

function updateProgress() {
    const checked = Object.values(checkedItems).filter(Boolean).length;
    const pct = totalItems ? Math.round(checked / totalItems * 100) : 0;
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressText').textContent = `${checked} of ${totalItems} packed (${pct}%)`;
}

function saveChecklist() {
    const state = JSON.stringify({ data: packingData, checked: checkedItems, ts: Date.now() });
    localStorage.setItem('tms_packing_' + document.getElementById('p_dest').value, state);
    tmsToast('Packing progress saved to your browser!');
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = (typeof str === 'string' ? str : JSON.stringify(str)) || '';
    return d.innerHTML;
}
</script>
</body>
</html>
