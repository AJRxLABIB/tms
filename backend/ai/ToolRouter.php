<?php
/**
 * ToolRouter — decides, WITHOUT asking the model, which read-only tools to run
 * for a message. Small local models (3B-8B) are unreliable at free-form
 * function calling; routing by rule and letting the model only WRITE the
 * answer from the returned facts is more dependable and cheaper.
 */
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/BanglaNormalizer.php';

final class ToolRouter
{
    /** @return array<int,array{tool:string,args:array}> */
    public static function plan(string $message, array $pageContext = []): array
    {
        $m = mb_strtolower($message, 'UTF-8'); $calls = [];
        $pkgId = isset($pageContext['package_id']) ? (int)$pageContext['package_id'] : 0;

        if (preg_match('/\b(my (booking|trip|reservation)s?|my upcoming|am i booked)\b|আমার\s*(বুকিং|ট্রিপ|ভ্রমণ)/u', $m)) {
            $calls[] = ['tool' => 'my_bookings', 'args' => []];
        }
        if (preg_match('/\b(cancel|cancellation|refund)\b|বাতিল|ফেরত/u', $m) && $pkgId > 0) {
            $calls[] = ['tool' => 'get_cancellation_policy', 'args' => ['package_id' => $pkgId]];
        }
        if (preg_match('/\b(visa|passport|entry (rule|requirement)s?|immigration|advisory|travel warning|strike|hartal|disruption|safe to (travel|visit)|is it safe|sustainab|eco[- ]?friendly|carbon)\b|ভিসা|পাসপোর্ট|হরতাল|নিরাপদ/u', $m)) {
            $a = ['query' => mb_substr($message, 0, 200)];
            if (!empty($pageContext['country']) && is_string($pageContext['country'])) $a['country'] = mb_substr($pageContext['country'], 0, 100);
            $calls[] = ['tool' => 'get_travel_notices', 'args' => $a];
        }
        // Budget ceiling parsed by code (Bangla digits, "50k", "১.৫ লাখ" ...), never by the model.
        $max = BanglaNormalizer::budgetCeiling($message);
        // Cost / affordability question about the package the visitor is looking at -> deterministic budget tool.
        $d = BanglaNormalizer::digits($m);
        if ($pkgId > 0 && preg_match('/\b(total cost|how much (?:will|would|does|for)|can (?:we|i) afford|afford|enough|cost for|price for|within (?:my )?budget)\b|মোট খরচ|খরচ কত|কত পড়বে|কত লাগবে|বাজেটে হবে/u', $d)) {
            $trav = 1;
            if (preg_match('/(?:family of|group of|for|party of)\s*(\d{1,2})\b|(\d{1,2})\s*(?:people|persons?|travell?ers|adults|of us|জন)/u', $d, $t)) $trav = (int)($t[1] !== '' ? $t[1] : $t[2]);
            $a = ['travelers' => max(1, min(50, $trav)), 'package_id' => $pkgId];
            if (($b = BanglaNormalizer::budgetCeiling($message)) !== null && $b <= 1000000000) $a['budget_bdt'] = $b;
            $calls[] = ['tool' => 'calculate_trip_budget', 'args' => $a];
        }
        if ($pkgId > 0 && self::wantsBookingCheck($message)) $calls[] = ['tool' => 'check_booking_readiness', 'args' => self::bookingArgs($message, $pkgId)];
        $isCatalogue = preg_match('/\b(package|tour|trip|hotel|beach|hill|price|cheap|budget|family|honeymoon|visit|go to|best)\b|প্যাকেজ|ভ্রমণ|দাম|কক্সবাজার|সিলেট|সুন্দরবন|বান্দরবান/u', $m);
        if ($isCatalogue || !$calls) {
            $args = ['query' => mb_substr($message, 0, 200), 'limit' => 5];
            if ($max) $args['max_price'] = $max;
            $calls[] = ['tool' => 'search_packages', 'args' => $args];
        }
        return $calls;
    }

    /** True when the message asks whether/how a trip can be booked or whether seats/dates are available. */
    public static function wantsBookingCheck(string $message): bool
    {
        $d = BanglaNormalizer::digits(mb_strtolower($message, 'UTF-8'));
        return (bool)preg_match('/\b(can (?:we|i) book|available|availability|seats? (?:left|available)|any seats?|is it (?:open|bookable)|ready to book|book (?:it|this|for)|reserve)\b|বুক করতে পারব|সিট আছে|খালি আছে|পাওয়া যাবে/u', $d);
    }

