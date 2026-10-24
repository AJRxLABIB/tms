<?php
/**
 * TripState — the structured, persisted state of one trip conversation (Trip Copilot).
 *
 * The copilot used to rely on re-reading a text briefing plus the last 10 chat turns. That loses what the traveller said earlier
 * ("under 50k", "we are four", "no hiking") as soon as it scrolls out of history. This keeps those facts in explicit fields,
 * each with a BASIS so the assistant (and the UI) never presents a guess as a fact:
 *   confirmed  taken from the booking record (destination, dates, party size, amount paid/charged)
 *   stated     said by the traveller in chat (budget, constraints, what-if party size)
 *   estimated  computed by the platform from a plan (not a quote, not a booking)
 * A stated value never overwrites a confirmed one: a different party size in chat is kept as a what-if.
 *
 * Pure functions (fromBooking / applyMessage / render) need no DB; load()/save() persist to tbl_trip_state (migration 034).
 */
require_once __DIR__ . '/BanglaNormalizer.php';

final class TripState
{
    private const MAX_CONSTRAINTS = 10;
    private const MAX_CONSTRAINT_LEN = 120;

    public static function empty(): array
    {
        return ['v' => 1, 'destination' => null, 'dates' => null, 'travellers' => null, 'travellers_whatif' => null, 'budget' => null,
                'confirmed_cost' => null, 'estimated_cost' => null, 'mode' => null, 'constraints' => [], 'updated' => null];
    }

    /** Authoritative fields from the booking row (+ saved itinerary's computed cost, if any). */
    public static function fromBooking(array $booking, ?array $existing = null): array
    {
        $s = array_merge(self::empty(), is_array($existing) ? $existing : []);
        $s['v'] = 1;
        $dest = trim((string)($booking['PackageLocation'] ?? ''));
        if ($dest !== '') $s['destination'] = ['value' => mb_substr($dest, 0, 100), 'basis' => 'confirmed'];
        $from = (string)($booking['FromDate'] ?? ''); $to = (string)($booking['ToDate'] ?? '');
        if ($from !== '') $s['dates'] = ['from' => mb_substr($from, 0, 30), 'to' => mb_substr($to, 0, 30), 'basis' => 'confirmed'];
        if (isset($booking['NoOfPersons']) && (int)$booking['NoOfPersons'] > 0) $s['travellers'] = ['value' => (int)$booking['NoOfPersons'], 'basis' => 'confirmed'];
        if (isset($booking['TotalCost']) && is_numeric($booking['TotalCost']) && (float)$booking['TotalCost'] > 0) $s['confirmed_cost'] = ['value' => (int)$booking['TotalCost'], 'basis' => 'confirmed'];
        $it = $booking['itinerary'] ?? null;
        if (is_array($it) && isset($it['computed_cost']['total']) && is_numeric($it['computed_cost']['total']))
            $s['estimated_cost'] = ['value' => (int)$it['computed_cost']['total'], 'basis' => 'estimated', 'verified_from_catalogue' => (int)($it['computed_cost']['verified_from_catalogue'] ?? 0)];
        if (is_array($it) && !empty($it['validation']['mode']) && $s['mode'] === null) $s['mode'] = ['value' => (string)$it['validation']['mode'], 'basis' => 'estimated'];
        return $s;
    }

