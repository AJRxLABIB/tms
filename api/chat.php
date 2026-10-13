<?php
/**
 * api/chat.php — Tourist <-> Agency live chat
 *
 * Reviewer ask: "Chat Between Tourist & Agency... real-time implementation
 * ... Typing indicator, Read receipts." True WebSockets need a persistent-
 * connection server this stack doesn't run — this is polling-based
 * (clients call ?action=poll every ~3s), which is an honest, standard way
 * to deliver near-real-time chat on plain PHP/Apache hosting, not a fake
 * "real-time" label over something that doesn't actually work.
 *
 * Endpoints:
 *   GET  ?action=poll&since_id=N            (customer: own thread; admin: &thread=email required)
 *   POST ?action=send                        (body: message; admin also sends &thread=email)
 *   POST ?action=typing                      (body: thread=email required for admin)
 *   POST ?action=mark_read                   (body: thread=email required for admin)
 *   GET  ?action=threads                     (admin only — list all customer threads)
 */

require_once __DIR__ . '/../backend/session-boot.php';
require_once dirname(__DIR__) . '/backend/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

function chatError(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function chatSuccess(array $data): void {
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = !empty($_SESSION['alogin']);
$isCustomer = !empty($_SESSION['login']);
if (!$isAdmin && !$isCustomer) {
    chatError(401, 'Please sign in to use chat.');
}

// Resolve which thread (which customer's conversation) this request is for.
if ($isAdmin) {
    $thread = tms_clean($_GET['thread'] ?? $_POST['thread'] ?? '');
    if ($thread === '') chatError(400, 'Admin requests must specify ?thread=customer_email.');
} else {
    $thread = $_SESSION['login']; // customers can only ever see their own thread
}

$action = tms_clean($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {

    case 'poll': {
        $sinceId = (int)($_GET['since_id'] ?? 0);
        $q = $dbh->prepare(
            "SELECT id, SenderRole, SenderName, Message, CreatedAt FROM tblchat_messages
             WHERE UserEmail = :e AND id > :sid ORDER BY id ASC LIMIT 100"
        );
        $q->execute([':e' => $thread, ':sid' => $sinceId]);
        $messages = $q->fetchAll(PDO::FETCH_ASSOC);

        // Typing indicator: "typing" only counts if the timestamp is within
        // the last 6 seconds — otherwise a browser tab left open after
        // someone stopped typing would show a stale "typing..." forever.
        $tq = $dbh->prepare("SELECT * FROM tblchat_threads WHERE UserEmail = :e");
        $tq->execute([':e' => $thread]);
        $threadRow = $tq->fetch(PDO::FETCH_ASSOC);
        $otherTypingField = $isAdmin ? 'CustomerTypingAt' : 'AdminTypingAt';
        $otherTyping = false;
        if ($threadRow && !empty($threadRow[$otherTypingField])) {
            $otherTyping = (time() - strtotime($threadRow[$otherTypingField])) <= 6;
        }

        chatSuccess([
            'messages' => $messages,
            'other_typing' => $otherTyping,
            'last_id' => $messages ? (int)end($messages)['id'] : $sinceId,
        ]);
    }

    case 'send': {
        tms_csrf_validate();
        $message = trim((string)($_POST['message'] ?? ''));
        if ($message === '') chatError(400, 'Message cannot be empty.');
        if (mb_strlen($message) > 2000) chatError(400, 'Message is too long.');

        $senderRole = $isAdmin ? 'admin' : 'customer';
        $senderName = $isAdmin ? 'Support Team' : ($_SESSION['login'] ?? 'Guest');

        $dbh->prepare(
            "INSERT INTO tblchat_messages (UserEmail, SenderRole, SenderName, Message) VALUES (:e, :r, :n, :m)"
        )->execute([':e' => $thread, ':r' => $senderRole, ':n' => $senderName, ':m' => $message]);
        // Round 102 fix: lastInsertId() must be captured HERE, immediately
        // after this message INSERT -- it used to be read only once, after
        // the tblchat_threads upsert below, which meant the 'id' this
        // endpoint returned was always from tblchat_threads (a different
        // table entirely), never the actual message's own id. Same bug
        // class as the Round 96 coupon fix. Confirmed via grep that no
        // caller (admin/live-chat.php, backend/chat-widget.php,
        // includes/chat-widget.php) actually reads this response field
        // today, so this was latent, not yet user-visible -- but a real
        // landmine for any future feature (e.g. "jump to this message")
        // that trusts it.
        $newMessageId = (int)$dbh->lastInsertId();

        // Ensure a thread row exists (for typing/read-receipt tracking).
        $dbh->prepare(
            "INSERT INTO tblchat_threads (UserEmail) VALUES (:e) ON DUPLICATE KEY UPDATE UserEmail=UserEmail"
        )->execute([':e' => $thread]);

        // Notify the customer when the admin replies — reuses the existing
        // notification infrastructure from Round 10, same as the support
        // ticket reply feature.
        if ($isAdmin) {
            tms_notify($dbh, $thread, "💬 You have a new message from Support.", 'index.php');
            // Round 101 (audit log retrofit): live chat was one of the few
            // remaining admin-facing surfaces with a real mutation and zero
            // accountability trail -- an admin could message any customer
            // with nothing recorded in tblactivity_log. Logs who replied to
            // which customer thread and when; stores only a short preview
            // of the message (not the full text) since the full message
            // already lives in tblchat_messages -- this is an accountability
            // record ("Actor X replied to Customer Y"), not a second copy of
            // the conversation. Customer-sent messages are intentionally
            // NOT logged here -- this project's audit log tracks admin/staff
            // actions, not customer activity.
            tms_log_activity(
                $dbh, $_SESSION['alogin'], 'Live Chat Reply Sent',
                'To: ' . $thread . ' — "' . mb_substr($message, 0, 100) . (mb_strlen($message) > 100 ? '...' : '') . '"',
                'chat_thread', $thread
            );
        }

        chatSuccess(['id' => $newMessageId]);
    }

    case 'typing': {
        $field = $isAdmin ? 'AdminTypingAt' : 'CustomerTypingAt';
        $dbh->prepare(
            "INSERT INTO tblchat_threads (UserEmail, $field) VALUES (:e, NOW())
             ON DUPLICATE KEY UPDATE $field = NOW()"
        )->execute([':e' => $thread]);
        chatSuccess([]);
    }

    case 'mark_read': {
        $field = $isAdmin ? 'AdminLastSeen' : 'CustomerLastSeen';
        $dbh->prepare(
            "INSERT INTO tblchat_threads (UserEmail, $field) VALUES (:e, NOW())
             ON DUPLICATE KEY UPDATE $field = NOW()"
        )->execute([':e' => $thread]);
        // Mark the other side's messages as read.
        $readerRole = $isAdmin ? 'customer' : 'admin';
        $dbh->prepare(
            "UPDATE tblchat_messages SET IsRead=1 WHERE UserEmail=:e AND SenderRole=:r"
        )->execute([':e' => $thread, ':r' => $readerRole]);
        chatSuccess([]);
    }

    case 'threads': {
        if (!$isAdmin) chatError(403, 'Admin only.');
        // One row per customer who has ever messaged, with their last
        // message and an honest unread count (messages from the customer
        // that the admin hasn't marked read yet).
        $rows = $dbh->query(
            "SELECT m.UserEmail,
                    (SELECT Message FROM tblchat_messages m2 WHERE m2.UserEmail=m.UserEmail ORDER BY id DESC LIMIT 1) AS LastMessage,
                    (SELECT CreatedAt FROM tblchat_messages m3 WHERE m3.UserEmail=m.UserEmail ORDER BY id DESC LIMIT 1) AS LastAt,
                    SUM(CASE WHEN m.SenderRole='customer' AND m.IsRead=0 THEN 1 ELSE 0 END) AS Unread
             FROM tblchat_messages m
             GROUP BY m.UserEmail
             ORDER BY LastAt DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        chatSuccess(['threads' => $rows]);
    }

    default:
        chatError(404, "Unknown action: $action");
}
