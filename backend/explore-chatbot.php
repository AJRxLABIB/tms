<!-- TMS Explore Mode — Floating AI Travel Assistant -->
<!-- Include on any Explore page: include('backend/explore-chatbot.php'); -->
<?php
// The assistant is always offered. What the badge says depends on what actually answers (AIClient::resolution()):
//   ollama -> local model, gemini -> Gemini, none -> catalogue answers only (no language model, still useful and honest).
// (FIX Round 14 hid the widget when no Gemini key existed; with local Ollama and the catalogue fallback that is no longer needed.)
$tmsExploreAiBadge = '🟢 Live &middot; local AI';
try {
    if (isset($dbh) && $dbh instanceof PDO) {
        require_once __DIR__ . '/ai/AIClient.php';
        $tmsExploreRes = AIClient::resolution($dbh);
        if ($tmsExploreRes['provider'] === 'gemini')      $tmsExploreAiBadge = '🟢 Live &middot; powered by Gemini';
        elseif ($tmsExploreRes['provider'] === 'openai')  $tmsExploreAiBadge = '🟢 Live &middot; powered by OpenAI';
        elseif ($tmsExploreRes['provider'] === 'none')    $tmsExploreAiBadge = '🟡 Catalogue answers only &middot; AI is offline';
    }
} catch (Throwable $e) { error_log('[TMS] explore chatbot badge: ' . $e->getMessage()); }
?>
<button id="exploreChatToggle" onclick="toggleExploreChat()" title="Ask the AI Travel Assistant">
    <i class="fa fa-robot"></i>
</button>

<div id="exploreChatPanel">
    <div class="exp-chat-header">
        <div class="exp-chat-avatar">🧭</div>
        <div class="exp-chat-header-info">
            <h5>AI Travel Assistant</h5>
            <p><?php echo $tmsExploreAiBadge; ?></p>
        </div>
        <button class="exp-chat-close" onclick="toggleExploreChat()"><i class="fa fa-times"></i></button>
    </div>
    <div class="exp-chat-messages" id="exploreChatMessages"></div>
    <div class="exp-chat-input-area">
        <input type="text" class="exp-chat-input" id="exploreChatInput" placeholder="Ask about this destination..." onkeypress="if(event.key==='Enter') sendExploreChat()">
        <button class="exp-chat-send" onclick="sendExploreChat()"><i class="fa fa-paper-plane"></i></button>
    </div>
</div>
