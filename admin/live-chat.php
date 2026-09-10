<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
include('includes/config.php');
tms_require_admin();
tms_admin_require_role($dbh, ['support', 'manager']); // Round 20 RBAC (item 46)
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(tms_active_language(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TMS | Live Chat</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link href="css/font-awesome.css" rel="stylesheet">
<link href="css/bootstrap.min.css" rel="stylesheet">
<link href="css/admin-v6.css" rel="stylesheet">
<style>
.page-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title-row h2{font-family:'Plus Jakarta Sans',sans-serif;font-size:22px;font-weight:800;color:#0f172a;margin:0;}
.lc-wrap{display:flex;gap:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;height:640px;}
.lc-threads{width:300px;border-right:1px solid #eef1f5;overflow-y:auto;flex-shrink:0;}
.lc-thread{padding:14px 16px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:.15s;position:relative;}
.lc-thread:hover{background:#f8fafc;}
.lc-thread.active{background:#eff6ff;border-left:3px solid #1d4ed8;}
.lc-thread .email{font-size:13px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lc-thread .preview{font-size:11.5px;color:#94a3b8;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lc-thread .unread{position:absolute;top:14px;right:14px;background:#e11d48;color:#fff;font-size:10px;font-weight:700;border-radius:20px;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;padding:0 5px;}
.lc-empty-threads{padding:40px 20px;text-align:center;color:#94a3b8;font-size:13px;}
.lc-conv{flex:1;display:flex;flex-direction:column;}
.lc-conv-head{padding:14px 18px;border-bottom:1px solid #eef1f5;font-weight:700;font-size:14px;color:#0f172a;}
.lc-conv-body{flex:1;overflow-y:auto;padding:16px;background:#f8fafc;display:flex;flex-direction:column;gap:8px;}
.lc-msg{max-width:70%;padding:8px 12px;border-radius:14px;font-size:13px;line-height:1.4;}
.lc-msg.customer{align-self:flex-start;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);border-bottom-left-radius:4px;}
.lc-msg.admin{align-self:flex-end;background:#1d4ed8;color:#fff;border-bottom-right-radius:4px;}
.lc-msg .t{font-size:9.5px;opacity:.6;margin-top:3px;}
.lc-typing{font-size:11px;color:#94a3b8;padding:0 18px 6px;display:none;}
.lc-form{display:flex;gap:8px;padding:12px;border-top:1px solid #eef1f5;}
.lc-form input{flex:1;border:1.5px solid #e2e8f0;border-radius:20px;padding:9px 14px;font-size:13px;outline:none;}
.lc-form input:focus{border-color:#1d4ed8;}
.lc-form button{background:#1d4ed8;color:#fff;border:none;width:38px;height:38px;border-radius:50%;cursor:pointer;}
.lc-none-selected{flex:1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;flex-direction:column;gap:10px;}
html[data-theme="dark"] .page-title-row h2,
html[data-theme="dark"] .lc-thread .email,
html[data-theme="dark"] .lc-conv-head { color:#eef2f7 !important; }
html[data-theme="dark"] .lc-wrap,
html[data-theme="dark"] .lc-msg.customer { background:#1b222c !important; border-color:#2b3542 !important; }
html[data-theme="dark"] .lc-threads { border-color:#2b3542 !important; }
html[data-theme="dark"] .lc-thread { border-color:#232b37 !important; }
html[data-theme="dark"] .lc-thread:hover { background:#232b37 !important; }
html[data-theme="dark"] .lc-conv-body { background:#151b24 !important; }
html[data-theme="dark"] .lc-conv-head,
html[data-theme="dark"] .lc-form { border-color:#2b3542 !important; }
html[data-theme="dark"] .lc-form input { background:#232b37 !important; color:#eef2f7 !important; border-color:#333f4d !important; }
</style>
</head>
<body>
<div class="tms-shell">
<?php include('includes/sidebarmenu.php'); ?>
<main class="tms-main" id="tmsMain">
<?php include('includes/header.php'); ?>
<div class="tms-content">
<div class="page-title-row">
  <h2><i class="fa fa-comments" style="color:#1d4ed8;margin-right:10px;"></i>Live Chat</h2>
</div>

<div class="lc-wrap">
  <div class="lc-threads" id="lcThreads">
    <div class="lc-empty-threads"><i class="fa fa-spinner fa-spin"></i> Loading conversations…</div>
  </div>
  <div class="lc-conv" id="lcConv">
    <div class="lc-none-selected"><i class="fa fa-comments" style="font-size:34px;color:#dbeafe;"></i>Select a conversation to start replying.</div>
  </div>
</div>

</div>
</main>
</div>
<?php include('includes/footer.php'); ?>
<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
(function(){
    var CSRF = <?php echo json_encode(tms_csrf_token()); ?>;
    var activeThread = null, lastId = 0, threadsTimer = null, msgTimer = null;

    function escHtml(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function fmtTime(iso){
        if (!iso) return '';
        var d = new Date(iso.replace(' ', 'T'));
        return isNaN(d) ? '' : d.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
    }

    function loadThreads(){
        fetch('api/chat.php?action=threads')
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) return;
                var el = document.getElementById('lcThreads');
                if (!res.threads.length) {
                    el.innerHTML = '<div class="lc-empty-threads">No conversations yet.</div>';
                    return;
                }
                el.innerHTML = '';
                res.threads.forEach(function(t){
                    var row = document.createElement('div');
                    row.className = 'lc-thread' + (t.UserEmail === activeThread ? ' active' : '');
                    row.innerHTML = '<div class="email">' + escHtml(t.UserEmail) + '</div>' +
                        '<div class="preview">' + escHtml((t.LastMessage||'').slice(0,50)) + '</div>' +
                        (t.Unread > 0 ? '<span class="unread">' + t.Unread + '</span>' : '');
                    row.addEventListener('click', function(){ lcOpenThread(t.UserEmail); });
                    el.appendChild(row);
                });
            })
            .catch(function(){});
    }

    window.lcOpenThread = function(email){
        activeThread = email;
        lastId = 0;
        loadThreads();
        document.getElementById('lcConv').innerHTML =
            '<div class="lc-conv-head">' + escHtml(email) + '</div>' +
            '<div class="lc-conv-body" id="lcConvBody"></div>' +
            '<div class="lc-typing" id="lcTyping"><i class="fa fa-ellipsis-h"></i> Typing…</div>' +
            '<form class="lc-form" id="lcForm" onsubmit="return lcSend(event)">' +
            '<input type="text" id="lcInput" placeholder="Type a reply…" autocomplete="off">' +
            '<button type="submit"><i class="fa fa-paper-plane"></i></button></form>';
        pollMessages();
        if (msgTimer) clearInterval(msgTimer);
        msgTimer = setInterval(pollMessages, 3000);
        markRead();
    };

    function markRead(){
        if (!activeThread) return;
        var fd = new FormData();
        fd.append('csrf_token', CSRF); fd.append('thread', activeThread);
        fetch('api/chat.php?action=mark_read', { method:'POST', body: fd }).catch(function(){});
    }

    function pollMessages(){
        if (!activeThread) return;
        fetch('api/chat.php?action=poll&since_id=' + lastId + '&thread=' + encodeURIComponent(activeThread))
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) return;
                var body = document.getElementById('lcConvBody');
                if (!body) return;
                (res.messages || []).forEach(function(m){
                    var div = document.createElement('div');
                    div.className = 'lc-msg ' + (m.SenderRole === 'admin' ? 'admin' : 'customer');
                    div.innerHTML = escHtml(m.Message) + '<div class="t">' + fmtTime(m.CreatedAt) + '</div>';
                    body.appendChild(div);
                    lastId = Math.max(lastId, parseInt(m.id, 10));
                });
                if ((res.messages||[]).length) { body.scrollTop = body.scrollHeight; markRead(); }
                var typingEl = document.getElementById('lcTyping');
                if (typingEl) typingEl.style.display = res.other_typing ? 'block' : 'none';
            })
            .catch(function(){});
    }

    window.lcSend = function(e){
        e.preventDefault();
        var input = document.getElementById('lcInput');
        var msg = input.value.trim();
        if (!msg || !activeThread) return false;
        input.value = '';
        var fd = new FormData();
        fd.append('message', msg); fd.append('csrf_token', CSRF); fd.append('thread', activeThread);
        fetch('api/chat.php?action=send', { method:'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(){ pollMessages(); loadThreads(); })
            .catch(function(){ alert('Could not send — please try again.'); });
        return false;
    };

    loadThreads();
    threadsTimer = setInterval(loadThreads, 5000);
})();
</script>
</body>
</html>
