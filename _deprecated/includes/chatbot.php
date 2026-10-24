<!-- TMS AI Chatbot Widget -->
<!-- Include this file on any page: include('includes/chatbot.php'); -->
<style>
/* ═══════════════ AI ROBOT ASSISTANT (replaces the old chat-bubble icon) ═══════════════ */
@keyframes tmsRobotEntrance {
    0% { transform: scale(0) rotate(-20deg); opacity: 0; }
    60% { transform: scale(1.15) rotate(8deg); opacity: 1; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
}
@keyframes tmsRobotFloat {
    0%, 100% { transform: translateY(0) rotate(-2deg); }
    50% { transform: translateY(-8px) rotate(2deg); }
}
@keyframes tmsRobotBreathe {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.04); }
}
@keyframes tmsRobotGlow {
    0%, 100% { box-shadow: 0 0 18px 4px rgba(102,126,234,0.45), 0 8px 25px rgba(0,0,0,0.25); }
    50% { box-shadow: 0 0 30px 10px rgba(118,75,162,0.55), 0 8px 25px rgba(0,0,0,0.25); }
}
@keyframes tmsEyeBlink {
    0%, 92%, 100% { transform: scaleY(1); }
    95% { transform: scaleY(0.1); }
}
@keyframes tmsAntennaPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

#tmsChatToggle {
    position: fixed; bottom: 25px; right: 25px;
    width: 68px; height: 68px;
    background: transparent; border: none; cursor: pointer;
    z-index: 9999; padding: 0;
    animation: tmsRobotEntrance 0.7s cubic-bezier(.34,1.56,.64,1) both,
               tmsRobotFloat 3.2s ease-in-out 0.7s infinite;
    transition: transform 0.25s ease;
}
#tmsChatToggle:hover { transform: scale(1.12) translateY(-4px); }
#tmsChatToggle:hover .tms-robot-body { animation-play-state: paused; }

.tms-robot-glow {
    position: absolute; inset: -4px; border-radius: 50%;
    animation: tmsRobotGlow 2.4s ease-in-out infinite;
    pointer-events: none;
}
.tms-robot-body {
    position: relative; width: 100%; height: 100%;
    animation: tmsRobotBreathe 2.6s ease-in-out infinite;
}
.tms-robot-body svg { width: 100%; height: 100%; display: block; overflow: visible; }
.tms-robot-eye { animation: tmsEyeBlink 4.5s ease-in-out infinite; transform-origin: center; }
.tms-robot-antenna-dot { animation: tmsAntennaPulse 1.6s ease-in-out infinite; transform-origin: center; }

#tmsChatToggle .notif { position:absolute; top:-2px; right:-2px; width:15px; height:15px; background:#e74c3c; border-radius:50%; border:2px solid #fff; z-index:2; }

@media (max-width: 480px) {
    #tmsChatToggle { width: 56px; height: 56px; bottom: 16px; right: 16px; }
    #tmsChatPanel { width: calc(100vw - 24px); right: 12px; left: 12px; bottom: 84px; height: 70vh; max-height: 520px; }
}
</style>

<!-- Chatbot Button — animated AI robot assistant -->
<button id="tmsChatToggle" onclick="toggleChat()" title="Chat with TMS AI Assistant" aria-label="Open AI travel assistant">
    <div class="tms-robot-glow" style="background: radial-gradient(circle, rgba(102,126,234,0.35), transparent 70%);"></div>
    <div class="tms-robot-body">
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="tmsRobotHeadGrad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#8b9dff"/>
                    <stop offset="100%" stop-color="#764ba2"/>
                </linearGradient>
                <linearGradient id="tmsRobotBodyGrad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#667eea"/>
                    <stop offset="100%" stop-color="#5a3f8f"/>
                </linearGradient>
            </defs>
            <!-- antenna -->
            <line x1="50" y1="18" x2="50" y2="8" stroke="#c7d0ff" stroke-width="2.5" stroke-linecap="round"/>
            <circle class="tms-robot-antenna-dot" cx="50" cy="6" r="4" fill="#9ffcd8"/>
            <!-- head -->
            <rect x="26" y="18" width="48" height="38" rx="16" fill="url(#tmsRobotHeadGrad)"/>
            <!-- visor -->
            <rect x="33" y="30" width="34" height="16" rx="8" fill="#111a2e"/>
            <!-- eyes -->
            <ellipse class="tms-robot-eye" cx="43" cy="38" rx="4" ry="5" fill="#7ef7ff"/>
            <ellipse class="tms-robot-eye" cx="57" cy="38" rx="4" ry="5" fill="#7ef7ff"/>
            <!-- ears -->
            <rect x="20" y="30" width="6" height="14" rx="3" fill="#8b9dff"/>
            <rect x="74" y="30" width="6" height="14" rx="3" fill="#8b9dff"/>
            <!-- body -->
            <rect x="30" y="58" width="40" height="30" rx="12" fill="url(#tmsRobotBodyGrad)"/>
            <circle cx="50" cy="72" r="7" fill="#111a2e"/>
            <circle cx="50" cy="72" r="3.4" fill="#9ffcd8"/>
            <rect x="36" y="82" width="10" height="6" rx="3" fill="#5a3f8f"/>
            <rect x="54" y="82" width="10" height="6" rx="3" fill="#5a3f8f"/>
        </svg>
    </div>
    <span class="notif"></span>
