<?php
/**
 * TripCopilot.php — 24/7 AI Travel Companion
 *
 * This is the CORE feature: an AI assistant that becomes the user's
 * personal travel companion for the full duration of their trip.
 * 
 * Booking-aware: knows the user's itinerary, hotels, schedule.
 * Context-aware: knows current date/time relative to trip.
 * Proactive: can generate weather alerts, schedule adjustments, etc.
 */

require_once __DIR__ . '/AIClient.php';
require_once __DIR__ . '/../CacheService.php';

class TripCopilot {

    private AIClient $ai;
    private PDO      $db;
    private CacheService $cache;

    public function __construct(PDO $db, ?CacheService $cache = null) {
        $this->db    = $db;
        $this->ai    = new AIClient($db);
        // ensureTable=false: includes/config.php's own $tmsCache already migrates the shared table.
        $this->cache = $cache ?? new CacheService($db, false);
    }

    /**
     * Send a message to the Trip Copilot and get a response.
     * Maintains full conversation history for the booking.
     *
     * @param int    $bookingId  Active booking ID
     * @param string $userMsg    User's message
     * @param string $email      User email
     * @return array             ['reply'=>string, 'suggestions'=>array, 'action'=>string]
     */
    public function chat(int $bookingId, string $userMsg, string $email): array {
        $booking  = $this->getBookingContext($bookingId, $email);
        $history  = $this->getHistory($bookingId, 10); // last 10 turns
        $system   = $this->buildSystemPrompt($booking);

        // Build messages array with history
        $messages = [];
        foreach ($history as $h) {
            $messages[] = ['role' => $h->role, 'content' => $h->message];
        }
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        $result = $this->ai->call($system, $messages, '', $email, feature: 'trip_copilot');

        if (!$result['success']) {
            return [
                'reply'       => "I'm temporarily unavailable. For urgent assistance, call your emergency contact.",
                'suggestions' => [],
                'action'      => 'none',
            ];
        }

        $reply = $result['content'];

        // Save both sides of conversation
        $this->saveMessage($bookingId, $email, 'user', $userMsg);
        $this->saveMessage($bookingId, $email, 'assistant', $reply);

        // Parse structured response if AI returned JSON action
        $parsed = $this->parseStructuredReply($reply);

        return $parsed;
    }

    /**
     * Generate a proactive alert (weather, schedule change, reminder).
     * Called by a cron job or on page load.
     *
     * Cached for 10 minutes per booking (Phase 2b): the docblock above says
     * this can be called on every page load, and a fresh "proactive alert"
     * doesn't meaningfully change minute-to-minute, so calling Gemini again
     * every time someone views the page would burn API quota for no benefit
     * to the user. 10 minutes is short enough that a genuinely new
     * situation (e.g. weather turning) still surfaces promptly.
     */
    public function generateProactiveAlert(int $bookingId, string $email): ?array {
        $cacheKey = 'copilot_alert_' . $bookingId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $booking = $this->getBookingContext($bookingId, $email);
        if (!$booking) return null;

        $system = "You are a proactive travel assistant. Given trip context, generate ONE relevant proactive alert for the traveler RIGHT NOW. Return JSON: {\"type\":\"weather|reminder|tip|warning\",\"title\":\"<short>\",\"message\":\"<2-3 sentences>\",\"urgency\":\"low|medium|high\"}. No other text.";

        $userMsg = "Trip context: " . $this->buildTripBriefing($booking) . " Current time: " . date('Y-m-d H:i') . ". Generate a relevant proactive alert if warranted. If nothing urgent, return {\"type\":\"tip\",\"title\":\"Travel Tip\",\"message\":\"<general useful tip>\",\"urgency\":\"low\"}.";

        $result = $this->ai->ask($system, $userMsg, '', $email, feature: 'trip_copilot_alert');
        if (!$result['success']) return null;

        $alert = $this->ai->parseJson($result['content']);
        if ($alert) $this->cache->set($cacheKey, $alert, 600);
        return $alert;
    }

