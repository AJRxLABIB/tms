<?php
/**
 * travel-memory.php — AI Travel Memory Generator
 * Generates travel journals, trip summaries, and social media captions after trips.
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user();

$email = $_SESSION['login'];

// Get completed bookings
try {
    $stmt = $dbh->prepare(
        "SELECT b.BookingId, b.BookingDate, b.NoOfPersons, b.TotalCost,
                p.PackageName, p.PackageLocation, p.PackageDuration, p.PackageImage
         FROM tblbooking b JOIN tbltourpackages p ON p.PackageId=b.PackageId
         WHERE b.UserEmail=? AND b.status=1
         ORDER BY b.BookingDate DESC"
    );
    $stmt->execute([$email]);
    $bookings = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $bookings = []; }

// Get saved memories
try {
    $mStmt = $dbh->prepare("SELECT * FROM tbl_travel_memories WHERE user_email=? ORDER BY created_at DESC");
    $mStmt->execute([$email]);
    $savedMemories = $mStmt->fetchAll(PDO::FETCH_OBJ);
} catch(Exception $e) { $savedMemories = []; }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Travel Memories | TMS AI</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
.memory-hero {
    background: linear-gradient(135deg,#f093fb,#f5576c);
    padding:60px 0 40px; text-align:center; color:#fff;
}
.memory-hero h1 { font-size:2.2rem; font-weight:800; margin-bottom:10px; }
.memory-hero p { color:rgba(255,255,255,.8); }

.booking-selector { max-width:800px; margin:-30px auto 40px; }
.bs-card {
    background:#fff; border-radius:20px; padding:28px;
    box-shadow:0 10px 40px rgba(0,0,0,.1);
}
.bs-card h5 { font-weight:800; color:#1a202c; margin-bottom:20px; }

.trip-option {
    border:2px solid #f0f0f0; border-radius:14px; padding:16px;
    margin-bottom:12px; cursor:pointer; transition:.2s;
    display:flex; align-items:center; gap:14px;
}
.trip-option:hover { border-color:#f093fb; background:#fdf2ff; }
.trip-option.selected { border-color:#f093fb; background:#fdf2ff; }
.trip-img { width:70px; height:50px; border-radius:10px; object-fit:cover; flex-shrink:0; }
.trip-info { flex:1; }
.trip-info h6 { font-weight:800; color:#1a202c; margin-bottom:2px; font-size:14px; }
.trip-info p  { font-size:12px; color:#718096; margin:0; }
.trip-select-radio { width:20px; height:20px; border-radius:50%; border:2px solid #e2e8f0; flex-shrink:0; display:flex; align-items:center; justify-content:center; transition:.2s; }
.trip-option.selected .trip-select-radio { background:#f093fb; border-color:#f093fb; }
.trip-option.selected .trip-select-radio::after { content:''; width:8px; height:8px; background:#fff; border-radius:50%; }

.btn-generate-memory {
    background:linear-gradient(135deg,#f093fb,#f5576c);
    color:#fff; border:none; border-radius:12px; padding:14px 32px;
    font-size:15px; font-weight:700; cursor:pointer; width:100%;
    transition:.3s; margin-top:16px;
}
.btn-generate-memory:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(240,147,251,.4); }
.btn-generate-memory:disabled { opacity:.6; transform:none; }

/* Memory output */
.memory-output { max-width:800px; margin:0 auto; display:none; }
.journal-card {
    background:#fff; border-radius:20px; padding:32px;
    box-shadow:0 8px 30px rgba(0,0,0,.1); margin-bottom:24px;
}
.journal-card h4 { font-weight:800; color:#1a202c; margin-bottom:4px; display:flex; align-items:center; gap:10px; }
.journal-text { font-size:15px; line-height:1.9; color:#2d3748; white-space:pre-wrap; }

.caption-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.caption-card {
    background:#f8fafc; border-radius:12px; padding:16px;
    border:1px solid #e2e8f0; position:relative;
}
.caption-card p { font-size:13px; color:#2d3748; line-height:1.7; margin:0; }
.copy-btn { position:absolute; top:10px; right:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:4px 10px; font-size:11px; cursor:pointer; color:#667eea; transition:.2s; }
.copy-btn:hover { background:#667eea; color:#fff; }

.achievement-card {
    background:linear-gradient(135deg,#f093fb,#f5576c);
    border-radius:20px; padding:28px; text-align:center; color:#fff; margin-bottom:24px;
}
.achievement-icon { font-size:50px; margin-bottom:12px; }
.achievement-title { font-size:1.2rem; font-weight:800; margin-bottom:6px; }
.achievement-subtitle { font-size:13px; opacity:.85; }

.loading-state { text-align:center; padding:60px 20px; display:none; }
.loading-spin { width:50px; height:50px; border:3px solid #fce4ec; border-top-color:#f093fb; border-radius:50%; animation:spin 1s linear infinite; margin:0 auto 16px; }
@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:600px) { .caption-grid{grid-template-columns:1fr;} }
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<div class="memory-hero">
    <div class="container">
        <h1>📔 Travel Memory Generator</h1>
        <p>Let AI turn your trip into a beautiful travel journal and share-worthy captions</p>
    </div>
</div>

<div class="container" style="padding-bottom:60px;">
    <!-- Trip selector -->
    <div class="booking-selector">
        <div class="bs-card">
            <h5>🗺️ Select a Completed Trip</h5>
            <?php if(empty($bookings)): ?>
                <div style="text-align:center;padding:30px;color:#718096;">
                    <i class="fa fa-suitcase" style="font-size:40px;margin-bottom:12px;display:block;"></i>
                    <p>No confirmed bookings yet. <a href="package-list.php" style="color:#f093fb;">Browse packages</a> to start your travel journey!</p>
                </div>
            <?php else: ?>
                <?php foreach($bookings as $b): ?>
                <div class="trip-option" onclick="selectTrip(<?= $b->BookingId ?>, this)"
                     data-bid="<?= $b->BookingId ?>">
                    <img class="trip-img" loading="lazy"
                         src="<?= htmlspecialchars($b->PackageImage ?? 'images/1.jpg') ?>"
                         onerror="this.src='images/1.jpg'"
                         alt="<?= htmlspecialchars($b->PackageName) ?>">
                    <div class="trip-info">
                        <h6><?= htmlspecialchars($b->PackageName) ?></h6>
                        <p>
                            <i class="fa fa-map-marker"></i> <?= htmlspecialchars($b->PackageLocation) ?> &nbsp;|&nbsp;
                            <i class="fa fa-calendar"></i> <?= htmlspecialchars($b->BookingDate) ?> &nbsp;|&nbsp;
                            <i class="fa fa-users"></i> <?= $b->NoOfPersons ?> travelers
                        </p>
                    </div>
                    <div class="trip-select-radio"></div>
                </div>
                <?php endforeach; ?>

                <button class="btn-generate-memory" id="genMemoryBtn" onclick="generateMemory()" disabled>
                    ✨ Generate My Travel Memory
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Loading -->
    <div class="loading-state" id="loadingState">
        <div class="loading-spin"></div>
        <h5 style="font-weight:800;color:#1a202c;">AI is crafting your travel story…</h5>
        <p style="color:#718096;" id="loadMsg">Reliving your journey</p>
    </div>

    <!-- Memory Output -->
    <div class="memory-output" id="memoryOutput">
        <!-- Achievement Badge -->
        <div class="achievement-card" id="achievementCard">
            <div class="achievement-icon" id="achievementIcon">🏆</div>
            <div class="achievement-title" id="achievementTitle">Explorer</div>
            <div class="achievement-subtitle" id="achievementSubtitle">You've unlocked a new travel achievement!</div>
        </div>

        <!-- Journal -->
        <div class="journal-card">
            <h4><span>📝</span> Your Travel Journal</h4>
            <p style="font-size:13px;color:#718096;margin-bottom:20px;">An AI-crafted narrative of your adventure</p>
            <div class="journal-text" id="journalText"></div>
            <div style="margin-top:16px;">
                <button onclick="copyToClipboard('journalText')" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer;color:#4a5568;">
                    <i class="fa fa-copy"></i> Copy Journal
                </button>
            </div>
        </div>

        <!-- Summary -->
        <div class="journal-card" style="background:linear-gradient(135deg,#fdf2ff,#fce4ec);">
            <h4><span>✨</span> Trip Summary</h4>
            <p id="tripSummary" style="font-size:15px;color:#2d3748;line-height:1.7;"></p>
        </div>

        <!-- Social Captions -->
        <div class="journal-card">
            <h4><span>📱</span> Social Media Captions</h4>
            <p style="font-size:13px;color:#718096;margin-bottom:16px;">Ready to share on Instagram, Facebook, or WhatsApp</p>
            <div class="caption-grid" id="captionGrid"></div>
        </div>

        <!-- Share / Download -->
        <div style="text-align:center;padding:10px 0 40px;">
            <button onclick="downloadMemory()" style="background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;border:none;border-radius:12px;padding:12px 28px;font-size:14px;font-weight:700;cursor:pointer;margin-right:10px;">
                <i class="fa fa-download"></i> Download Memory
            </button>
            <button onclick="regenerateMemory()" style="background:#fff;border:2px solid #f093fb;color:#f5576c;border-radius:12px;padding:12px 28px;font-size:14px;font-weight:700;cursor:pointer;">
                <i class="fa fa-refresh"></i> Regenerate
            </button>
        </div>
    </div>

    <!-- Saved Memories -->
    <?php if(!empty($savedMemories)): ?>
    <div style="max-width:800px;margin:0 auto 40px;">
        <h5 style="font-weight:800;color:#1a202c;margin-bottom:16px;">📚 Your Saved Memories</h5>
        <?php foreach($savedMemories as $m): ?>
        <div style="background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 16px rgba(0,0,0,.07);margin-bottom:14px;">
            <div style="font-size:12px;color:#718096;margin-bottom:8px;"><?= date('d M Y', strtotime($m->created_at)) ?> · Booking #<?= $m->booking_id ?></div>
            <p style="font-size:13px;color:#2d3748;line-height:1.7;margin:0;"><?= htmlspecialchars(mb_substr($m->journal_text, 0, 200)) ?>…</p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include('includes/footer.php'); ?>

<script>
let selectedBid = 0;
let currentMemory = null;
const loadMsgs = ['Reliving your journey…','Crafting your story…','Writing your journal…','Creating social captions…','Generating your achievement…'];
let loadIdx = 0, loadInt;

function selectTrip(bid, el) {
    document.querySelectorAll('.trip-option').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    selectedBid = bid;
    document.getElementById('genMemoryBtn').disabled = false;
}

async function generateMemory() {
    if (!selectedBid) return;
    document.getElementById('memoryOutput').style.display = 'none';
    document.getElementById('loadingState').style.display  = 'block';
    document.getElementById('genMemoryBtn').disabled = true;

    loadIdx = 0;
    loadInt = setInterval(() => { document.getElementById('loadMsg').textContent = loadMsgs[loadIdx++ % loadMsgs.length]; }, 1800);

    const fd = new FormData();
    fd.append('action', 'memory');
    fd.append('booking_id', selectedBid);

    try {
        const res  = await fetch('api/ai.php', { method:'POST', body:fd });
        const json = await res.json();
        clearInterval(loadInt);
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('genMemoryBtn').disabled = false;

        if (json.success && json.data.memory) {
            currentMemory = json.data.memory;
            renderMemory(currentMemory);
        } else {
            tmsToast(json.error || 'Failed to generate memory. Please try again.');
        }
    } catch(e) {
        clearInterval(loadInt);
        document.getElementById('loadingState').style.display = 'none';
        document.getElementById('genMemoryBtn').disabled = false;
        tmsToast('Network error. Please try again.');
    }
}

function renderMemory(mem) {
    // Achievement
    const achieve = mem.achievement || 'Explorer';
    const icons   = {'Explorer':'🌍','Beach Lover':'🏖️','Mountain Climber':'🏔️','City Hopper':'🏙️','Adventurer':'⛺','Culture Seeker':'🏛️'};
    document.getElementById('achievementIcon').textContent    = icons[achieve] || '🏆';
    document.getElementById('achievementTitle').textContent   = achieve;
    document.getElementById('achievementSubtitle').textContent = 'Achievement unlocked for your journey!';

    // Journal
    document.getElementById('journalText').textContent = mem.journal || '';

    // Summary
    document.getElementById('tripSummary').textContent = mem.summary || '';

    // Captions
    const captions = mem.social_captions || [];
    const grid     = document.getElementById('captionGrid');
    grid.innerHTML = captions.map((c, i) => `
    <div class="caption-card">
        <p>${esc(c)}</p>
        <button class="copy-btn" onclick="copyCaption(${i})">Copy</button>
    </div>`).join('');

    document.getElementById('memoryOutput').style.display = 'block';
    document.getElementById('memoryOutput').scrollIntoView({behavior:'smooth'});
}

function copyCaption(i) {
    const captions = currentMemory?.social_captions || [];
    navigator.clipboard.writeText(captions[i] || '');
    event.target.textContent = 'Copied!';
    setTimeout(() => event.target.textContent = 'Copy', 1500);
}

function copyToClipboard(id) {
    const text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text);
    tmsToast('Copied to clipboard!');
}

function downloadMemory() {
    if (!currentMemory) return;
    const text = `TRAVEL MEMORY\n\n${currentMemory.achievement}\n\nJOURNAL:\n${currentMemory.journal}\n\nSUMMARY:\n${currentMemory.summary}\n\nSOCIAL CAPTIONS:\n${(currentMemory.social_captions||[]).join('\n\n')}`;
    const blob = new Blob([text], {type:'text/plain'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'travel-memory.txt';
    a.click();
}

function regenerateMemory() { generateMemory(); }

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>
</body>
</html>
