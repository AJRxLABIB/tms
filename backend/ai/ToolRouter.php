<?php
/**
 * ToolRouter — decides, WITHOUT asking the model, which read-only tools to run
 * for a message. Small local models (3B-8B) are unreliable at free-form
 * function calling; routing by rule and letting the model only WRITE the
 * answer from the returned facts is more dependable and cheaper.
 */
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/BanglaNormalizer.php';
require_once __DIR__ . '/QueryParser.php';
require_once __DIR__ . '/FollowUp.php';
require_once __DIR__ . '/Banglish.php';
require_once __DIR__ . '/ConversationState.php';
require_once __DIR__ . '/ActionProposals.php';

final class ToolRouter
{
    /** @return array<int,array{tool:string,args:array}> */
    public static function plan(string $message, array $pageContext = [], array $recentIds = [], array $prices = []): array
    {
        return self::planWithState($message, $pageContext, $recentIds, $prices, [])['calls'];
    }

    /**
     * plan() plus explicit conversation state. $state = ConversationState::load() (+ optional 'ids'/'prices' are taken from the arguments).
     * @return array{calls:array<int,array{tool:string,args:array}>,note:string,state:array{search:array,focus:?int},refine:string}
     *   note: extra instruction for the model (resolved follow-up); state: what to save with ConversationState::save().
     */
    public static function planWithState(string $message, array $pageContext, array $recentIds, array $prices, array $state): array
    {
        $ref = FollowUp::refine($message, ['ids' => $recentIds, 'prices' => $prices, 'focus' => $state['focus'] ?? null, 'search' => $state['search'] ?? []]);
        if ($ref['intent'] === 'itinerary_edit') $ref['search'] = $state['search'] ?? [];   // facts for the edit come from the trip being discussed
        $calls = self::planCalls($message, $pageContext, $recentIds, $prices, $ref);
        $focus = $state['focus'] ?? null; $search = $state['search'] ?? [];
        foreach ($calls as $c) {
            if ($c['tool'] === 'search_packages') $search = ConversationState::cleanSearch($c['args'] + ['mode' => $ref['search']['mode'] ?? ($search['mode'] ?? null), 'travelers' => $ref['search']['travelers'] ?? ($search['travelers'] ?? null)]);
            if (in_array($c['tool'], ['get_package', 'get_cancellation_policy', 'calculate_trip_budget', 'check_booking_readiness'], true) && !empty($c['args']['package_id'])) $focus = (int)$c['args']['package_id'];
        }
        if (!empty($pageContext['package_id'])) $focus = (int)$pageContext['package_id'];
        return ['calls' => $calls, 'note' => $ref['note'], 'state' => ['search' => $search, 'focus' => $focus], 'refine' => $ref['intent']];
    }

    /** @return array<int,array{tool:string,args:array}> */
    private static function planCalls(string $message, array $pageContext, array $recentIds, array $prices, array $ref): array
    {
        $m = mb_strtolower($message, 'UTF-8'); $calls = [];
        // Banglish cues ("koto taka", "batil", "amar bukking") become the English cues the rules below look for. Original text is kept.
        $bh = Banglish::hints($message);
        if ($bh !== '') $m .= ' ' . $bh;
        $pkgId = isset($pageContext['package_id']) ? (int)$pageContext['package_id'] : 0;
        // References to the previous answer ("the second one", "compare them") are resolved by code from ids the server saved.
        $fu = FollowUp::resolve($message, $recentIds, $prices);
        if ($ref['intent'] !== 'none') $fu = ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => false];
        if ($fu['kind'] === 'compare') $calls[] = ['tool' => 'compare_packages', 'args' => ['package_ids' => implode(',', $fu['ids'])]];
        elseif ($fu['kind'] === 'single') $pkgId = (int)$fu['target'];

