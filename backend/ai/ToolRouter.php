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
        $isCatalogue = preg_match('/\b(package|tour|trip|hotel|beach|hill|price|cheap|budget|family|honeymoon|visit|go to|best)\b|প্যাকেজ|ভ্রমণ|দাম|কক্সবাজার|সিলেট|সুন্দরবন|বান্দরবান/u', $m);
        if ($isCatalogue || !$calls) {
            $args = ['query' => mb_substr($message, 0, 200), 'limit' => 5];
            if ($max) $args['max_price'] = $max;
            $calls[] = ['tool' => 'search_packages', 'args' => $args];
        }
        return $calls;
    }

    /** Run the plan and render a compact, untrusted-wrapped FACTS block + citations. */
    /** @param string[] $exclude lower-case terms the user asked never to be shown (TravelMemory 'destination_avoid'); applied in code, not by the model */
    public static function run(ToolRegistry $reg, array $calls, array $ctx, array $exclude = []): array
    {
        $blocks = []; $cites = [];
        foreach ($calls as $c) {
            $r = $reg->execute($c['tool'], $c['args'], $ctx);
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
            $walk = function ($v) use (&$walk, &$cites) {
                if (is_array($v)) { if (isset($v['citation']['id'])) $cites[$v['citation']['id']] = $v['citation']; foreach ($v as $x) $walk($x); }
            };
            $walk($r['data']);
        }
        require_once __DIR__ . '/InjectionGuard.php';
        return ['facts' => InjectionGuard::wrapUntrusted('RETRIEVED FACTS', implode("\n", $blocks)), 'citations' => array_values($cites)];
    }
}
