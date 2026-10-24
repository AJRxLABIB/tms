<?php
/**
 * virtual-guide.php — AI Virtual Tour Guide
 * Users can ask any question about any destination and get expert local knowledge.
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

// Pre-load destination if passed via URL
$preloadDest = tms_clean($_GET['dest'] ?? '');
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
<title>AI Virtual Guide | TourBuddy</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="<?php echo tms_asset_ver('css/modern-theme.css'); ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
.guide-layout { display:grid; grid-template-columns:280px 1fr; gap:24px; max-width:1100px; margin:30px auto; padding:0 20px 60px; }
@media(max-width:768px){ .guide-layout{grid-template-columns:1fr;} }

/* Sidebar */
.guide-sidebar { background:var(--white); border-radius:20px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,.08); align-self:start; position:sticky; top:20px; }
.guide-avatar { width:72px; height:72px; border-radius:20px; background:linear-gradient(135deg,#176B50,#104B3A); display:flex; align-items:center; justify-content:center; font-size:32px; margin:0 auto 16px; }
.guide-name { text-align:center; font-size:1rem; font-weight:800; color:var(--text); margin-bottom:4px; }
.guide-role { text-align:center; font-size:12px; color:var(--text-muted); margin-bottom:20px; }

.dest-input-wrap { margin-bottom:20px; }
.dest-input { width:100%; padding:10px 14px; border:2px solid #e2e8f0; border-radius:10px; font-size:14px; outline:none; transition:.2s; }
.dest-input:focus { border-color:#176B50; box-shadow:0 0 0 3px rgba(23,107,80,.12); }
.btn-set-dest { width:100%; background:linear-gradient(135deg,#176B50,#104B3A); border:none; border-radius:10px; padding:10px; font-size:13px; font-weight:700; color:#fff; cursor:pointer; transition:.2s; }
.btn-set-dest:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(246,173,85,.4); }

.guide-sidebar h6 { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:10px; }
.topic-btn { width:100%; text-align:left; background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:8px 12px; font-size:13px; color:var(--text); cursor:pointer; margin-bottom:6px; transition:.2s; display:flex; align-items:center; gap:8px; }
.topic-btn:hover { background:#E7F5EE; border-color:#176B50; color:#0E3B2D; }

/* Chat area */
.guide-chat { background:var(--white); border-radius:20px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,.08); display:flex; flex-direction:column; min-height:600px; }
.guide-chat-header { background:linear-gradient(135deg,#176B50,#104B3A); padding:20px 24px; }
.guide-chat-header h4 { font-size:1.1rem; font-weight:800; color:#fff; margin:0 0 4px; }
.guide-chat-header p  { font-size:13px; color:rgba(255,255,255,.88); margin:0; }

.guide-messages { flex:1; overflow-y:auto; padding:20px; background:var(--bg); display:flex; flex-direction:column; gap:14px; min-height:400px; }

.gmsg { max-width:80%; }
.gmsg-guide { align-self:flex-start; }
.gmsg-user  { align-self:flex-end; }
.gmsg-bubble { padding:12px 16px; border-radius:14px; font-size:14px; line-height:1.6; }
.gmsg-guide .gmsg-bubble { background:var(--white); color:var(--text); box-shadow:0 2px 8px rgba(0,0,0,.07); border-radius:4px 14px 14px 14px; color:var(--text); }
.gmsg-user  .gmsg-bubble { background:linear-gradient(135deg,#176B50,#104B3A); color:#fff; border-radius:14px 4px 14px 14px; }
.gmsg-name { font-size:11px; color:var(--secondary); font-weight:700; margin-bottom:4px; }

.typing { display:none; align-self:flex-start; }
.typing-bub { background:var(--white); box-shadow:0 2px 8px rgba(0,0,0,.07); border-radius:4px 14px 14px 14px; padding:12px 16px; display:inline-flex; gap:4px; }
.t-dot { width:7px; height:7px; border-radius:50%; background:#ccc; animation:tb 1.2s infinite; }
.t-dot:nth-child(2){ animation-delay:.2s; }
.t-dot:nth-child(3){ animation-delay:.4s; }
@keyframes tb { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-5px)} }

.guide-input-area { padding:14px 20px; border-top:1px solid #f0f0f0; display:flex; gap:10px; align-items:flex-end; }
.guide-input { flex:1; border:2px solid #e2e8f0; border-radius:12px; padding:10px 16px; font-size:14px; outline:none; resize:none; min-height:44px; max-height:120px; font-family:inherit; transition:.2s; }
.guide-input:focus { border-color:#176B50; box-shadow:0 0 0 3px rgba(23,107,80,.12); }
.guide-send { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#176B50,#104B3A); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#fff; font-size:16px; flex-shrink:0; transition:.2s; }
.guide-send:hover { transform:scale(1.05); }

.no-dest { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:40px; }
.no-dest-icon { font-size:60px; margin-bottom:20px; }
</style>
</head>
<body>
<?php include('backend/header.php'); ?>

<div style="background:linear-gradient(135deg,#0E2A21,#104B3A);padding:50px 0 30px;text-align:center;color:#fff;margin-bottom:0;">
    <h1 style="font-size:2rem;font-weight:800;margin-bottom:8px;">🗺️ AI Virtual Tour Guide</h1>
    <p style="color:rgba(255,255,255,.7);">Ask a local expert AI anything about your destination</p>
</div>

<div class="guide-layout">
    <!-- Sidebar -->
    <div class="guide-sidebar">
        <div class="guide-avatar">🧭</div>
        <div class="guide-name">AI Local Expert</div>
        <div class="guide-role">Knows every destination inside out</div>

        <div class="dest-input-wrap">
            <label for="destInput" style="font-size:12px;font-weight:700;color:#4a5568;display:block;margin-bottom:6px;">DESTINATION</label>
            <input type="text" id="destInput" class="dest-input" placeholder="Enter destination…" value="<?= htmlspecialchars($preloadDest) ?>">
            <br><br>
            <button class="btn-set-dest" onclick="setDestination()">🌍 Activate Guide</button>
        </div>

        <h6>Popular Questions</h6>
        <button class="topic-btn" onclick="askTopic('What are the top 5 must-see attractions?')">📸 Top attractions</button>
        <button class="topic-btn" onclick="askTopic('What is the best local food to try?')">🍜 Local food</button>
        <button class="topic-btn" onclick="askTopic('What are the local customs and etiquette I should know?')">🙏 Local customs</button>
        <button class="topic-btn" onclick="askTopic('What are the best photography spots?')">📷 Photo spots</button>
        <button class="topic-btn" onclick="askTopic('What hidden gems do most tourists miss?')">💎 Hidden gems</button>
        <button class="topic-btn" onclick="askTopic('What is the history and culture of this destination?')">🏛️ History & culture</button>
        <button class="topic-btn" onclick="askTopic('How do I get around? What transport options are available?')">🚌 Getting around</button>
        <button class="topic-btn" onclick="askTopic('What safety tips should I be aware of?')">🛡️ Safety tips</button>
        <button class="topic-btn" onclick="askTopic('What should I avoid as a tourist?')">⚠️ Things to avoid</button>
    </div>

    <!-- Chat -->
    <div class="guide-chat">
        <div class="guide-chat-header">
            <h4 id="chatTitle">Select a Destination to Begin</h4>
            <p id="chatSubtitle">Enter your destination in the sidebar to activate your AI guide</p>
        </div>

        <div class="guide-messages" id="guideMessages">
            <div class="no-dest" id="noDestState">
                <div class="no-dest-icon">🗺️</div>
                <h5 style="font-weight:800;color:var(--text);">Where are you going?</h5>
                <p style="color:var(--text-muted);font-size:14px;">Enter a destination in the sidebar and I'll become your personal local expert guide.</p>
            </div>
            <div class="typing" id="guideTyping">
                <div class="typing-bub">
                    <div class="t-dot"></div><div class="t-dot"></div><div class="t-dot"></div>
                </div>
            </div>
        </div>

        <div class="guide-input-area" id="guideInputArea" style="display:none;">
            <textarea class="guide-input" id="guideInput" placeholder="Ask anything about this destination…" rows="1"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();askGuide()}"
                oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px'"></textarea>
            <button class="guide-send" onclick="askGuide()">
                <i class="fa fa-paper-plane"></i>
            </button>
        </div>
    </div>
</div>

<?php include('backend/footer.php'); ?>

<script>
let currentDest = '<?= addslashes($preloadDest) ?>';

// Auto-activate if destination pre-loaded
if (currentDest) {
    window.addEventListener('load', () => {
        activateGuide(currentDest);
        addGuideMessage(`Hello! I'm your AI guide for <strong>${esc(currentDest)}</strong>. 🌟 I know everything about this destination — from hidden gems to local customs. What would you like to know?`);
    });
}

function setDestination() {
    const dest = document.getElementById('destInput').value.trim();
    if (!dest) { tmsToast('Please enter a destination.'); return; }
    currentDest = dest;
    activateGuide(dest);
    addGuideMessage(`Great choice! 🌍 I'm now your expert guide for <strong>${esc(dest)}</strong>. I have deep local knowledge about this destination — attractions, food, culture, safety, hidden gems and more. What would you like to know?`);
}

function activateGuide(dest) {
    document.getElementById('noDestState').style.display = 'none';
    document.getElementById('guideInputArea').style.display = 'flex';
    document.getElementById('chatTitle').textContent    = `AI Guide: ${dest}`;
    document.getElementById('chatSubtitle').textContent = 'Ask me anything about ' + dest;
    document.getElementById('guideInput').focus();
}

async function askGuide() {
    if (!currentDest) { tmsToast('Please set a destination first.'); return; }
    const input = document.getElementById('guideInput');
    const q     = input.value.trim();
    if (!q) return;

    addUserMessage(q);
    input.value = '';
    input.style.height = 'auto';
    showTyping(true);

    const fd = new FormData();
    fd.append('action', 'virtual_guide');
    fd.append('csrf_token', <?php echo json_encode(tms_csrf_token()); ?>);
    fd.append('destination', currentDest);
    fd.append('question', q);

    try {
        const res  = await fetch('api/ai.php', { method: 'POST', body: fd });
        const json = await res.json();
        showTyping(false);
        addGuideMessage(json.success ? formatText(json.data.answer) : (json.error || 'Sorry, I had trouble finding that information. Please try again.'));
    } catch(e) {
        showTyping(false);
        addGuideMessage('Network error. Please check your connection and try again.');
    }
}

function askTopic(q) {
    if (!currentDest) { document.getElementById('destInput').focus(); tmsToast('Please enter a destination first.'); return; }
    document.getElementById('guideInput').value = q;
    askGuide();
}

function addGuideMessage(html) {
    const msgs  = document.getElementById('guideMessages');
    const typing = document.getElementById('guideTyping');
    const now   = new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    typing.insertAdjacentHTML('beforebegin', `
    <div class="gmsg gmsg-guide">
        <div class="gmsg-name">🧭 AI Guide</div>
        <div class="gmsg-bubble">${html}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:3px;">${now}</div>
    </div>`);
    msgs.scrollTop = msgs.scrollHeight;
}

function addUserMessage(text) {
    const msgs  = document.getElementById('guideMessages');
    const typing = document.getElementById('guideTyping');
    const now   = new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    typing.insertAdjacentHTML('beforebegin', `
    <div class="gmsg gmsg-user">
        <div class="gmsg-bubble">${esc(text)}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:3px;text-align:right;">${now}</div>
    </div>`);
    msgs.scrollTop = msgs.scrollHeight;
}

function showTyping(show) {
    document.getElementById('guideTyping').style.display = show ? 'flex' : 'none';
    document.getElementById('guideMessages').scrollTop = 99999;
}

function formatText(t) {
    return esc(t).replace(/\n/g,'<br>').replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>
</body>
</html>