        // One specific booking by number ("status of booking 123", "BK45", "বুকিং ১২৩"): owner-only tool, identity from the session.
        $bkId = self::bookingNumber($message);
        if ($bkId !== null) {
            $calls[] = ['tool' => 'get_my_booking', 'args' => ['booking_id' => $bkId]];
        } elseif (preg_match('/\b(my (booking|trip|reservation)s?|my upcoming|am i booked)\b|আমার\s*(বুকিং|ট্রিপ|ভ্রমণ)/u', $m)) {
            $calls[] = ['tool' => 'my_bookings', 'args' => []];
        }
        $isAction = ActionProposals::detect($message) !== null;   // "cancel my booking 12": handled as a confirmable proposal, not a policy lookup
        if (preg_match('/\b(cancel|cancellation|refund)\b|বাতিল|ফেরত/u', $m) && $pkgId > 0) {
            $calls[] = ['tool' => 'get_cancellation_policy', 'args' => ['package_id' => $pkgId]];
        } elseif (!$isAction && $bkId === null && preg_match('/\b(cancellation polic|refund polic|cancel(?:ling|ing)? (?:a |my |the )?(?:trip|tour|booking|package|hotel)|refund)/u', $m)) {
            $calls[] = ['tool' => 'get_cancellation_info', 'args' => []];
        }
        // Hotels, activities and curated destination guides (read-only; demo rows are labelled by the tool, never priced).
        $entityPlanned = false;
        $hasPkgWords = (bool)preg_match('/\b(package|tour|trip)s?\b|প্যাকেজ|ট্যুর|ট্রিপ/u', $m);
        $entityQf = null;
        $entityArgs = function () use ($message, &$entityQf) {
            $entityQf ??= QueryParser::parse($message);
            $a = ['query' => mb_substr($message, 0, 200), 'limit' => 5];
            if (!empty($entityQf['max_price']) && $entityQf['max_price'] <= 10000000) $a['max_price'] = (int)$entityQf['max_price'];
            return $a;
        };
        if (preg_match('/\b(hotels?|resorts?|accommodation|guest ?house|lodging|where to stay|place to stay|motel)\b|হোটেল|রিসোর্ট|থাকার\s*জায়গা/u', $m)) {
            $a = $entityArgs();
            if (preg_match('/\b([1-5])[- ]?stars?\b|([১-৫])\s*স্টার/u', BanglaNormalizer::digits($m), $st)) $a['min_stars'] = (int)($st[1] !== '' ? $st[1] : BanglaNormalizer::digits($st[2]));
            $calls[] = ['tool' => 'search_hotels', 'args' => $a]; $entityPlanned = true;
        }
        if (preg_match('/\b(activit(?:y|ies)|things to do|adventure|snorkel(?:l?ing)?|diving|rafting|zip ?line|boat ride|jungle safari|trekking|hiking|day trip|kayak(?:ing)?)\b|অ্যাক্টিভিটি|অ্যাডভেঞ্চার|কী কী করা যায়/u', $m)) {
            $calls[] = ['tool' => 'search_activities', 'args' => $entityArgs()]; $entityPlanned = true;
        }
        if (preg_match('/\b(destination guide|best time to (?:visit|go|travel)|when (?:is|to) (?:the )?best|how (?:to|do i|can i) (?:get|reach) (?:to )?|getting (?:to|there)|permits?|what is .{2,40} known for|travel tips|know before)\b|কখন (?:যাওয়া|ঘোরা)|কীভাবে যাব|কিভাবে যাব|পারমিট|পরিচিত কীসের/u', $m)) {
            $calls[] = ['tool' => 'get_destination_guide', 'args' => ['query' => mb_substr($message, 0, 200)]]; $entityPlanned = true;
        }
        if (preg_match('/\b(visa|passport|entry (rule|requirement)s?|immigration|advisory|travel warning|strike|hartal|disruption|safe to (travel|visit)|is it safe|sustainab|eco[- ]?friendly|carbon)\b|ভিসা|পাসপোর্ট|হরতাল|নিরাপদ/u', $m)) {
            $a = ['query' => mb_substr($message, 0, 200)];
            if (!empty($pageContext['country']) && is_string($pageContext['country'])) $a['country'] = mb_substr($pageContext['country'], 0, 100);
            $calls[] = ['tool' => 'get_travel_notices', 'args' => $a];
        }
        // Budget ceiling parsed by code (Bangla digits, "50k", "১.৫ লাখ" ...), never by the model.
        // Budget, price floor, trip length and travellers are parsed by CODE (QueryParser), never by the model. An explicit TOTAL budget for N
        // travellers becomes a per-person ceiling because package prices are per person.
        $qf = QueryParser::parse($message);
        $max = QueryParser::perPersonCeiling($qf);
        // Cost / affordability question about the package the visitor is looking at -> deterministic budget tool.
        $d = BanglaNormalizer::digits($m);
        if ($pkgId > 0 && preg_match('/\b(total cost|how much (?:will|would|does|for)|can (?:we|i) afford|afford|enough|cost for|price for|within (?:my )?budget)\b|মোট খরচ|খরচ কত|কত পড়বে|কত লাগবে|বাজেটে হবে/u', $d)) {
            $trav = 1;
            if (preg_match('/(?:family of|group of|for|party of)\s*(\d{1,2})\b|(\d{1,2})\s*(?:people|persons?|travell?ers|adults|of us|জন)/u', $d, $t)) $trav = (int)($t[1] !== '' ? $t[1] : $t[2]);
            $a = ['travelers' => max(1, min(50, $trav)), 'package_id' => $pkgId];
            if ($qf['max_price'] !== null && $qf['max_price'] <= 1000000000) $a['budget_bdt'] = $qf['max_price'];
            $calls[] = ['tool' => 'calculate_trip_budget', 'args' => $a];
        }
        if ($pkgId > 0 && self::wantsBookingCheck($message)) $calls[] = ['tool' => 'check_booking_readiness', 'args' => self::bookingArgs($message, $pkgId)];
        $isCatalogue = preg_match('/\b(package|tour|trip|hotel|beach|hill|price|cheap|budget|family|honeymoon|visit|go to|best)\b|প্যাকেজ|ভ্রমণ|দাম|কক্সবাজার|সিলেট|সুন্দরবন|বান্দরবান/u', $m);
        // Hotel words have always led to a package search too (packages include stays; plural "hotels" used to reach it through the
        // no-tool fallback), so that is kept now that a hotel tool exists. Activity and guide questions do not need one.
        if (preg_match('/\b(hotels?|resorts?)\b|হোটেল|রিসোর্ট/u', $m)) $isCatalogue = true;
        if ($fu['kind'] === 'single' && !$calls) $calls[] = ['tool' => 'get_package', 'args' => ['package_id' => $pkgId]];
        $refined = ($ref['intent'] !== 'none' && !$ref['unresolved'] && ($ref['search'] || $ref['intent'] === 'refine_search'));
        if ($refined && !array_filter($calls, fn($c) => $c['tool'] === 'search_packages')) {
            // A resolved follow-up reuses the earlier search with the change applied (travellers/mode are state, not search parameters).
            $a = $ref['search']; unset($a['mode'], $a['travelers']);
            $a['query'] = ($a['query'] ?? '') !== '' ? $a['query'] : mb_substr($message, 0, 200);
            $a['limit'] = 5;
            $calls[] = ['tool' => 'search_packages', 'args' => $a];
        } elseif (($isCatalogue && $fu['kind'] === 'none') || !$calls) {
            $args = ['query' => mb_substr($message, 0, 200), 'limit' => 5];
            if ($max) $args['max_price'] = $max;
            if ($qf['min_price']) $args['min_price'] = $qf['min_price'];
            if ($qf['days_min']) { $args['days_min'] = $qf['days_min']; $args['days_max'] = $qf['days_max'] ?? $qf['days_min']; }
            $calls[] = ['tool' => 'search_packages', 'args' => $args];
        }
        return $calls;
    }

    /** Booking number written by the visitor ("booking 123", "BK45", "booking #12", "বুকিং ১২৩"); null when none. Parsed by code, never by the model. */
    public static function bookingNumber(string $message): ?int
    {
        $d = BanglaNormalizer::digits(mb_strtolower($message, 'UTF-8'));
        if (preg_match('/\b(?:booking|reservation|bk)\s*(?:id|no\.?|number)?\s*[:#-]?\s*(?:bk)?\s*(\d{1,9})\b|বুকিং\s*(?:নম্বর|আইডি|নং)?\s*[:#-]?\s*(\d{1,9})/u', $d, $m)) {
            $n = (int)($m[1] !== '' ? $m[1] : ($m[2] ?? 0));
            return $n > 0 ? $n : null;
        }
        return null;
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

    /** Plain-language notes about HOW a package search was filtered, so the answer can be honest about it. @return string[] */
    public static function searchNotes(ToolRegistry $reg, array $args, int $returned): array
    {
        $m = $reg->lastSearchMeta; $f = $m['filters'] ?? []; $n = [];
        $parts = [];
        if (isset($f['max_price'])) $parts[] = 'price at most ' . $f['max_price'] . ' BDT per person';
        if (isset($f['min_price'])) $parts[] = 'price at least ' . $f['min_price'] . ' BDT per person';
        if (isset($f['days_min'])) $parts[] = 'length ' . ($f['days_min'] === $f['days_max'] ? $f['days_min'] : $f['days_min'] . '-' . $f['days_max']) . ' days';
        if ($parts) $n[] = 'filters_applied: ' . implode('; ', $parts) . '. Package prices are per person; if the traveller\'s budget might be a group total, say which you assumed.';
        if (!empty($m['relaxed'])) $n[] = 'note: no package matches the requested ' . implode(' and ', $m['relaxed']) . ' exactly, so these are the closest by the other criteria. Say this plainly; do not present them as exact matches.';
        if (!empty($m['browse'])) $n[] = 'note: results are the best-rated packages that satisfy the filters (the request named no destination or theme).';
        if ($returned === 0) $n[] = 'note: no published package matches' . ($parts ? ' (' . implode('; ', $parts) . ')' : '') . '. Say none were found and suggest loosening the filters; do not invent packages or prices.';
        return $n;
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
            if ($c['tool'] === 'search_packages') foreach (self::searchNotes($reg, $c['args'], is_array($r['data']) ? count($r['data']) : 0) as $nt) $blocks[] = $nt;
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