    /**
     * Travellers and ISO dates parsed by CODE. Only unambiguous numeric dates (YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY) are accepted;
     * "next Friday" or "15 December" are never guessed (no year/locale assumptions) and are simply left out.
     * @return array<string,mixed>
     */
    public static function bookingArgs(string $message, int $pkgId): array
    {
        $d = BanglaNormalizer::digits(mb_strtolower($message, 'UTF-8'));
        $trav = 1;
        if (preg_match('/(?:family of|group of|for|party of)\s*(\d{1,2})\b|(\d{1,2})\s*(?:people|persons?|travell?ers|adults|of us|জন)/u', $d, $t)) $trav = (int)($t[1] !== '' ? $t[1] : $t[2]);
        $a = ['package_id' => $pkgId, 'travelers' => max(1, min(50, $trav))];
        $dates = [];
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b|\b(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})\b/', $d, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $dates[] = ($x[1] ?? '') !== '' ? sprintf('%04d-%02d-%02d', $x[1], $x[2], $x[3]) : sprintf('%04d-%02d-%02d', $x[6], $x[5], $x[4]);
        }
        if (isset($dates[0])) $a['start_date'] = $dates[0];
        if (isset($dates[1])) $a['end_date'] = $dates[1];
        return $a;
    }

    /** Hard bounds for one assistant turn (also the answer to "can it loop?": no). */
    public const MAX_STEPS = 4;
    public const DEADLINE_SEC = 8.0;

    /**
     * Dependent follow-up steps, decided by CODE from a completed step's result (never by the model).
     * Only fires for a catalogue search that returned real packages, and only for read-only tools.
     * @return array<int,array{tool:string,args:array}>
     */
    public static function followUps(string $message, array $call, array $data, array $done): array
    {
        if ($call['tool'] !== 'search_packages' || !is_array($data) || !$data || !isset($data[0]['id'])) return [];
        $top = $data[0]; $out = [];
        $d = BanglaNormalizer::digits(mb_strtolower($message, 'UTF-8'));
        $has = fn(string $t) => in_array($t, array_column($done, 'tool'), true);
        $cost = preg_match('/\b(total cost|how much|can (?:we|i) afford|afford|cost for|price for|within (?:my )?budget)\b|মোট খরচ|খরচ কত|কত পড়বে|কত লাগবে|বাজেটে হবে/u', $d);
        if ($cost && !$has('calculate_trip_budget')) {
            $trav = 1;
            if (preg_match('/(?:family of|group of|for|party of)\s*(\d{1,2})\b|(\d{1,2})\s*(?:people|persons?|travell?ers|adults|of us|জন)/u', $d, $t)) $trav = (int)($t[1] !== '' ? $t[1] : $t[2]);
            $a = ['travelers' => max(1, min(50, $trav)), 'package_id' => (int)$top['id']];
            if (($b = BanglaNormalizer::budgetCeiling($message)) !== null && $b <= 1000000000) $a['budget_bdt'] = $b;
            $out[] = ['tool' => 'calculate_trip_budget', 'args' => $a];
        }
        if (self::wantsBookingCheck($message) && !$has('check_booking_readiness')) $out[] = ['tool' => 'check_booking_readiness', 'args' => self::bookingArgs($message, (int)$top['id'])];
        $safety = preg_match('/\b(visa|passport|advisory|safe to (?:travel|visit)|is it safe|strike|hartal|disruption)\b|ভিসা|পাসপোর্ট|হরতাল|নিরাপদ/u', mb_strtolower($message, 'UTF-8'));
        if ($safety && !$has('get_travel_notices') && !empty($top['country']) && is_string($top['country'])) {
            $out[] = ['tool' => 'get_travel_notices', 'args' => ['query' => mb_substr($message, 0, 200), 'country' => mb_substr($top['country'], 0, 100)]];
        }
        return $out;
    }

    /**
     * Run the plan and render a compact, untrusted-wrapped FACTS block + citations.
     * Bounded multi-step: at most MAX_STEPS tool calls and DEADLINE_SEC seconds per turn, identical calls are never repeated,
     * and follow-ups come only from followUps() (read-only tools). Returns a 'trace' for audit/analytics.
     * @param string[] $exclude lower-case terms the user asked never to be shown (TravelMemory 'destination_avoid'); applied in code, not by the model
     */
    public static function run(ToolRegistry $reg, array $calls, array $ctx, array $exclude = [], ?string $message = null, int $maxSteps = self::MAX_STEPS, float $deadlineSec = self::DEADLINE_SEC): array
    {
        $blocks = []; $cites = []; $queue = $calls; $done = []; $seen = []; $trace = [];
        $t0 = microtime(true); $limited = null;
        while ($queue) {
            if (count($done) >= $maxSteps) { $limited = 'step_limit'; break; }
            if (microtime(true) - $t0 > $deadlineSec) { $limited = 'deadline'; break; }
            $c = array_shift($queue);
            $key = $c['tool'] . '|' . json_encode($c['args']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $s0 = microtime(true);
            $r = $reg->execute($c['tool'], $c['args'], $ctx);
            $done[] = $c;
            $trace[] = ['step' => count($done), 'tool' => $c['tool'], 'ok' => (bool)$r['ok'], 'ms' => (int)round((microtime(true) - $s0) * 1000), 'follow_up' => count($done) > count($calls)];
            if (!$r['ok']) { $blocks[] = $c['tool'] . ': unavailable (' . $r['error'] . ')'; continue; }
            if ($exclude && $c['tool'] === 'search_packages' && is_array($r['data'])) {
                $before = count($r['data']);
                $r['data'] = array_values(array_filter($r['data'], function ($p) use ($exclude) {
                    $hay = mb_strtolower(($p['title'] ?? '') . ' ' . ($p['location'] ?? '') . ' ' . ($p['country'] ?? ''), 'UTF-8');
                    foreach ($exclude as $t) if ($t !== '' && mb_strpos($hay, $t) !== false) return false;
                    return true;
                }));
                if (count($r['data']) < $before) $blocks[] = 'note: ' . ($before - count($r['data'])) . ' matching package(s) were hidden because the user asked not to be shown them; do not mention or suggest them.';
            }
            $blocks[] = $c['tool'] . ': ' . json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($message !== null) foreach (self::followUps($message, $c, $r['data'], $done) as $f) $queue[] = $f;
            $walk = function ($v) use (&$walk, &$cites) {
                if (is_array($v)) { if (isset($v['citation']['id'])) $cites[$v['citation']['id']] = $v['citation']; foreach ($v as $x) $walk($x); }
            };
            $walk($r['data']);
        }
        require_once __DIR__ . '/InjectionGuard.php';
        if ($limited) $blocks[] = 'note: the lookup was stopped early (' . $limited . '); answer only from the facts above and say anything else could not be checked.';
        return ['facts' => InjectionGuard::wrapUntrusted('RETRIEVED FACTS', implode("\n", $blocks)), 'citations' => array_values($cites), 'trace' => $trace, 'stopped' => $limited];
    }
}
