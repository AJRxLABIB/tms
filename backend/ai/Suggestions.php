<?php
/**
 * Suggestions — quick-reply chips built by CODE from what the turn actually produced (which tools ran, how many real packages came
 * back). Every phrase is one the router/follow-up resolver understands, so a click always does what its label says; the model's own
 * free-text chips only fill the remaining slots and are filtered.
 */
final class Suggestions
{
    private const TEXT = [
        'en' => [
            'compare' => 'Compare them', 'cheaper' => 'Which is the cheaper one?', 'about_first' => 'Tell me about the first one',
            'cancel_first' => 'Cancellation policy for the first one', 'seats_first' => 'Any seats left for the first one?',
            'best_rated' => 'Show best-rated packages', 'under_20k' => 'Packages under 20k', 'cost_first' => 'How much for 4 people for the first one?',
        ],
        'bn' => [
            'compare' => 'তুলনা করো', 'cheaper' => 'কোনটা সস্তা?', 'about_first' => 'প্রথম প্যাকেজ সম্পর্কে বলো',
            'cancel_first' => 'প্রথম প্যাকেজের বাতিলের নিয়ম', 'seats_first' => 'প্রথম প্যাকেজের সিট আছে?',
            'best_rated' => 'সেরা রেটিংয়ের প্যাকেজ দেখাও', 'under_20k' => '২০ হাজারের নিচে প্যাকেজ', 'cost_first' => 'প্রথম প্যাকেজে ৪ জনের খরচ কত?',
        ],
    ];

    /**
     * @param array<int,array{tool:string,ok?:bool}> $trace   ToolRouter::run() trace
     * @param array<int,array> $citations                      ToolRouter::run() citations (one per distinct real package shown)
     * @return string[] up to 4 chips
     */
    public static function build(array $trace, array $citations, string $lang = 'en'): array
    {
        $t = self::TEXT[$lang === 'bn' ? 'bn' : 'en'];
        $tools = array_column($trace, 'tool');
        $pk = count(array_filter($citations, fn($c) => str_starts_with((string)($c['id'] ?? ''), 'pkg:')));
        $out = [];
        if (in_array('compare_packages', $tools, true)) {
            $out = [$t['cancel_first'], $t['seats_first'], $t['cost_first']];
        } elseif (in_array('search_packages', $tools, true) && $pk >= 2) {
            $out = [$t['compare'], $t['cheaper'], $t['cancel_first'], $t['about_first']];
        } elseif ($pk === 1) {
            $out = [$t['cancel_first'], $t['seats_first'], $t['cost_first']];
        } elseif (in_array('search_packages', $tools, true)) {
            $out = [$t['best_rated'], $t['under_20k']];   // nothing matched: offer a way forward, never a claim
        }
        return $out;
    }

    /** Code chips first, then the model's (trimmed, short, no markup/URLs, de-duplicated), at most 4 in total. @return string[] */
    public static function merge(array $codeChips, array $modelChips): array
    {
        $out = $codeChips; $seen = array_map(fn($s) => mb_strtolower($s, 'UTF-8'), $out);
        foreach ($modelChips as $c) {
            if (count($out) >= 4) break;
            if (!is_string($c)) continue;
            $c = trim(strip_tags($c));
            if ($c === '' || mb_strlen($c) > 60 || preg_match('~https?://|www\.|[<>{}]~i', $c)) continue;
            $k = mb_strtolower($c, 'UTF-8');
            if (in_array($k, $seen, true)) continue;
            $seen[] = $k; $out[] = $c;
        }
        return array_slice($out, 0, 4);
    }
}
