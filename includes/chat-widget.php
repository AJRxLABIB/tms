<?php
/**
 * includes/chat-widget.php — human Tourist <-> Agency live chat widget.
 * Included site-wide from footer.php, but only renders for logged-in
 * customers (matching the wishlist/notification pattern — guests get
 * prompted to sign in elsewhere, this widget just doesn't show for them
 * rather than showing a chat box that would fail on first message).
 * Distinct from includes/chatbot.php (the AI SiteAssistant) — this is a
 * real human-agency channel, positioned to the left of the AI bubble so
 * the two don't overlap.
 */
if (empty($_SESSION['login'])) return;
?>
<style>
#tmsHumanChatToggle {
    position: fixed; bottom: 95px; right: 25px; width: 56px; height: 56px;
    border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary));
    color: #fff; border: none; box-shadow: 0 6px 20px rgba(15,76,129,0.35);
    cursor: pointer; z-index: 9997; display: flex; align-items: center; justify-content: center; font-size: 22px;
    transition: transform .2s;
}
#tmsHumanChatToggle:hover { transform: scale(1.08); }
#tmsHumanChatToggle .tms-chat-badge {
    position: absolute; top: -2px; right: -2px; min-width: 18px; height: 18px; padding: 0 4px;
    background: #e11d48; border-radius: 20px; border: 2px solid #fff; color: #fff; font-size: 10px;
    font-weight: 700; display: none; align-items: center; justify-content: center;
}
#tmsHumanChatPanel {
    position: fixed; bottom: 165px; right: 25px; width: 340px; height: 460px;
    background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.22);
    z-index: 9997; display: none; flex-direction: column; overflow: hidden;
}
#tmsHumanChatPanel .hc-head { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: #fff; padding: 14px 16px; }
#tmsHumanChatPanel .hc-head .t { font-weight: 700; font-size: 14px; }
#tmsHumanChatPanel .hc-head .s { font-size: 11px; opacity: .8; margin-top: 2px; }
#tmsHumanChatBody { flex: 1; overflow-y: auto; padding: 14px; background: var(--bg); display: flex; flex-direction: column; gap: 8px; }
.hc-msg { max-width: 78%; padding: 8px 12px; border-radius: 14px; font-size: 13px; line-height: 1.4; }
.hc-msg.customer { align-self: flex-end; background: var(--secondary); color: #fff; border-bottom-right-radius: 4px; }
.hc-msg.admin { align-self: flex-start; background: #fff; color: var(--text); box-shadow: var(--shadow-sm); border-bottom-left-radius: 4px; }
.hc-msg .hc-time { font-size: 9.5px; opacity: .65; margin-top: 3px; }
#tmsHumanTyping { font-size: 11px; color: var(--text-muted); padding: 0 14px 6px; display: none; }
#tmsHumanChatForm { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #eef1f5; }
#tmsHumanChatInput { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 20px; padding: 9px 14px; font-size: 13px; outline: none; }
#tmsHumanChatInput:focus { border-color: var(--secondary); }
#tmsHumanChatForm button { background: var(--secondary); color: #fff; border: none; width: 38px; height: 38px; border-radius: 50%; cursor: pointer; }
#tmsHumanChatEmpty { text-align: center; color: var(--text-light); font-size: 12.5px; padding: 30px 20px; }
@media (max-width: 480px) {
    #tmsHumanChatToggle { right: 16px; bottom: 78px; width: 50px; height: 50px; }
    #tmsHumanChatPanel { right: 8px; left: 8px; width: auto; bottom: 138px; height: 60vh; }
}
</style>

<button id="tmsHumanChatToggle" title="Chat with our team" aria-label="Chat with our team" onclick="tmsHumanChatToggle()">
    <i class="fa fa-comments"></i>
    <span class="tms-chat-badge" id="tmsHumanChatBadge"></span>
</button>
<div id="tmsHumanChatPanel">
    <div class="hc-head">
        <div class="t"><i class="fa fa-headset"></i> Chat with our team</div>
        <div class="s">Usually replies within a few minutes</div>
    </div>
    <div id="tmsHumanChatBody"><div id="tmsHumanChatEmpty">Say hello — a real member of our team will reply here.</div></div>
    <div id="tmsHumanTyping"><i class="fa fa-ellipsis-h"></i> Support is typing…</div>
    <form id="tmsHumanChatForm" onsubmit="return tmsHumanChatSend(event)">
        <?php tms_csrf_field(); ?>
        <input type="text" id="tmsHumanChatInput" placeholder="Type a message…" autocomplete="off" oninput="tmsHumanChatTypingPing()">
        <button type="submit" aria-label="Send message"><i class="fa fa-paper-plane"></i></button>
    </form>
</div>
<script>
(function(){
    var CSRF = <?php echo json_encode(tms_csrf_token()); ?>;
    var lastId = 0, panelOpen = false, pollTimer = null, typingThrottle = 0;

    window.tmsHumanChatToggle = function(){
        panelOpen = !panelOpen;
        document.getElementById('tmsHumanChatPanel').style.display = panelOpen ? 'flex' : 'none';
        if (panelOpen) {
            markRead();
            document.getElementById('tmsHumanChatBadge').style.display = 'none';
        }
    };

    function escHtml(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function fmtTime(iso){
        var d = new Date(iso.replace(' ', 'T'));
        return isNaN(d) ? '' : d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }

    function renderMessages(msgs){
        if (!msgs.length) return;
        var body = document.getElementById('tmsHumanChatBody');
        var empty = document.getElementById('tmsHumanChatEmpty');
        if (empty) empty.remove();
        msgs.forEach(function(m){
            var div = document.createElement('div');
            div.className = 'hc-msg ' + (m.SenderRole === 'admin' ? 'admin' : 'customer');
            div.innerHTML = escHtml(m.Message) + '<div class="hc-time">' + fmtTime(m.CreatedAt) + '</div>';
            body.appendChild(div);
            lastId = Math.max(lastId, parseInt(m.id, 10));
        });
        body.scrollTop = body.scrollHeight;
    }

    function markRead(){
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fetch('api/chat.php?action=mark_read', { method: 'POST', body: fd }).catch(function(){});
    }

    function poll(){
        fetch('api/chat.php?action=poll&since_id=' + lastId)
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) return;
                renderMessages(res.messages || []);
                document.getElementById('tmsHumanTyping').style.display = res.other_typing ? 'block' : 'none';
                if ((res.messages || []).length && !panelOpen) {
                    var badge = document.getElementById('tmsHumanChatBadge');
                    var current = parseInt(badge.textContent || '0', 10) || 0;
                    badge.textContent = current + res.messages.length;
                    badge.style.display = 'flex';
                }
                if (panelOpen && (res.messages || []).length) markRead();
            })
            .catch(function(){ /* silent — next poll will retry */ });
    }

    window.tmsHumanChatSend = function(e){
        e.preventDefault();
        var input = document.getElementById('tmsHumanChatInput');
        var msg = input.value.trim();
        if (!msg) return false;
        input.value = '';
        var fd = new FormData();
        fd.append('message', msg);
        fd.append('csrf_token', CSRF);
        fetch('api/chat.php?action=send', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(){ poll(); })
            .catch(function(){ alert('Could not send — please check your connection and try again.'); });
        return false;
    };

    window.tmsHumanChatTypingPing = function(){
        var now = Date.now();
        if (now - typingThrottle < 2000) return; // throttle to at most once every 2s
        typingThrottle = now;
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fetch('api/chat.php?action=typing', { method: 'POST', body: fd }).catch(function(){});
    };

    poll();
    pollTimer = setInterval(poll, 3000);
})();
</script>