</button>

<style>
#tmsChatPanel { position:fixed; bottom:100px; right:25px; width:360px; height:500px; background:#fff; border-radius:16px; box-shadow:0 10px 40px rgba(0,0,0,0.2); z-index:9998; display:none; flex-direction:column; overflow:hidden; }
#tmsChatPanel.open { display:flex; }

.chat-header { background:linear-gradient(135deg,#667eea,#764ba2); padding:15px 18px; color:#fff; display:flex; align-items:center; gap:12px; }
.chat-avatar { width:40px; height:40px; background:rgba(255,255,255,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.chat-header-info h5 { margin:0; font-size:15px; font-weight:700; }
.chat-header-info p { margin:0; font-size:12px; opacity:0.85; }
.chat-close { margin-left:auto; background:none; border:none; color:#fff; font-size:18px; cursor:pointer; padding:5px; opacity:0.8; }
.chat-close:hover { opacity:1; }
.chat-voice-toggle { background:none; border:none; color:#fff; font-size:15px; cursor:pointer; padding:5px; opacity:0.75; margin-left:auto; }
.chat-voice-toggle:hover { opacity:1; }
.chat-voice-toggle.on { opacity:1; color:#9ffcd8; }
/* ACCESSIBILITY FIX (Round 69, WCAG 2.5.8 target size): zero top/bottom
   padding left this button's clickable height at roughly 19px (vs. the
   sibling .chat-close/.chat-voice-toggle buttons above, which use
   symmetric padding:5px and already clear 24px on both axes). min-height
   plus flex-centering guarantee a full 24px-tall box without changing the
   icon's visible size; width was already passing so it's untouched. */
.chat-mic { background:none; border:none; color:#764ba2; font-size:16px; cursor:pointer; padding:0 8px; min-height:24px; display:inline-flex; align-items:center; opacity:0.7; transition:opacity .15s, color .15s; }
.chat-mic:hover { opacity:1; }
.chat-mic.listening { color:#e74c3c; opacity:1; animation:tmsMicPulse 1s ease-in-out infinite; }
@keyframes tmsMicPulse { 0%,100%{ transform:scale(1); } 50%{ transform:scale(1.18); } }

.chat-messages { flex:1; overflow-y:auto; padding:15px; background:#f8f9fa; display:flex; flex-direction:column; gap:12px; }
.msg { max-width:85%; }
.msg-bot { align-self:flex-start; }
.msg-user { align-self:flex-end; }
.msg-bubble { padding:10px 14px; border-radius:12px; font-size:13px; line-height:1.5; }
.msg-bot .msg-bubble { background:#fff; color:#333; box-shadow:0 1px 5px rgba(0,0,0,0.08); border-radius:0 12px 12px 12px; }
.msg-user .msg-bubble { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; border-radius:12px 0 12px 12px; }
.msg-time { font-size:10px; color:#aaa; margin-top:3px; }
.msg-bot .msg-time { text-align:left; }
.msg-user .msg-time { text-align:right; }
.bot-name { font-size:11px; color:#667eea; font-weight:700; margin-bottom:3px; }

.quick-replies { display:flex; flex-wrap:wrap; gap:6px; padding:5px 0; }
.qr-btn { padding:6px 12px; border-radius:20px; font-size:12px; border:2px solid #667eea; color:#667eea; background:#fff; cursor:pointer; transition:0.2s; white-space:nowrap; }
.qr-btn:hover { background:#667eea; color:#fff; }

.chat-input-area { padding:12px 15px; border-top:1px solid #eee; background:#fff; display:flex; gap:8px; align-items:center; }
.chat-input { flex:1; border:2px solid #e0e0e0; border-radius:20px; padding:8px 14px; font-size:13px; outline:none; transition:0.2s; }
.chat-input:focus { border-color:#667eea; }
.chat-send { width:38px; height:38px; background:linear-gradient(135deg,#667eea,#764ba2); border:none; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.chat-send i { color:#fff; font-size:14px; }
.chat-send:hover { opacity:0.9; }

.typing-indicator { display:none; align-self:flex-start; }
.typing-indicator .msg-bubble { background:#fff; box-shadow:0 1px 5px rgba(0,0,0,0.08); border-radius:0 12px 12px 12px; padding:12px 16px; }
.typing-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#aaa; margin:0 2px; animation:typingDot 1.2s infinite; }
.typing-dot:nth-child(2) { animation-delay:0.2s; }
.typing-dot:nth-child(3) { animation-delay:0.4s; }
@keyframes typingDot { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-6px)} }
</style>

<!-- Chat Window -->
<div id="tmsChatPanel">

    <div class="chat-header">
        <div class="chat-avatar">🤖</div>
        <div class="chat-header-info">
            <h5>TMS Travel Assistant</h5>
            <p>🟢 Online — here to help</p>
        </div>
        <button class="chat-voice-toggle" id="chatVoiceToggle" style="display:none;" title="Toggle spoken replies" aria-label="Toggle spoken replies"><i class="fa fa-volume-off"></i></button>
        <button class="chat-close" onclick="toggleChat()"><i class="fa fa-times"></i></button>
    </div>

    <div class="chat-messages" id="chatMessages" role="log" aria-live="polite" aria-atomic="false">
        <!-- Initial message added by JS -->
    </div>

    <div class="chat-input-area">
        <input type="text" class="chat-input" id="chatInput" placeholder="Ask me anything about tours..."
           autocomplete="off" onkeydown="if(event.key==='Enter' && !event.isComposing){event.preventDefault();sendMessage();}">
        <button class="chat-mic" id="chatMicBtn" style="display:none;" onclick="toggleListening()" title="Speak your question" aria-label="Speak your question"><i class="fa fa-microphone"></i></button>
        <button class="chat-send" onclick="sendMessage()"><i class="fa fa-paper-plane"></i></button>
    </div>
</div>

<script>
var chatOpen = false;
// Round 14: `isTyping` was declared here and never read or written again, while
// nothing actually prevented concurrent sends — hammering Enter fired N
// overlapping requests whose replies then arrived out of order, each one
// removing a duplicated-id typing indicator and corrupting the history sent on
// the next turn. `chatBusy` is the real guard, and it is honoured in sendMessage().
var chatBusy = false;
var userName = '<?php echo isset($_SESSION['login']) ? htmlspecialchars($_SESSION["login"], ENT_QUOTES) : ""; ?>';
var CHAT_CSRF = '<?php echo tms_csrf_token(); ?>';
var CHAT_PAGE_CONTEXT = document.title || '';

<?php
// LIVE BUG FIX (customer-facing audit): every visitor currently sees this
// canned knowledge base, not a live model -- GEMINI_API_KEY isn't configured
// yet, so the "packages" and "map" replies below (previously static text)
// are the actual answer given to 100% of chat visitors asking "what
// packages do you have" or "where do you go". They named fictional
// groupings ("Family Packages - Kerala, Bhutan, Sikkim", "Couple/Honeymoon
// - Bali, Paris, Switzerland"...) that don't match this catalogue's real
// PackageType values (Nature, Heritage, City, Beach, Island, Adventure...),
// and claimed coverage of about 9 countries when the real catalogue spans
// 40+. Pulling the real top types/countries here keeps the canned fallback
// honest. json_encode() (not string concatenation) builds the JS string
// literals below, so quotes/newlines/unicode from real data can never
// break the surrounding <script> block.
$tmsChatTopTypes = [];
$tmsChatTopCountries = [];
$tmsChatCatalogTotal = 0;
try {
    $tmsChatCatalogTotal = (int)$dbh->query("SELECT COUNT(*) FROM tbltourpackages")->fetchColumn();
    $tmsChatTopTypes = $dbh->query(
        "SELECT PackageType, COUNT(*) AS n FROM tbltourpackages
         WHERE PackageType IS NOT NULL AND PackageType <> ''
         GROUP BY PackageType HAVING n > 0 ORDER BY n DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN);
    $tmsChatTopCountries = $dbh->query(
        "SELECT Country, COUNT(*) AS n FROM tbltourpackages
         WHERE Country IS NOT NULL AND Country <> ''
         GROUP BY Country ORDER BY n DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('[TMS] chatbot kb catalogue facts failed: ' . $e->getMessage());
}
$tmsChatPackagesReply = $tmsChatTopTypes
    ? ("🌍 We have {$tmsChatCatalogTotal}+ tour packages across categories like "
        . implode(', ', array_slice($tmsChatTopTypes, 0, 4)) . " and more.\n\nVisit our <a href='package-list.php' style='color:#667eea'>Package List</a> to filter by type, price and destination!")
    : "🌍 Browse our full range on the <a href='package-list.php' style='color:#667eea'>Package List</a> page -- filter by type, price and destination!";
$tmsChatMapReply = $tmsChatTopCountries
    ? ("🗺️ Explore our destinations on our <a href='tour-map.php' style='color:#667eea'>Interactive Map</a>!\n\nWe cover {$tmsChatCatalogTotal}+ destinations, including "
        . implode(', ', array_slice($tmsChatTopCountries, 0, 6)) . " and more.\n\nFilter by package type and click any marker for details!")
    : "🗺️ Explore our destinations on our <a href='tour-map.php' style='color:#667eea'>Interactive Map</a>! Filter by package type and click any marker for details!";
?>
// Knowledge base
var kb = [
    { patterns: ['hello','hi','hey','good morning','good evening','greetings'], response: "Hello! 👋 Welcome to TMS Travel Assistant. I'm here to help you plan your dream trip! What can I help you with today?\n\nYou can ask me about:\n• Tour packages\n• Booking process\n• Weather & best travel times\n• Budget planning\n• Safety tips", quick: ['Show Packages','Budget Help','Best Destinations','Book a Tour'] },
    { patterns: ['package','packages','tour','tours','available','what tours'], response: <?php echo json_encode($tmsChatPackagesReply, JSON_UNESCAPED_UNICODE); ?>, quick: ['Show Packages','AI Recommend','Budget Planner'] },
    { patterns: ['book','booking','reserve','how to book'], response: "📋 Booking a tour is easy!\n\n1. Browse <a href='package-list.php' style='color:#667eea'>Tour Packages</a>\n2. Click on a package you like\n3. Select your travel dates\n4. Sign in or create an account\n5. Submit your booking\n\nOur team confirms within 24 hours. You can also <a href='custom-trip.php' style='color:#667eea'>design a custom trip</a>!", quick: ['Browse Packages','Custom Trip','Sign Up','Contact Us'] },
    { patterns: ['price','cost','budget','cheap','expensive','how much'], response: "💰 Our tour packages start from:\n\nPrices vary by destination and season — the live figures are on each package page.\n\nPrices are per person. Use our <a href='budget-planner.php' style='color:#667eea'>Budget Planner</a> to estimate total costs for your group!\n\nWant our AI to find packages within your budget?", quick: ['Budget Planner','AI Recommender','Family Packages','Cheap Packages'] },
    { patterns: ['weather','climate','best time','when to visit','season','temperature'], response: "☀️ Best travel seasons:\n\n🇮🇳 **India/Bhutan/Sikkim** – Oct to May\n🇮🇩 **Bali** – Apr to Sep\n🇦🇪 **Dubai** – Nov to Mar\n🇫🇷 **France/Switzerland** – Apr to Oct\n\nCheck our <a href='weather.php' style='color:#667eea'>Weather Tool</a> for detailed monthly forecasts for any destination!", quick: ['Check Weather','Bali','Dubai','Paris'] },
    { patterns: ['safety','safe','emergency','danger','police','hospital','ambulance'], response: "🛡️ Safety is our priority!\n\nOur <a href='emergency.php' style='color:#667eea'>Emergency Contacts</a> page has:\n\n• Emergency numbers for all destinations\n• Tourist helpline numbers\n• Embassy information\n• Travel safety tips\n\nAlways share your itinerary with someone before traveling!", quick: ['Emergency Contacts','Safety Tips','Travel Insurance','Contact Us'] },
    { patterns: ['map','location','where','destination','country','place'], response: <?php echo json_encode($tmsChatMapReply, JSON_UNESCAPED_UNICODE); ?>, quick: ['View Map'] },
    { patterns: ['review','reviews','rating','feedback','testimonial','experience'], response: "⭐ Read real traveler reviews on our <a href='reviews.php' style='color:#667eea'>Review Page</a>!\n\nYou can:\n• Read reviews by package\n• Rate your own trip (1-5 stars)\n• Upload travel photos\n• See overall ratings\n\nWe have verified reviews from real customers!", quick: ['Read Reviews','Write Review','Top Rated','Best Packages'] },
    { patterns: ['custom','customize','personalise','build my own','special request'], response: "🎨 Yes! We offer fully customized trips!\n\nWith our <a href='custom-trip.php' style='color:#667eea'>Custom Trip Planner</a> you can choose:\n\n• Specific destinations\n• Travel dates & duration\n• Accommodation type\n• Transport preference\n• Activities & meal plans\n• Special requests\n\nSubmit your requirements and we'll create a tailor-made quote!", quick: ['Build Custom Trip','Standard Packages','Budget Planner','Contact Us'] },
    { patterns: ['recommend','suggest','which','best for me','advice','help me choose'], response: "🤖 Try our <a href='ai-recommend.php' style='color:#667eea'>AI Tour Recommender</a>!\n\nIt analyzes your:\n• Budget\n• Travel style (family, couple, group)\n• Climate preference\n• Trip duration\n\nAnd suggests the best matching packages. Give it a try!", quick: ['AI Recommender','Family Tours','Couple Tours','Solo Travel'] },
    { patterns: ['language','translate','bengali','hindi','french','arabic','spanish'], response: "🌐 TMS supports multiple languages!\n\nVisit our <a href='language.php' style='color:#667eea'>Language Settings</a> to switch to:\n\n🇧🇩 Bengali | 🇮🇳 Hindi | 🇫🇷 French\n🇸🇦 Arabic | 🇨🇳 Chinese | 🇪🇸 Spanish | 🇩🇪 German\n\nSelect your preferred language for a better experience!", quick: ['Change Language','Bengali','Hindi','English'] },
    { patterns: ['cancel','cancellation','refund','policy'], response: "📋 Our Cancellation Policy:\n\n• 30+ days before: Full refund\n• 15–30 days: 50% refund\n• 7–15 days: 25% refund\n• Under 7 days: No refund\n\nFor special circumstances, please <a href='page.php?type=contact' style='color:#667eea'>contact us</a> directly. We try to be flexible!", quick: ['Contact Support','My Bookings','Book Now','Custom Trip'] },
    { patterns: ['contact','call','email','phone','support','help','talk to','human'], response: "📞 Need to speak with us?\n\n• 💬 <a href='enquiry.php' style='color:#667eea'>Send us an enquiry</a> and we'll reply by email\n• 🎫 For an existing booking, open a ticket from your account\n• 🕒 We answer enquiries Mon–Sat\n• 💬 You can also <a href='enquiry.php' style='color:#667eea'>send an enquiry</a>\n\nOr use the Issue Tracker in your account for booking-related issues.", quick: ['Send Enquiry','Book Now','Emergency','FAQ'] },
    { patterns: ['visa','passport','document','requirement'], response: "📄 For visa requirements:\n\n• Requirements vary by nationality and destination\n• We recommend checking official embassy websites\n• We can provide invitation/booking letters\n• Allow 2–4 weeks for visa processing\n\nOur team can guide you through the process — just <a href='enquiry.php' style='color:#667eea'>send an enquiry</a>!", quick: ['Send Enquiry','Emergency Contacts','Contact Us','Book Now'] },
    { patterns: ['thank','thanks','thank you','great','awesome','good','helpful'], response: "😊 You're welcome! I'm glad I could help!\n\nIs there anything else you'd like to know about our tours or services? I'm here 24/7!", quick: ['Browse Packages','Plan My Trip','AI Recommender','Goodbye'] },
    { patterns: ['bye','goodbye','exit','see you','ciao','done'], response: "👋 Safe travels and happy adventures!\n\nCome back anytime you need help planning your trip. TMS is always here for you! 🌍✈️", quick: [] },
];

var quickLinks = {
    'Show Packages': 'package-list.php',
    'Budget Planner': 'budget-planner.php',
    'AI Recommender': 'ai-recommend.php',
    'AI Recommend': 'ai-recommend.php',
    'Check Weather': 'weather.php',
    'Emergency Contacts': 'emergency.php',
    'View Map': 'tour-map.php',
    'Read Reviews': 'reviews.php',
    'Write Review': 'reviews.php',
    'Build Custom Trip': 'custom-trip.php',
    'Change Language': 'language.php',
    'Send Enquiry': 'enquiry.php',
    'Contact Us': 'page.php?type=contact',
    'Sign Up': '#',
    'Browse Packages': 'package-list.php',
    'Plan My Trip': 'ai-recommend.php',
};

function toggleChat() {
    chatOpen = !chatOpen;
    document.getElementById('tmsChatPanel').classList.toggle('open', chatOpen);
    if(chatOpen) {
        document.querySelector('#tmsChatToggle .notif').style.display = 'none';
        if(document.getElementById('chatMessages').children.length === 0) initChat();
        document.getElementById('chatInput').focus();
    }
}

function initChat() {
    var greet = userName ? "Hello! 👋 Welcome back to TMS!\n\nHow can I help you plan your next adventure?" : "Hello! 👋 Welcome to TMS Travel Assistant!\n\nI can help you with:\n• Finding the perfect tour package\n• Booking guidance\n• Weather & safety info\n• Budget planning";
    addMessage('bot', greet, ['Show Packages','AI Recommender','Budget Help','Safety Info']);
}

// SECURITY FIX (Round 14): both the visitor's own text and the model's reply
// used to go into innerHTML with no escaping at all. The system prompt
// deliberately instructs the model to answer in raw HTML anchors, so the sink is
// load-bearing and cannot simply be escaped away — instead everything is escaped
// first and then exactly one construct is allowed back in: a relative-path
// anchor to a .php page on this site. Anything else the model emits (an <img
// onerror=...>, a javascript: href, an absolute URL) now renders as visible text
// instead of executing in a page that holds the visitor's session cookie.
function chatEscape(v) {
    var d = document.createElement('div');
    d.textContent = (v === null || v === undefined) ? '' : String(v);
    return d.innerHTML;
}

function chatFormat(text) {
    // LIVE BUG FIX (customer-facing screenshot): chatEscape() uses the
    // textContent -> innerHTML trick, which escapes "<", ">" and "&" but
    // does NOT escape quote characters in text content (only attribute
    // VALUES need that, and this isn't one). So a real model response like
    // <a href="budget-planner.php" style="color:#667eea">Budget Planner</a>
    // came out of chatEscape() as &lt;a href="..." style="..."&gt;...&lt;/a&gt;
    // -- a literal, un-escaped double quote. The allow-list regex below only
    // ever matched &quot; or a literal single quote, never a literal double
    // quote, so this exact (very common) shape silently fell through and
    // rendered as visible raw HTML tags instead of a clickable link.
    return chatEscape(text)
        .replace(/\n/g, '<br>')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(
            /&lt;a\s+href=(?:&quot;|"|')([a-z0-9\-]{1,40}\.php(?:\?[a-z_]{1,20}=[a-z0-9\-]{1,40})?)(?:&quot;|"|')[^&]*&gt;([^&<]{1,120})&lt;\/a&gt;/gi,
            '<a href="$1" style="color:#667eea">$2</a>'
        );
}

function addMessage(type, text, quickReplies) {
    var msgs = document.getElementById('chatMessages');
    var div = document.createElement('div');
    div.className = 'msg msg-' + type;

    var time = new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});

    var html = (type === 'bot' ? '<div class="bot-name">🤖 TMS Assistant</div>' : '') +
        '<div class="msg-bubble">' + chatFormat(text) + '</div>' +
        '<div class="msg-time">' + time + '</div>';

    if(quickReplies && quickReplies.length > 0) {
        html += '<div class="quick-replies">';
        quickReplies.forEach(function(qr) {
            var label = chatEscape(qr);
            var arg = encodeURIComponent(qr);
            if(quickLinks[qr]) {
                html += '<a href="' + chatEscape(quickLinks[qr]) + '" class="qr-btn" data-qr="' + arg + '">' + label + '</a>';
            } else {
                html += '<button type="button" class="qr-btn" data-qr="' + arg + '" data-qr-send="1">' + label + '</button>';
            }
        });
        html += '</div>';
    }

    div.innerHTML = html;
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
}

// Quick replies are delegated rather than carrying inline onclick handlers built
// by string concatenation (a label containing an apostrophe used to break them).
document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-qr]') : null;
    if (!btn) return;
    var label = decodeURIComponent(btn.getAttribute('data-qr'));
    if (btn.hasAttribute('data-qr-send')) {
        e.preventDefault();
        handleQuickReply(label);
    } else {
        tmsTrackQuickReply(label);
    }
});

function showTyping() {
    var msgs = document.getElementById('chatMessages');
    var div = document.createElement('div');
    div.className = 'typing-indicator msg msg-bot';
    div.id = 'typingIndicator';
    div.innerHTML = '<div class="msg-bubble"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>';
    div.style.display = 'flex';
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
}

function hideTyping() {
    var el = document.getElementById('typingIndicator');
    if(el) el.remove();
}

function getResponse(input) {
    var lower = input.toLowerCase();
    for(var i = 0; i < kb.length; i++) {
        var entry = kb[i];
        for(var j = 0; j < entry.patterns.length; j++) {
            if(lower.includes(entry.patterns[j])) {
                return { text: entry.response, quick: entry.quick || [] };
            }
        }
    }
    return {
        text: "I'm not sure about that, but I'm learning every day! 😊\n\nYou might find what you need in:\n• <a href='package-list.php' style='color:#667eea'>Tour Packages</a>\n• <a href='enquiry.php' style='color:#667eea'>Send Enquiry</a>\n• <a href='page.php?type=contact' style='color:#667eea'>Contact Us</a>",
        quick: ['Show Packages','Contact Us','Send Enquiry','AI Recommender']
    };
}

/* ══════════════════════ Gemini-powered replies, with a safety net ══════════════════════
   The robot now talks to the same Gemini AI backend already used elsewhere in
   TMS (includes/ai/AIClient.php via api/ai.php?action=chatbot) instead of only
   matching fixed keyword patterns. If the API key isn't configured, the quota
   is hit, or the request simply fails (offline, slow network, etc.), we fall
   straight back to the original local kb matching below — the widget never
   just breaks or shows a raw error in the chat window. */
// Round 14: history is no longer sent from here. It lives server-side against a
// long-lived cookie (tms_chat_uid), so the conversation survives a page
// navigation — including clicking one of the bot's own links, which used to wipe
// it — and a client can no longer forge "the assistant previously said X".
function askAI(text) {
    return fetch('api/ai.php?action=chatbot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(text) +
              '&page_context=' + encodeURIComponent(CHAT_PAGE_CONTEXT + ' | ' + location.pathname + location.search) +
              '&csrf_token=' + encodeURIComponent(CHAT_CSRF)
    }).then(function(r) {
        return r.json().then(function (data) { return { status: r.status, data: data }; });
    }).then(function(res) {
        var data = res.data;
        if (data && data.success && data.data && data.data.reply) {
            // UPGRADE (AI-conversation round): the assistant now returns real,
            // context-derived quick replies alongside its answer (see
            // SiteAssistant::parseStructuredReply()) instead of nothing, so
            // respondTo() below can offer THOSE instead of the same fixed 4
            // buttons after every message. An empty/missing array here is
            // normal (no natural follow-up) and falls back to the defaults.
            return { ok: true, text: data.data.reply, suggestions: Array.isArray(data.data.suggestions) ? data.data.suggestions : [] };
        }
        // Keep the reason. It used to be discarded, so "the AI is switched off",
        // "you've hit the rate limit" and "the network is down" were all
        // indistinguishable from the bot simply giving a poor answer.
        return { ok: false, reason: (data && data.error) ? data.error : '', status: res.status };
    }).catch(function() {
        return { ok: false, reason: 'network' };
    });
}

function setChatBusy(busy) {
    chatBusy = busy;
    var send = document.querySelector('.chat-send');
    var input = document.getElementById('chatInput');
    if (send)  { send.disabled = busy; send.style.opacity = busy ? '0.5' : ''; }
    if (input) { input.disabled = busy; }
    if (!busy && input) { input.focus(); }
}

function respondTo(text, appendUserTurn) {
    setChatBusy(true);
    showTyping();
    askAI(text).then(function(result) {
        hideTyping();
        setChatBusy(false);
        if (result.ok) {
            // Real, context-derived suggestions when the assistant offered any;
            // otherwise fall back to the same fixed set as before this upgrade
            // (a page-navigation quick-reply row is still better than none).
            var qr = (result.suggestions && result.suggestions.length) ? result.suggestions
                   : ['Show Packages','AI Recommender','Budget Planner','Contact Us'];
            addMessage('bot', result.text, qr);
            speakChatReply(result.text);
            return;
        }

        // Fallback: the original local rule-based responder — but the visitor is
        // now TOLD it's a standard answer, instead of a weaker reply being
        // presented as if the assistant had considered their question.
        var resp = getResponse(text);
        var note;
        if (result.reason === 'network') {
            note = "\n\n_(I couldn't reach the assistant just now, so that's a standard answer. Check your connection and try again.)_";
        } else if (result.status === 429) {
            note = "\n\n_(The assistant is busy right now, so that's a standard answer — try again in a minute.)_";
        } else {
            note = "\n\n_(The smart assistant isn't available at the moment, so that's a standard answer.)_";
        }
        addMessage('bot', resp.text + note, (resp.quick || []).concat(['Try that again']));
        speakChatReply(resp.text);
    });
}

var lastUserMessage = '';

function sendMessage() {
    if (chatBusy) return; // one request in flight at a time
    var input = document.getElementById('chatInput');
    var text = input.value.trim();
    if(!text) return;
    input.value = '';
    lastUserMessage = text;
    addMessage('user', text, []);
    respondTo(text, true);
}

function handleQuickReply(text) {
    if (chatBusy) return;
    if (text === 'Try that again') {
        if (!lastUserMessage) return;
        respondTo(lastUserMessage, false);
        return;
    }
    tmsTrackQuickReply(text);
    lastUserMessage = text;
    addMessage('user', text, []);
    respondTo(text, true);
}

function tmsTrackQuickReply(label) {
    // Fire-and-forget usage analytics for the admin dashboard — never blocks
    // or delays the actual chat/navigation experience.
    try {
        fetch('api/ai.php?action=track_quickreply', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'label=' + encodeURIComponent(label)
        }).catch(function(){});
    } catch(e) {}
}

// Show notification pulse after 3 seconds
setTimeout(function() {
    if(!chatOpen) {
        document.querySelector('#tmsChatToggle .notif').style.display = 'block';
    }
}, 3000);

// ═══════════════════════════════════════════════════════════════════════════
// Voice support (Section 15 audit — "Voice support (if available)")
//
// Same browser-native Web Speech API approach already proven in
// ai-copilot.php: SpeechRecognition fills the existing chatInput and reuses
// sendMessage() as-is (no separate code path for voice vs typed messages),
// SpeechSynthesis speaks bot replies aloud when enabled. 100% client-side,
// no server changes, no new cost. Both controls stay hidden (display:none,
// set in the HTML above) on any browser that doesn't support these APIs —
// mainly desktop Firefox — rather than showing a button that does nothing.
// ═══════════════════════════════════════════════════════════════════════════

var chatRecognition = null;
var chatIsListening = false;
var chatVoiceOutputEnabled = false;

var ChatSpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

if (ChatSpeechRecognitionAPI) {
    var micBtn = document.getElementById('chatMicBtn');
    micBtn.style.display = 'inline-flex';

    chatRecognition = new ChatSpeechRecognitionAPI();
    chatRecognition.continuous = false;
    chatRecognition.interimResults = false;
    chatRecognition.lang = 'en-US';

    chatRecognition.onstart = function() {
        chatIsListening = true;
        micBtn.classList.add('listening');
    };
    chatRecognition.onend = function() {
        chatIsListening = false;
        micBtn.classList.remove('listening');
    };
    chatRecognition.onerror = function() {
        chatIsListening = false;
        micBtn.classList.remove('listening');
    };
    chatRecognition.onresult = function(event) {
        var transcript = event.results[0][0].transcript;
        document.getElementById('chatInput').value = transcript;
        sendMessage();
    };
}

function toggleListening() {
    if (!chatRecognition) return;
    if (chatIsListening) {
        chatRecognition.stop();
    } else {
        try { chatRecognition.start(); } catch (e) { /* already running — ignore */ }
    }
}

// Task 6 fix: this used to let the browser pick its own default voice for
// spoken replies, which on most systems/browsers is a male voice. The Web
// Speech API doesn't expose a real "gender" field, so there's no single
// reliable flag to filter on — instead this scores every voice the browser
// offers using name patterns that are consistently female across the major
// engines (Chrome/Edge/Android/iOS/Windows all label their voices, and the
// well-known named voices are documented public information, not guesses),
// prefers higher-quality "Natural"/"Online"/"Neural" voices when more than
// one female match exists, and prefers English so pronunciation stays clear
// for this widget's English canned responses. If nothing matches (some
// stripped-down Linux/Chromium builds ship almost no voices), it falls back
// to the browser's own default rather than breaking speech entirely.
var chatSelectedVoice = null;
var CHAT_FEMALE_VOICE_NAMES = [
    'female', 'zira', 'hazel', 'susan', 'samantha', 'karen', 'moira', 'tessa',
    'victoria', 'allison', 'ava', 'serena', 'fiona', 'kate', 'catherine',
    'aria', 'jenny', 'michelle', 'joanna', 'salli', 'kimberly', 'ivy', 'kendra',
    'amy', 'emma', 'olivia', 'sonia', 'libby', 'natasha', 'shelley', 'nicky',
    'google us english', 'google uk english female'
];
function pickChatVoice() {
    if (!('speechSynthesis' in window)) return null;
    var voices = window.speechSynthesis.getVoices() || [];
    if (!voices.length) return null;

    var scored = voices.map(function(v) {
        var name = (v.name || '').toLowerCase();
        var isFemale = CHAT_FEMALE_VOICE_NAMES.some(function(n) { return name.indexOf(n) !== -1; });
        var isEnglish = (v.lang || '').toLowerCase().indexOf('en') === 0;
        var isHighQuality = /natural|online|neural|premium/.test(name);
        var score = (isFemale ? 100 : 0) + (isEnglish ? 10 : 0) + (isHighQuality ? 5 : 0);
        return { voice: v, score: score };
    });
    scored.sort(function(a, b) { return b.score - a.score; });
    // Only actually use the pick if it scored as a female match — otherwise
    // fall back to the browser default rather than forcing a male voice
    // through with a "female" label just because it was first in the list.
    return (scored[0] && scored[0].score >= 100) ? scored[0].voice : null;
}
function refreshChatVoice() {
    chatSelectedVoice = pickChatVoice();
}
if ('speechSynthesis' in window) {
    refreshChatVoice();
    // Voice lists load asynchronously in most browsers — this event fires
    // once they're actually ready, so the first spoken reply doesn't miss
    // out on the female voice just because it fired before load.
    window.speechSynthesis.onvoiceschanged = refreshChatVoice;
}

if ('speechSynthesis' in window) {
    var voiceToggle = document.getElementById('chatVoiceToggle');
    voiceToggle.style.display = 'inline-flex';
    chatVoiceOutputEnabled = localStorage.getItem('tms_chatbot_voice_replies') === '1';
    updateChatVoiceToggleUI();
    voiceToggle.onclick = function() { toggleChatVoiceOutput(); };
}

function toggleChatVoiceOutput() {
    chatVoiceOutputEnabled = !chatVoiceOutputEnabled;
    localStorage.setItem('tms_chatbot_voice_replies', chatVoiceOutputEnabled ? '1' : '0');
    if (!chatVoiceOutputEnabled) window.speechSynthesis.cancel();
    updateChatVoiceToggleUI();
}

function updateChatVoiceToggleUI() {
    var btn = document.getElementById('chatVoiceToggle');
    if (!btn) return;
    btn.innerHTML = chatVoiceOutputEnabled ? '<i class="fa fa-volume-up"></i>' : '<i class="fa fa-volume-off"></i>';
    btn.classList.toggle('on', chatVoiceOutputEnabled);
    btn.title = chatVoiceOutputEnabled ? 'Spoken replies: on' : 'Spoken replies: off';
}

function speakChatReply(text) {
    if (!chatVoiceOutputEnabled || !('speechSynthesis' in window)) return;
    // Strip the markdown-ish bold/links/bullets this widget's renderer uses
    // so the spoken version doesn't read out asterisks and raw HTML tags.
    var clean = text.replace(/<[^>]+>/g, '').replace(/\*\*(.*?)\*\*/g, '$1').replace(/[*_#•]/g, '').replace(/\n+/g, '. ');
    window.speechSynthesis.cancel(); // don't queue/overlap replies
    var utterance = new SpeechSynthesisUtterance(clean);
    if (chatSelectedVoice) {
        utterance.voice = chatSelectedVoice;
        utterance.lang = chatSelectedVoice.lang;
    }
    utterance.rate = 1.0;
    utterance.pitch = 1.0;
    window.speechSynthesis.speak(utterance);
}
</script>
