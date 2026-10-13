<?php
// DISABLED after use -- ONE-TIME QA diagnostic (session-simulation via direct
// $_SESSION['login'] write to a real customer with a real confirmed booking,
// no password entry, per the standing security constraint). Used to
// live-verify two things on frontend/ai-copilot.php: (1) TripCopilot.php's
// new stripDuplicatedEnvelope() fix (applied defensively by code-review
// parity with SiteAssistant.php) -- confirmed clean with the exact question
// that had reproduced the JSON-leak bug on the sitewide widget; (2) a real,
// independently-discovered bug: this page and backend/chatbot.php's sitewide
// floating widget both declare id="chatInput", id="chatMessages", and
// functions sendMessage()/toggleListening()/showTyping()/hideTyping() --
// since ai-copilot.php's own <script> runs after the widget's (included via
// backend/footer.php), its definitions silently overwrote the widget's,
// breaking the floating chat-robot icon on this one page (confirmed: typing
// into it and clicking send did nothing). Fixed by wrapping the widget's
// entire script in an IIFE and renaming its two colliding ids to
// tmsWidgetChatInput/tmsWidgetChatMessages -- confirmed live afterward: both
// chat UIs work correctly and independently on the same page. Kept only as a
// 404 stub per this project's standard throwaway-script convention.
http_response_code(404); die('Not found.');
