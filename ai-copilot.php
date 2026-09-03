<?php
/**
 * ai-copilot.php — Trip Copilot: 24/7 AI Travel Companion
 */
session_start();
error_reporting(E_ALL); ini_set('display_errors', '0'); ini_set('log_errors', '1');
include('includes/config.php');
tms_require_user();

require_once 'includes/ai/TripCopilot.php';
$copilot = new TripCopilot($dbh);
$activeBooking = $copilot->getActiveCopilotBooking($_SESSION['login']);

$allBookings = [];
try {
    $stmt = $dbh->prepare(
        "SELECT b.BookingId, b.BookingDate, p.PackageName, p.PackageLocation
         FROM tblbooking b JOIN tbltourpackages p ON p.PackageId = b.PackageId
         WHERE b.UserEmail = ? AND b.status = 1 ORDER BY b.BookingDate DESC LIMIT 20"
    );
    $stmt->execute([$_SESSION['login']]);
    $allBookings = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {}

$selectedBookingId = (int)($_GET['booking_id'] ?? ($activeBooking->BookingId ?? 0));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#0B2B3C">
<link rel="apple-touch-icon" href="images/icon-192.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Trip Copilot | TMS</title>
<link href="css/bootstrap.min2.css" rel="stylesheet">
<link href="css/font-awesome.min2.css" rel="stylesheet">
<link href="css/modern-theme.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Manrope:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
body { background: #f0f4f8; margin: 0; }
.copilot-layout { display: grid; grid-template-columns: 300px 1fr; height: calc(100vh - 120px); }
.copilot-sidebar { background: #1a1a2e; display: flex; flex-direction: column; overflow: hidden; }
.sidebar-header { padding: 20px; border-bottom: 1px solid rgba(255,255,255,.08); }
.brand-row { display: flex; align-items: center; gap: 10px; color: #fff; margin-bottom: 12px; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg,#667eea,#764ba2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
.brand-text strong { display: block; font-size: 15px; font-weight: 800; }
.brand-text span { font-size: 11px; color: rgba(255,255,255,.4); }
.online-pill { display: inline-flex; align-items: center; gap: 6px; background: rgba(72,187,120,.12); border: 1px solid rgba(72,187,120,.25); color: #68d391; border-radius: 20px; padding: 3px 10px; font-size: 11px; }
.online-dot { width: 6px; height: 6px; background: #48bb78; border-radius: 50%; animation: pulse 2s infinite; }
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.trip-selector { padding: 12px 20px; border-bottom: 1px solid rgba(255,255,255,.06); }
.trip-selector label { font-size: 10px; color: rgba(255,255,255,.35); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; display: block; }
.trip-select { width:100%; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); color: #fff; border-radius: 8px; padding: 7px 10px; font-size: 12px; outline: none; }
.trip-select option { background: #1a1a2e; }
.trip-info-card { margin: 12px 20px; background: rgba(102,126,234,.15); border: 1px solid rgba(102,126,234,.25); border-radius: 10px; padding: 12px; }
.trip-info-title { font-size: 13px; font-weight: 700; color: #fff; margin-bottom: 6px; }
.trip-info-meta { font-size: 11px; color: rgba(255,255,255,.5); }
.quick-actions { padding: 12px 20px; border-bottom: 1px solid rgba(255,255,255,.06); }
.qa-label { font-size: 10px; color: rgba(255,255,255,.35); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
.qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
.qa-btn { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); color: rgba(255,255,255,.7); border-radius: 7px; padding: 7px 8px; font-size: 11px; cursor: pointer; text-align: center; transition: .2s; }
.qa-btn:hover { background: rgba(102,126,234,.2); color: #fff; }
.sidebar-links { padding: 12px 20px; margin-top: auto; border-top: 1px solid rgba(255,255,255,.06); }
.s-link { display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,.4); font-size: 12px; text-decoration: none; padding: 5px 6px; border-radius: 6px; }
.s-link:hover { background: rgba(255,255,255,.04); color: rgba(255,255,255,.7); }
.s-link i { color: #667eea; width: 12px; }
.chat-main { display: flex; flex-direction: column; background: #f8fafc; }
.chat-topbar { background: #fff; padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
.chat-topbar-title { font-size: 15px; font-weight: 800; color: #1a202c; }
.chat-topbar-sub { font-size: 12px; color: #718096; }
.topbar-actions { display: flex; gap: 8px; }
.topbar-btn { background: #f0f4f8; border: none; border-radius: 7px; padding: 6px 12px; font-size: 12px; color: #4a5568; cursor: pointer; display: flex; align-items: center; gap: 5px; text-decoration: none; }
.topbar-btn:hover { background: #e2e8f0; }
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 14px; }
.msg { max-width: 78%; display: flex; flex-direction: column; }
.msg.user { align-self: flex-end; align-items: flex-end; }
.msg.assistant { align-self: flex-start; align-items: flex-start; }
.msg-meta { font-size: 10px; color: #a0aec0; margin-bottom: 3px; display: flex; align-items: center; gap: 5px; }
.msg-avatar { width: 26px; height: 26px; border-radius: 7px; background: linear-gradient(135deg,#667eea,#764ba2); display: flex; align-items: center; justify-content: center; font-size: 12px; color: #fff; }
.msg-bubble { padding: 11px 14px; border-radius: 14px; font-size: 13px; line-height: 1.6; }
.msg.assistant .msg-bubble { background: #fff; color: #1a202c; border-radius: 0 14px 14px 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.msg.user .msg-bubble { background: linear-gradient(135deg,#667eea,#764ba2); color: #fff; border-radius: 14px 0 14px 14px; }
.suggestions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.sug-chip { background: #ebf8ff; color: #2c5282; border: 1px solid #bee3f8; border-radius: 16px; padding: 4px 10px; font-size: 11px; cursor: pointer; transition: .2s; }
.sug-chip:hover { background: #bee3f8; }
.typing-msg { align-self: flex-start; }
.typing-bubble { background: #fff; border-radius: 0 14px 14px 14px; padding: 12px 16px; box-shadow: 0 2px 8px rgba(0,0,0,.06); display: flex; gap: 4px; }
.typing-dot { width: 7px; height: 7px; background: #a0aec0; border-radius: 50%; animation: typeBounce 1.2s infinite; }
.typing-dot:nth-child(2){animation-delay:.2s}
.typing-dot:nth-child(3){animation-delay:.4s}
@keyframes typeBounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-5px)}}
.chat-input-bar { background: #fff; padding: 12px 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 8px; align-items: flex-end; }
.chat-input-wrap { flex: 1; }
.chat-textarea { width: 100%; border: 2px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; font-size: 13px; resize: none; outline: none; transition: .2s; max-height: 100px; font-family: inherit; }
.chat-textarea:focus { border-color: #667eea; }
.send-btn { width: 42px; height: 42px; background: linear-gradient(135deg,#667eea,#764ba2); border: none; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mic-btn { width: 42px; height: 42px; background: #f1f5f9; color: #667eea; border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: .2s; }
.mic-btn.listening { background: linear-gradient(135deg,#ef4444,#dc2626); color: #fff; border-color: transparent; animation: micPulse 1.2s infinite; }
.mic-btn:disabled { opacity: .4; cursor: not-allowed; }
@keyframes micPulse { 0%,100%{ box-shadow: 0 0 0 0 rgba(239,68,68,.4); } 50%{ box-shadow: 0 0 0 8px rgba(239,68,68,0); } }
.send-btn i { color: #fff; font-size: 14px; }
.send-btn:disabled { opacity: .5; }
.no-booking { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px; }
.no-booking h3 { font-size: 1.2rem; font-weight: 800; color: #1a202c; margin-bottom: 10px; }
.no-booking p { color: #718096; max-width: 380px; margin-bottom: 20px; font-size: 14px; }
@media(max-width:768px){.copilot-sidebar{display:none}.copilot-layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include('includes/header.php'); ?>

<div class="copilot-layout">
  <!-- Sidebar -->
  <div class="copilot-sidebar">
    <div class="sidebar-header">
      <div class="brand-row">
        <div class="brand-icon">✈️</div>
        <div class="brand-text"><strong>Trip Copilot</strong><span>AI Travel Companion</span></div>
      </div>
      <div class="online-pill"><span class="online-dot"></span> Active 24/7</div>
    </div>

    <div class="trip-selector">
      <label>Select Trip</label>
      <select class="trip-select" onchange="if(this.value) window.location='ai-copilot.php?booking_id='+this.value">
        <option value="">— Select a trip —</option>
        <?php foreach ($allBookings as $b): ?>
        <option value="<?= $b->BookingId ?>" <?= $selectedBookingId==$b->BookingId?'selected':'' ?>>
          <?= htmlspecialchars(substr($b->PackageName,0,28)) ?> (<?= $b->BookingDate ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($selectedBookingId): ?>
    <div class="trip-info-card">
      <div class="trip-info-title" id="tripName">Trip #<?= $selectedBookingId ?></div>
      <div class="trip-info-meta">✅ Confirmed Booking</div>
    </div>
    <?php endif; ?>

    <div class="quick-actions">
      <div class="qa-label">Quick Actions</div>
      <div class="qa-grid">
        <button class="qa-btn" onclick="sendQuick('What should I do today?')">📅 Today</button>
        <button class="qa-btn" onclick="sendQuick('Best restaurants nearby?')">🍽️ Food</button>
        <button class="qa-btn" onclick="sendQuick('What is the weather?')">🌤️ Weather</button>
        <button class="qa-btn" onclick="sendQuick('I need emergency help!')">🆘 Emergency</button>
        <button class="qa-btn" onclick="sendQuick('Hidden gem spots here?')">💎 Gems</button>
        <button class="qa-btn" onclick="sendQuick('Local culture and customs tips')">🏛️ Culture</button>
        <button class="qa-btn" onclick="sendQuick('How to get around locally?')">🚌 Transport</button>
        <button class="qa-btn" onclick="sendQuick('Photography spots nearby?')">📸 Photos</button>
      </div>
    </div>

    <div class="sidebar-links">
      <a href="ai-planner.php" class="s-link"><i class="fa fa-calendar"></i> AI Trip Planner</a>
      <a href="ai-recommend.php" class="s-link"><i class="fa fa-magic"></i> Find New Trips</a>
      <a href="packing-assistant.php" class="s-link"><i class="fa fa-suitcase"></i> Packing Assistant</a>
      <a href="image-analyzer.php" class="s-link"><i class="fa fa-camera"></i> Find Trips by Photo</a>
      <a href="tour-history.php" class="s-link"><i class="fa fa-history"></i> My Bookings</a>
    </div>
  </div>

  <!-- Chat area -->
  <div class="chat-main">
    <?php if ($selectedBookingId): ?>
    <div class="chat-topbar">
      <div>
        <div class="chat-topbar-title">Trip Copilot</div>
        <div class="chat-topbar-sub">AI-powered 24/7 travel companion for Booking #<?= $selectedBookingId ?></div>
      </div>
      <div class="topbar-actions">
        <button class="topbar-btn" id="voiceToggleBtn" onclick="toggleVoiceOutput()" style="display:none;"><i class="fa fa-volume-off"></i> Voice Replies</button>
        <button class="topbar-btn" onclick="clearChat()"><i class="fa fa-trash-o"></i> Clear</button>
        <a href="ai-planner.php" class="topbar-btn"><i class="fa fa-calendar"></i> Planner</a>
      </div>
    </div>

    <div class="chat-messages" id="chatMessages">
      <div class="msg assistant">
        <div class="msg-meta"><div class="msg-avatar">🤖</div><span>Copilot</span></div>
        <div class="msg-bubble">
          👋 <strong>Welcome to your Trip Copilot!</strong><br><br>
          I'm your 24/7 AI travel companion for Booking #<?= $selectedBookingId ?>.
          I know your trip details and can help with recommendations, emergency info,
          schedule adjustments, local tips, and anything else you need.<br><br>
          How can I help you today?
        </div>
        <div class="suggestions">
          <span class="sug-chip" onclick="sendQuick('What should I visit first?')">What to visit first?</span>
          <span class="sug-chip" onclick="sendQuick('Tell me about my destination')">About my destination</span>
          <span class="sug-chip" onclick="sendQuick('Weather forecast for my trip')">Weather forecast</span>
        </div>
      </div>
    </div>

    <div class="chat-input-bar">
      <div class="chat-input-wrap">
        <textarea class="chat-textarea" id="chatInput" rows="1"
          placeholder="Ask anything about your trip…"
          onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage()}"
          oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px'"></textarea>
      </div>
      <button class="mic-btn" id="micBtn" onclick="toggleListening()" title="Speak your question" style="display:none;">
        <i class="fa fa-microphone"></i>
      </button>
      <button class="send-btn" id="sendBtn" onclick="sendMessage()">
        <i class="fa fa-paper-plane"></i>
      </button>
    </div>

    <?php else: ?>
    <div class="no-booking">
      <div style="font-size:60px;margin-bottom:20px;">✈️</div>
      <h3>No Active Trip Selected</h3>
      <p>The Trip Copilot activates with a confirmed booking. Select a booking from the sidebar, or explore and book a package below.</p>
      <a href="package-list.php" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:11px 24px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;">
        Browse Packages
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include('includes/footer.php'); ?>

<script>
const BOOKING_ID = <?= $selectedBookingId ?: 'null' ?>;
let busy = false;

if (BOOKING_ID) { loadHistory(); }

async function loadHistory() {
  try {
    const res  = await fetch('api/ai.php?action=copilot_history&booking_id=' + BOOKING_ID);
    const json = await res.json();
    if (json.success && json.data.history?.length) {
      json.data.history.forEach(m => addMsg(m.role, m.message, false));
    }
  } catch(e) {}
}

async function sendMessage() {
  const input = document.getElementById('chatInput');
  const msg   = input.value.trim();
  if (!msg || busy || !BOOKING_ID) return;

  input.value = ''; input.style.height = 'auto';
  addMsg('user', msg);
  showTyping(); busy = true;
  document.getElementById('sendBtn').disabled = true;

  const fd = new FormData();
  fd.append('action','copilot_chat');
  fd.append('booking_id', BOOKING_ID);
  fd.append('message', msg);

  try {
    const res  = await fetch('api/ai.php', {method:'POST', body:fd});
    const json = await res.json();
    hideTyping(); busy = false;
    document.getElementById('sendBtn').disabled = false;
    if (json.success) {
      addMsg('assistant', json.data.reply, true, json.data.suggestions || []);
    } else {
      addMsg('assistant', json.error || 'Sorry, something went wrong. Please try again.');
    }
  } catch(e) {
    hideTyping(); busy = false;
    document.getElementById('sendBtn').disabled = false;
    addMsg('assistant', 'Network error. Please check your connection.');
  }
}

function sendQuick(msg) {
  document.getElementById('chatInput').value = msg;
  sendMessage();
}

// ═══════════════════════════════════════════════════════════════════════════
// Voice-Enabled Assistant (Phase 2k)
//
// Uses the browser-native Web Speech API — SpeechRecognition for voice input
// (mic → text, filled into the same chatInput/sendMessage flow already used
// for typed messages, so there's no separate code path to keep in sync) and
// SpeechSynthesis for voice output (reading the assistant's replies aloud).
// Both are 100% client-side: no external speech API, no server changes, no
// new cost — this is genuinely free "architecture" the browser already ships.
// Gracefully hides the mic/voice controls entirely on browsers that don't
// support these APIs (mainly desktop Firefox, some older browsers) rather
// than showing a broken button.
// ═══════════════════════════════════════════════════════════════════════════

let recognition = null;
let isListening = false;
let voiceOutputEnabled = false;

const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

if (SpeechRecognitionAPI) {
  document.getElementById('micBtn').style.display = 'flex';

  recognition = new SpeechRecognitionAPI();
  recognition.continuous = false;
  recognition.interimResults = false;
  recognition.lang = 'en-US';

  recognition.onstart = function() {
    isListening = true;
    document.getElementById('micBtn').classList.add('listening');
  };
  recognition.onend = function() {
    isListening = false;
    document.getElementById('micBtn').classList.remove('listening');
  };
  recognition.onerror = function() {
    isListening = false;
    document.getElementById('micBtn').classList.remove('listening');
  };
  recognition.onresult = function(event) {
    const transcript = event.results[0][0].transcript;
    document.getElementById('chatInput').value = transcript;
    sendMessage();
  };
}

function toggleListening() {
  if (!recognition || busy) return;
  if (isListening) {
    recognition.stop();
  } else {
    try { recognition.start(); } catch (e) { /* already running — ignore */ }
  }
}

if ('speechSynthesis' in window) {
  const voiceBtn = document.getElementById('voiceToggleBtn');
  voiceBtn.style.display = 'inline-flex';
  voiceOutputEnabled = localStorage.getItem('tms_copilot_voice_replies') === '1';
  updateVoiceToggleUI();

  voiceBtn.onclick = function() { toggleVoiceOutput(); };
}

function toggleVoiceOutput() {
  voiceOutputEnabled = !voiceOutputEnabled;
  localStorage.setItem('tms_copilot_voice_replies', voiceOutputEnabled ? '1' : '0');
  if (!voiceOutputEnabled) window.speechSynthesis.cancel();
  updateVoiceToggleUI();
}

function updateVoiceToggleUI() {
  const btn = document.getElementById('voiceToggleBtn');
  if (!btn) return;
  btn.innerHTML = voiceOutputEnabled
    ? '<i class="fa fa-volume-up"></i> Voice Replies: On'
    : '<i class="fa fa-volume-off"></i> Voice Replies';
  btn.style.color = voiceOutputEnabled ? '#667eea' : '';
}

// Task 6 fix (shared logic with includes/chatbot.php's widget): pick the
// best available female voice by name pattern, since the Web Speech API
// exposes no real gender field. Falls back to the browser default voice if
// no confident female match exists.
let copilotSelectedVoice = null;
const COPILOT_FEMALE_VOICE_NAMES = [
  'female', 'zira', 'hazel', 'susan', 'samantha', 'karen', 'moira', 'tessa',
  'victoria', 'allison', 'ava', 'serena', 'fiona', 'kate', 'catherine',
  'aria', 'jenny', 'michelle', 'joanna', 'salli', 'kimberly', 'ivy', 'kendra',
  'amy', 'emma', 'olivia', 'sonia', 'libby', 'natasha', 'shelley', 'nicky',
  'google us english', 'google uk english female'
];
function pickCopilotVoice() {
  if (!('speechSynthesis' in window)) return null;
  const voices = window.speechSynthesis.getVoices() || [];
  if (!voices.length) return null;
  const scored = voices.map(v => {
    const name = (v.name || '').toLowerCase();
    const isFemale = COPILOT_FEMALE_VOICE_NAMES.some(n => name.indexOf(n) !== -1);
    const isEnglish = (v.lang || '').toLowerCase().indexOf('en') === 0;
    const isHighQuality = /natural|online|neural|premium/.test(name);
    return { voice: v, score: (isFemale ? 100 : 0) + (isEnglish ? 10 : 0) + (isHighQuality ? 5 : 0) };
  });
  scored.sort((a, b) => b.score - a.score);
  return (scored[0] && scored[0].score >= 100) ? scored[0].voice : null;
}
if ('speechSynthesis' in window) {
  copilotSelectedVoice = pickCopilotVoice();
  window.speechSynthesis.onvoiceschanged = () => { copilotSelectedVoice = pickCopilotVoice(); };
}

function speakReply(text) {
  if (!voiceOutputEnabled || !('speechSynthesis' in window)) return;
  // Strip markdown-ish formatting the chat renderer uses (bold/links/bullets)
  // so the spoken version doesn't read out asterisks and pipe characters.
  const clean = text.replace(/\*\*(.*?)\*\*/g, '$1').replace(/[*_#]/g, '').replace(/\n+/g, '. ');
  window.speechSynthesis.cancel(); // don't queue/overlap replies
  const utterance = new SpeechSynthesisUtterance(clean);
  if (copilotSelectedVoice) {
    utterance.voice = copilotSelectedVoice;
    utterance.lang = copilotSelectedVoice.lang;
  }
  utterance.rate = 1.0;
  utterance.pitch = 1.0;
  window.speechSynthesis.speak(utterance);
}

function addMsg(role, text, animate=false, suggestions=[]) {
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;
  const time = new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
  const sugHtml = suggestions.length
    ? '<div class="suggestions">'+suggestions.map(s=>`<span class="sug-chip" onclick="sendQuick('${s.replace(/'/g,"\\'")}')">${esc(s)}</span>`).join('')+'</div>'
    : '';
  const html = `<div class="msg ${role}" ${animate?'style="opacity:0;transform:translateY(8px);transition:.3s"':''}>
    ${role==='assistant'?`<div class="msg-meta"><div class="msg-avatar">🤖</div><span>Copilot</span><span>${time}</span></div>`:`<div class="msg-meta"><span>${time}</span></div>`}
    <div class="msg-bubble">${esc(text).replace(/\n/g,'<br>')}</div>
    ${sugHtml}
  </div>`;
  wrap.insertAdjacentHTML('beforeend', html);
  if (animate) { const el=wrap.lastElementChild; requestAnimationFrame(()=>{el.style.opacity='1';el.style.transform='translateY(0)'}); }
  wrap.scrollTop = wrap.scrollHeight;
  // Only speak live replies (animate=true), never the chat-history replay
  // that runs on page load — nobody wants their whole conversation history
  // read aloud the moment the page opens.
  if (animate && role === 'assistant') speakReply(text);
}

function showTyping() {
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;
  wrap.insertAdjacentHTML('beforeend',`<div class="msg typing-msg" id="typingDots"><div class="msg-meta"><div class="msg-avatar">🤖</div><span>typing…</span></div><div class="typing-bubble"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div></div>`);
  wrap.scrollTop = wrap.scrollHeight;
}

function hideTyping() { document.getElementById('typingDots')?.remove(); }

function clearChat() {
  if (confirm('Clear this chat history?')) document.getElementById('chatMessages').innerHTML = '';
}

function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
</script>
</body>
</html>