    /**
     * Get full conversation history for a booking.
     */
    public function getHistory(int $bookingId, int $limit = 50): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT role, message, created_at FROM tbl_copilot_sessions
                 WHERE booking_id = ? ORDER BY id DESC LIMIT " . (int)$limit
            );
            $stmt->execute([$bookingId]);
            return array_reverse($stmt->fetchAll(PDO::FETCH_OBJ));
        } catch (Exception $e) {
            error_log('[TMS] ' . __METHOD__ . ' failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if user has an active (upcoming or ongoing) booking.
     * Returns the most relevant booking for copilot activation.
     */
    public function getActiveCopilotBooking(string $email): ?object {
        try {
            $stmt = $this->db->prepare(
                "SELECT b.BookingId, b.BookingDate, b.NoOfPersons, b.TotalCost,
                        p.PackageName, p.PackageLocation, p.PackageDuration
                 FROM tblbooking b
                 JOIN tbltourpackages p ON p.PackageId = b.PackageId
                 WHERE b.UserEmail = ? AND b.status = 1
                 AND b.BookingDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY b.BookingDate ASC LIMIT 1"
            );
            $stmt->execute([$email]);
            return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
        } catch (Exception $e) {
            error_log('[TMS] ' . __METHOD__ . ' failed: ' . $e->getMessage());
            return null;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function buildSystemPrompt(?array $booking): string {
        $now = date('Y-m-d H:i');
        // Phase 2b — context compression: this used to be
        // json_encode($booking, JSON_PRETTY_PRINT), which re-sent the ENTIRE
        // booking row plus the full nested day-by-day itinerary (every
        // schedule item, every note, every cost line) as INPUT TOKENS on
        // *every single chat message* for the whole trip. For a detailed
        // multi-day itinerary that's a large, mostly-irrelevant payload
        // repeated dozens of times per conversation. buildTripBriefing()
        // condenses it to what a copilot actually needs turn-to-turn: the
        // trip shape, not a receipt. If the user asks about a specific day's
        // exact schedule, the AI still has the day titles/activity list to
        // work from and can ask a clarifying follow-up rather than needing
        // every cost/note field pre-loaded on every message.
        $tripInfo = $booking ? $this->buildTripBriefing($booking) : 'No active booking found for this user.';

        return <<<SYSTEM
You are the TMS Trip Copilot — a world-class personal travel companion, guide, and assistant.

Current datetime: {$now}

ACTIVE TRIP CONTEXT:
{$tripInfo}

Your personality: warm, knowledgeable, proactive, concise. You speak like a trusted local friend who happens to be a travel expert.

Capabilities:
- Answer questions about the destination (attractions, culture, food, transport)
- Adjust schedules and suggest alternatives
- Provide safety guidance and emergency info
- Give real-time recommendations based on time of day
- Help with translation tips, local customs, cultural etiquette
- Suggest nearby restaurants, shops, photo spots
- Help with common travel problems (flight delays, lost items, health issues)

Rules:
- Keep responses concise (2-4 sentences for casual queries, structured lists for plans)
- Be proactive: if the user mentions rain, immediately suggest indoor alternatives
- Always prioritize user safety
- If you don't know something specific, say so and suggest how to find out
- Never make up specific phone numbers or addresses you're not sure about
- The trip context above is a condensed summary, not the full itinerary receipt —
  if asked for exact per-item costs/notes you don't have, say you'll pull up the
  full itinerary rather than inventing specific numbers
SYSTEM;
    }

    /**
     * Condenses a booking + its full itinerary into a compact plain-text
     * briefing for the copilot's system prompt — see the token-usage note
     * in buildSystemPrompt() above for why this exists.
     */
    private function buildTripBriefing(array $booking): string {
        $lines = [];
        $lines[] = "Package: {$booking['PackageName']} in {$booking['PackageLocation']} "
                 . "({$booking['PackageDuration']}), booked for {$booking['NoOfPersons']} traveler(s), "
                 . "total cost {$booking['TotalCost']} BDT, trip date {$booking['BookingDate']}.";

        $itinerary = $booking['itinerary'] ?? null;
        if (is_array($itinerary) && !empty($itinerary['days'])) {
            if (!empty($itinerary['weather_risk_analysis']['summary'])) {
                $lines[] = "Weather outlook: " . $itinerary['weather_risk_analysis']['summary'];
            }
            $lines[] = "Day-by-day plan:";
            foreach ($itinerary['days'] as $day) {
                $activities = array_map(
                    fn($s) => ($s['time'] ?? '') . ' ' . ($s['activity'] ?? ''),
                    array_slice($day['schedule'] ?? [], 0, 4)
                );
                $lines[] = "  Day {$day['day']} ({$day['date']}) — {$day['title']}: " . implode('; ', array_filter($activities));
            }
            if (!empty($itinerary['hotel_recommendations'][0]['name'])) {
                $lines[] = "Staying at (or recommended): " . $itinerary['hotel_recommendations'][0]['name'];
            }
        } else {
            $lines[] = "No detailed AI itinerary generated yet for this booking — offer to help create one.";
        }

        return implode("\n", $lines);
    }

    private function getBookingContext(int $bookingId, string $email): ?array {
        try {
            $stmt = $this->db->prepare(
                "SELECT b.*, p.PackageName, p.PackageLocation, p.PackageDuration, p.PackageDescription
                 FROM tblbooking b
                 JOIN tbltourpackages p ON p.PackageId = b.PackageId
                 WHERE b.BookingId = ? AND b.UserEmail = ?"
            );
            $stmt->execute([$bookingId, $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            // Try to get saved itinerary
            $iStmt = $this->db->prepare("SELECT itinerary_json FROM tbl_ai_itineraries WHERE booking_id = ? LIMIT 1");
            $iStmt->execute([$bookingId]);
            $iRow = $iStmt->fetch(PDO::FETCH_OBJ);
            if ($iRow) {
                $row['itinerary'] = json_decode($iRow->itinerary_json, true);
            }

            return $row;
        } catch (Exception $e) {
            error_log('[TMS] ' . __METHOD__ . ' failed: ' . $e->getMessage());
            return null;
        }
    }

    private function saveMessage(int $bookingId, string $email, string $role, string $message): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO tbl_copilot_sessions (booking_id, user_email, role, message, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$bookingId, $email, $role, $message]);
        } catch (Exception $e) {}
    }

    private function parseStructuredReply(string $reply): array {
        // Check if AI returned a JSON action block
        if (str_starts_with(trim($reply), '{')) {
            $data = json_decode(trim($reply), true);
            if ($data && isset($data['reply'])) {
                return [
                    'reply'       => $data['reply'],
                    'suggestions' => $data['suggestions'] ?? [],
                    'action'      => $data['action'] ?? 'none',
                ];
            }
        }

        // Otherwise plain text response
        return [
            'reply'       => $reply,
            'suggestions' => $this->extractSuggestions($reply),
            'action'      => 'none',
        ];
    }

    private function extractSuggestions(string $reply): array {
        // Extract quick-reply suggestions based on context
        $suggestions = [];
        if (stripos($reply, 'weather') !== false) {
            $suggestions[] = 'What are indoor alternatives?';
            $suggestions[] = 'Will it clear up later?';
        }
        if (stripos($reply, 'restaurant') !== false || stripos($reply, 'food') !== false) {
            $suggestions[] = 'Show me directions';
            $suggestions[] = 'Any cheaper options?';
        }
        if (stripos($reply, 'hotel') !== false) {
            $suggestions[] = 'What time is check-in?';
            $suggestions[] = 'What facilities are available?';
        }
        return array_slice($suggestions, 0, 3);
    }
}
