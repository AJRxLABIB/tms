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
require_once __DIR__ . '/../ExploreService.php';

class TripCopilot {

    private AIClient $ai;
    private PDO      $db;
    private CacheService $cache;
    private ExploreService $explore;

    public function __construct(PDO $db, ?CacheService $cache = null) {
        $this->db    = $db;
        $this->ai    = new AIClient($db);
        // ensureTable=false: includes/config.php's own $tmsCache already migrates the shared table.
        $this->cache = $cache ?? new CacheService($db, false);
        // Round 100 (items 19/20 audit): reuses the exact same Open-Meteo/
        // Nominatim integration ItineraryPlanner already uses -- no second
        // weather implementation. See buildLiveWeatherLine() below for why.
        $this->explore = new ExploreService($db, $this->cache);
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

        // Parse structured response if AI returned JSON action
        $parsed = $this->parseStructuredReply($reply);

        // FIX (Round 109, caught live during this round's own QA): save the
        // PARSED, human-readable reply text -- not the raw $reply, which as
        // of this round's system-prompt change is normally a JSON envelope
        // like {"reply":"...","suggestions":[...],"action":"none"}. Saving
        // the raw envelope here was live-tested and found to leak straight
        // into two places: (1) getHistory()/copilot_history's replay on page
        // reload, which hands m.message straight to addMsg() with no JSON
        // parsing on the frontend -- a returning user would see a raw JSON
        // blob instead of the reply they actually read; and (2) this same
        // history is fed back as this class's own conversation context on
        // the NEXT chat() call (see $messages above), so the AI would start
        // being shown its own past raw JSON scaffolding as "what I said
        // before" instead of the clean reply -- degrading response quality
        // over a longer conversation instead of improving it.
        $this->saveMessage($bookingId, $email, 'user', $userMsg);
        $this->saveMessage($bookingId, $email, 'assistant', $parsed['reply']);

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
- For any question about current or near-term conditions (today, tomorrow, "should
  I bring an umbrella", etc.), trust the "Live weather right now" line if present —
  it's a real, fresh fetch. The separate "Weather outlook at trip-planning time"
  line, if also present, is only what the forecast looked like when the itinerary
  was first generated and can be outdated by now — don't treat it as current.

OUTPUT FORMAT (Round 109): respond with ONLY a single JSON object, no markdown
fences, no extra text before or after it, in exactly this shape:
{"reply": "<your conversational answer, following all rules above>", "suggestions": ["<quick reply 1>", "<quick reply 2>", "<quick reply 3>"], "action": "none"}

Rules for "suggestions":
- 0 to 3 short quick-reply options the traveler could tap to continue the
  conversation — each under ~6 words, phrased as something THEY would say
  (e.g. "What about tomorrow?", not "Ask about tomorrow's weather").
- They must be genuinely relevant follow-ups to what YOU just said in "reply"
  — never generic filler, never repeats of the user's own last message.
- If your reply already fully resolves the topic and no natural follow-up
  exists, return an empty array — do not invent suggestions just to fill 3 slots.
- Never suggest something you cannot actually help with.

"action" stays the literal string "none" — reserved for future use, not
consumed by the UI today; do not put anything else there.
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

        // Round 100 (items 19/20 -- context-grounding audit): this used to
        // be the ONLY weather signal the copilot ever had -- a snapshot
        // baked into the itinerary JSON once, at generation time, which can
        // be days or weeks old by the time someone actually asks "what
        // should I do tomorrow" mid-trip. Item 20's own grounding rule is
        // "real data -> validation -> structured context -> AI", and a
        // stale forecast presented as current is exactly the failure mode
        // that rule exists to prevent. This re-fetches a REAL, current
        // forecast every call (cheap: ExploreService's own weather cache,
        // ~20 min TTL, means a fast-moving conversation reuses one real
        // fetch rather than hitting Open-Meteo on every message) and is
        // added ALONGSIDE the original trip-planning outlook below, not in
        // place of it -- one is "what the forecast looked like when this
        // itinerary was planned", the other is "what it's actually like
        // right now" -- both are useful, and Rule 2 means the original
        // line is never removed.
        $liveWeather = $this->buildLiveWeatherLine($booking['PackageLocation'] ?? '');
        if ($liveWeather !== '') {
            $lines[] = $liveWeather;
        }

        $itinerary = $booking['itinerary'] ?? null;
        if (is_array($itinerary) && !empty($itinerary['days'])) {
            if (!empty($itinerary['weather_risk_analysis']['summary'])) {
                $lines[] = "Weather outlook at trip-planning time: " . $itinerary['weather_risk_analysis']['summary'];
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

    /**
     * Round 100 (items 19/20): a real, current weather line for the
     * copilot's system prompt, fetched fresh every call (subject to
     * ExploreService's own ~20-minute weather cache — see the call site's
     * comment above for why that's the right cadence). Fully fail-soft:
     * geocoding failure or a weather API miss just means no live-weather
     * line is added, same as ItineraryPlanner::buildWeatherContext()'s own
     * philosophy of degrading quietly rather than blocking the chat.
     */
    private function buildLiveWeatherLine(string $destination): string {
        if ($destination === '') return '';
        try {
            $geo = $this->explore->searchDestinations($destination, 1);
            if (empty($geo['success']) || empty($geo['results'][0])) return '';
            $wx = $this->explore->getWeather((float)$geo['results'][0]['lat'], (float)$geo['results'][0]['lng']);
            if (empty($wx['success']) || empty($wx['data'])) return '';
            $data = $wx['data'];

            $line = "Live weather right now in {$destination} (real, fetched just now): "
                  . "{$data['current']['temp']}°C (feels like {$data['current']['feels_like']}°C), "
                  . "{$data['current']['description']}, humidity {$data['current']['humidity']}%.";

            // daily[0] is today, daily[1] is tomorrow -- exactly what a
            // "what should I do tomorrow" question needs, real and current
            // rather than whatever the itinerary happened to say weeks ago.
            if (!empty($data['daily'][0])) {
                $d = $data['daily'][0];
                $line .= " Today: {$d['tmin']}–{$d['tmax']}°C, rain chance {$d['pop']}%.";
            }
            if (!empty($data['daily'][1])) {
                $d = $data['daily'][1];
                $line .= " Tomorrow: {$d['tmin']}–{$d['tmax']}°C, rain chance {$d['pop']}%.";
            }
            return $line;
        } catch (Exception $e) {
            error_log('[TMS] TripCopilot live weather fetch failed (non-fatal): ' . $e->getMessage());
            return '';
        }
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
        // FIX (Round 109): buildSystemPrompt() now asks the AI to always
        // return the {"reply","suggestions","action"} JSON envelope (real,
        // context-derived quick replies, replacing the old approach of only
        // ever getting plain text and guessing suggestions from keywords
        // after the fact). The parse here was upgraded alongside that prompt
        // change to use AIClient::parseJson() -- which strips ```json
        // markdown fences -- instead of a raw json_decode() that required
        // the response to start with a literal "{". Gemini frequently wraps
        // JSON output in a code fence even when explicitly told "no markdown
        // fences", so the old strict check would have silently rejected
        // well-formed compliant responses and always fallen through to the
        // keyword-stub path below -- exactly the kind of failure that would
        // have made this whole upgrade a no-op without anyone noticing.
        $data = $this->ai->parseJson($reply);
        if (is_array($data) && isset($data['reply']) && is_string($data['reply']) && $data['reply'] !== '') {
            $suggestions = is_array($data['suggestions'] ?? null) ? $data['suggestions'] : [];
            // Defense in depth: keep only non-empty strings, cap at 3 -- the
            // system prompt already asks for this shape, but a UI-facing
            // array should never trust an LLM's shape compliance blindly.
            $suggestions = array_values(array_filter(array_map(
                fn($s) => is_string($s) ? trim($s) : '',
                $suggestions
            ), fn($s) => $s !== ''));
            $suggestions = array_slice($suggestions, 0, 3);

            return [
                'reply'       => $data['reply'],
                'suggestions' => $suggestions,
                'action'      => 'none', // reserved for future use; not consumed by the UI today (see api handler / ai-copilot.php)
            ];
        }

        // Fail-soft fallback: the AI didn't return the requested JSON shape
        // (rare, but not impossible) -- same behavior as before this round,
        // so a non-compliant response still degrades to a usable reply with
        // best-effort keyword-based suggestions rather than breaking the chat.
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
