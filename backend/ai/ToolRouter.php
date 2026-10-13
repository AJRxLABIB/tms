<?php
/**
 * ToolRouter — decides, WITHOUT asking the model, which read-only tools to run
 * for a message. Small local models (3B-8B) are unreliable at free-form
 * function calling; routing by rule and letting the model only WRITE the
 * answer from the returned facts is more dependable and cheaper.
 */
require_once __DIR__ . '/ToolRegistry.php';

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
        $max = null;
        if (preg_match('/\b(?:under|below|less than|within|max(?:imum)?|budget)\D{0,8}(\d[\d,\.]*)\s*(k|000)?\b/u', $m, $b)
            || preg_match('/(\d[\d,\.]*)\s*(হাজার|k)?\s*(?:টাকার\s*)?(?:এর\s*)?(?:নিচে|কম|মধ্যে)/u', $m, $b)) {
            $n = (float)str_replace([',', '.'], ['', ''], $b[1]);
            if (isset($b[2]) && ($b[2] === 'k' || $b[2] === 'হাজার') && $n < 1000) $n *= 1000;
            if ($n >= 500 && $n <= 10000000) $max = (int)$n;
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
    public static function run(ToolRegistry $reg, array $calls, array $ctx): array
    {
        $blocks = []; $cites = [];
        foreach ($calls as $c) {
            $r = $reg->execute($c['tool'], $c['args'], $ctx);
            if (!$r['ok']) { $blocks[] = $c['tool'] . ': unavailable (' . $r['error'] . ')'; continue; }
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
