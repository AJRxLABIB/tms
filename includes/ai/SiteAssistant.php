<?php
/**
 * SiteAssistant.php — Gemini-powered conversational agent for the site-wide
 * "AI robot" chat widget (includes/chatbot.php).
 *
 * Unlike TripCopilot (which is scoped to a single logged-in user's specific
 * booking), this agent is the general-purpose front-of-house assistant that
 * greets any visitor — guest or signed-in — the moment they click the robot
 * icon. It follows the same pattern as the other agents in this folder
 * (TravelRecommender, BudgetOptimizer, etc.): a thin class that builds a
 * system prompt anchored to real site data and delegates the actual model
 * call to AIClient, which already handles caching, rate-limiting, and a
 * graceful fallback if the Gemini API key is missing or the quota is hit.
 *
 * The PHP side never has to implement a "no AI available" message itself —
 * api/ai.php simply returns success=false in that case, and the browser-side
 * chatbot.php falls back to its original rule-based keyword responses, so
 * the widget always works even with zero API quota.
 */

require_once __DIR__ . '/AIClient.php';

class SiteAssistant {

    private AIClient $ai;
    private PDO      $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * @param string $message  The visitor's latest message.
     * @param array  $history  Prior turns in this conversation, oldest first:
     *                         [['role'=>'user'|'assistant','content'=>'...'], ...]
     *                         Capped to the last few turns by the caller.
     * @param string $email    Signed-in user's email, or 'guest'.
     * @return array           ['success'=>bool, 'reply'=>string, 'error'=>string]
     */
    public function reply(string $message, array $history, string $email = 'guest'): array {
        $system = $this->buildSystemPrompt($email);

        $messages = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($turn['content'] ?? ''));
            if ($content === '') continue;
            $messages[] = ['role' => $role, 'content' => $content];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        // Chat is inherently turn-specific, so we don't cache by conversation —
        // caching would mean two different visitors asking the same first
        // question get a stale reply from whichever one asked first, which
        // (unlike a static recommendation) reads as broken in a live chat.
        $result = $this->ai->call($system, $messages, '', $email);

        if (!$result['success']) {
            return ['success' => false, 'reply' => '', 'error' => $result['error'] ?? 'AI unavailable'];
        }

        return ['success' => true, 'reply' => trim($result['content']), 'error' => ''];
    }

    private function buildSystemPrompt(string $email): string {
        $packageBlurb = $this->getPackageBlurb();
        $loginNote = $email !== 'guest'
            ? "The visitor is signed in (email: {$email})."
            : "The visitor is browsing as a guest (not signed in).";

        return <<<SYSTEM
You are the TMS Travel Assistant — a friendly, knowledgeable front-desk AI for
TMS (Tourism Management System), a Bangladesh-based travel booking platform.
All prices on the site are in Bangladeshi Taka (৳), never dollars.

{$loginNote}

WHAT TMS OFFERS RIGHT NOW:
{$packageBlurb}

SITE PAGES YOU CAN LINK TO (use relative paths exactly as written, inside a
normal HTML anchor tag like <a href="package-list.php" style="color:#667eea">
Tour Packages</a> — this chat window renders raw HTML, so anchors work,
markdown does not):
- package-list.php — browse/search all tour packages
- package-details.php?pkgid=ID — a specific package (only link this if you
  actually know a real numeric ID from context; otherwise link package-list.php)
- ai-recommend.php — AI-powered personalized package recommender
- budget-planner.php — travel cost calculator
- tour-map.php — interactive map with destination search and get-directions
- custom-trip.php — build a fully custom itinerary
- compare.php — side-by-side package comparison
- weather.php — destination weather guide
- emergency.php — emergency contacts & safety info
- reviews.php — traveler reviews & ratings
- wishlist.php — saved/favourited packages
- tour-history.php — the signed-in user's bookings
- enquiry.php — contact / send an enquiry to the team
- language.php — switch site language
- packing-assistant.php — AI packing checklist generator
- page.php?type=contact — contact us
- register.php / (sign-in modal already on every page) — create an account

CANCELLATION POLICY (state this accurately if asked):
30+ days before departure: full refund. 15–30 days: 50% refund. 7–15 days:
25% refund. Under 7 days: no refund. Exceptions go through enquiry.php.

HOW TO RESPOND:
- Keep replies short — 2–5 sentences, occasionally a short bullet list for
  multi-part answers. This is a small chat widget, not an essay.
- Be warm and genuinely helpful, not salesy or repetitive.
- When a page above is relevant, link it inline using the anchor format shown.
- Never inflate prices, invent packages, or promise something the site can't
  actually do (e.g. don't claim real-time human agents are on standby).
- If you don't know something specific (exact price of a particular package,
  live availability), say so honestly and point to the relevant page instead
  of guessing a number.
- Never discuss unrelated topics, other companies, or anything outside travel
  planning for TMS — politely redirect back to how you can help with their trip.
SYSTEM;
    }

    /**
     * Small, cheap, non-AI DB query to ground the system prompt in real
     * inventory counts/types rather than the model guessing what TMS sells.
     */
    private function getPackageBlurb(): string {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) as total,
                        SUM(PackageType LIKE '%Beach%' OR PackageType LIKE '%Island%') as beach,
                        SUM(PackageType LIKE '%Mountain%' OR PackageType LIKE '%Adventure%') as mountain,
                        SUM(PackageType LIKE '%Heritage%' OR PackageType LIKE '%Religious%') as heritage,
                        MIN(PackagePrice) as min_price, MAX(PackagePrice) as max_price
                 FROM tbltourpackages WHERE Status=1"
            )->fetch(PDO::FETCH_ASSOC);

            if (!$row || !$row['total']) {
                return "A growing catalog of domestic (Bangladesh) and international tour packages.";
            }

            $min = number_format((float)$row['min_price']);
            $max = number_format((float)$row['max_price']);

            return "{$row['total']} active packages spanning beach/island, mountain/adventure, "
                 . "and heritage/religious destinations across Bangladesh and abroad — "
                 . "priced roughly from ৳{$min} to ৳{$max} per person depending on the trip.";
        } catch (Exception $e) {
            return "A range of domestic (Bangladesh) and international tour packages.";
        }
    }
}
