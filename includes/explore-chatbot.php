<!-- TMS Explore Mode — Floating AI Travel Assistant -->
<!-- Include on any Explore page: include('includes/explore-chatbot.php'); -->
<button id="exploreChatToggle" onclick="toggleExploreChat()" title="Ask the AI Travel Assistant">
    <i class="fa fa-robot"></i>
</button>

<div id="exploreChatPanel">
    <div class="exp-chat-header">
        <div class="exp-chat-avatar">🧭</div>
        <div class="exp-chat-header-info">
            <h5>AI Travel Assistant</h5>
            <p>🟢 Live &middot; powered by Gemini</p>
        </div>
        <button class="exp-chat-close" onclick="toggleExploreChat()"><i class="fa fa-times"></i></button>
    </div>
    <div class="exp-chat-messages" id="exploreChatMessages"></div>
    <div class="exp-chat-input-area">
        <input type="text" class="exp-chat-input" id="exploreChatInput" placeholder="Ask about this destination..." onkeypress="if(event.key==='Enter') sendExploreChat()">
        <button class="exp-chat-send" onclick="sendExploreChat()"><i class="fa fa-paper-plane"></i></button>
    </div>
</div>