    /** Deterministic read of one traveller message into the state. Stated facts only; nothing is inferred beyond keywords. */
    public static function applyMessage(array $state, string $message, ?int $now = null): array
    {
        $state = array_merge(self::empty(), $state);
        $m = trim(BanglaNormalizer::digits($message));
        if ($m === '') return $state;
        $low = mb_strtolower($m, 'UTF-8');
        $now = $now ?? time();

        $b = BanglaNormalizer::budgetCeiling($m);
        if ($b !== null && $b > 0 && $b < 100000000) $state['budget'] = ['value' => $b, 'basis' => 'stated'];

        if (preg_match('/\b(?:we are|we\'re|party of|group of|for|with)\s+(\d{1,2})\s*(?:people|persons|travell?ers|adults|of us|pax)\b/u', $low, $mm) || preg_match('/\b(\d{1,2})\s*(?:people|persons|travell?ers|pax)\b/u', $low, $mm)) {
            $n = (int)$mm[1];
            if ($n >= 1 && $n <= 50) {
                if (isset($state['travellers']['basis']) && $state['travellers']['basis'] === 'confirmed' && (int)$state['travellers']['value'] !== $n) $state['travellers_whatif'] = ['value' => $n, 'basis' => 'stated'];
                elseif (!isset($state['travellers']['value'])) $state['travellers'] = ['value' => $n, 'basis' => 'stated'];
            }
        }

        if (preg_match('/wheelchair|accessib|mobility|can\'?t walk|cannot walk|elderly/u', $low)) $state['mode'] = ['value' => 'accessible', 'basis' => 'stated'];
        elseif (preg_match('/\b(family|kids?|children|child|toddler|baby)\b/u', $low)) $state['mode'] = ['value' => 'family', 'basis' => 'stated'];
        elseif (preg_match('/relax|slow pace|take it easy|not too busy/u', $low)) $state['mode'] = ['value' => 'relaxed', 'basis' => 'stated'];

        $found = [];
        if (preg_match('/\b(vegetarian|vegan|halal|gluten[- ]free|no pork|no beef|nut allergy|allergic to [a-z ]{3,20})\b/u', $low, $c)) $found[] = trim($c[1]);
        if (preg_match('/\b(no (?:hiking|trekking|climbing|boat|boats|flying|long walks?|early mornings?|night travel))\b/u', $low, $c)) $found[] = trim($c[1]);
        if (preg_match('/\b(?:must|need to|have to) (?:be back|return|leave|arrive) (?:by|before|on) ([a-z0-9 :]{3,25})/u', $low, $c)) $found[] = 'must be back/leave by ' . trim($c[1]);
        foreach ($found as $text) {
            $text = mb_substr($text, 0, self::MAX_CONSTRAINT_LEN);
            foreach ($state['constraints'] as $e) if (($e['text'] ?? '') === $text) continue 2;
            $state['constraints'][] = ['text' => $text, 'basis' => 'stated', 'at' => gmdate('Y-m-d', $now)];
        }
        if (count($state['constraints']) > self::MAX_CONSTRAINTS) $state['constraints'] = array_slice($state['constraints'], -self::MAX_CONSTRAINTS);
        $state['updated'] = gmdate('c', $now);
        return $state;
    }

    /** Compact block for the system prompt. Basis labels are part of the text so the model keeps the distinction. */
    public static function render(array $s): string
    {
        $s = array_merge(self::empty(), $s);
        $l = [];
        if (!empty($s['destination']['value'])) $l[] = 'Destination: ' . $s['destination']['value'] . ' [confirmed by booking]';
        if (!empty($s['dates']['from'])) $l[] = 'Dates: ' . $s['dates']['from'] . ($s['dates']['to'] !== '' ? ' to ' . $s['dates']['to'] : '') . ' [confirmed by booking]';
        if (!empty($s['travellers']['value'])) $l[] = 'Travellers: ' . $s['travellers']['value'] . ' [' . $s['travellers']['basis'] . ']';
        if (!empty($s['travellers_whatif']['value'])) $l[] = 'What-if party size the traveller mentioned: ' . $s['travellers_whatif']['value'] . ' [stated, NOT the booked number]';
        if (!empty($s['budget']['value'])) $l[] = 'Budget the traveller mentioned: ' . number_format((int)$s['budget']['value']) . ' BDT [stated]';
        if (!empty($s['confirmed_cost']['value'])) $l[] = 'Amount on the booking: ' . number_format((int)$s['confirmed_cost']['value']) . ' BDT [confirmed]';
        if (!empty($s['estimated_cost']['value'])) $l[] = 'Planned extra spending estimate: ' . number_format((int)$s['estimated_cost']['value']) . ' BDT [estimated by the platform, not a quote]';
        if (!empty($s['mode']['value'])) $l[] = 'Travel style: ' . $s['mode']['value'] . ' [' . $s['mode']['basis'] . ']';
        foreach ($s['constraints'] as $c) $l[] = 'Constraint: ' . $c['text'] . ' [stated]';
        if (!$l) return '';
        return "TRIP STATE (structured; labels show where each value comes from):\n- " . implode("\n- ", $l)
             . "\nNever present an [estimated] or [stated] value as confirmed. If a stated value conflicts with a confirmed one, say so and ask.";
    }

    public static function load(PDO $db, int $bookingId, string $email): array
    {
        try {
            $q = $db->prepare('SELECT state_json FROM tbl_trip_state WHERE booking_id = ? AND user_email = ? LIMIT 1');
            $q->execute([$bookingId, $email]);
            $j = $q->fetchColumn();
            $a = $j ? json_decode((string)$j, true) : null;
            return is_array($a) ? array_merge(self::empty(), $a) : self::empty();
        } catch (Throwable $e) { return self::empty(); }      // table not migrated yet: behave as before
    }

    public static function save(PDO $db, int $bookingId, string $email, array $state): void
    {
        try {
            $db->prepare('INSERT INTO tbl_trip_state (booking_id, user_email, state_json, updated_at) VALUES (?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = NOW()')
               ->execute([$bookingId, $email, json_encode($state, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* best effort: the chat must never fail because state could not be saved */ }
    }
}
