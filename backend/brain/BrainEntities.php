<?php
require_once __DIR__ . '/BrainText.php';
require_once __DIR__ . '/BrainKnowledge.php';
require_once dirname(__DIR__) . '/ai/BanglaNormalizer.php';
require_once dirname(__DIR__) . '/ai/QueryParser.php';

/**
 * BrainEntities - pulls the facts out of a request: places, number of days, travellers, budget, month, interests, accommodation level.
 * Numbers, budgets and dates come from the existing QueryParser (English / Bangla / Banglish); this adds places, months and interests.
 */
final class BrainEntities
{
    /**
     * @return array{places:string[],days:?int,travelers:?int,budget:?int,month:?int,interests:string[],tier:?string,origin:?string}
     */
    public static function parse(string $text): array
    {
        $q = QueryParser::parse($text);
        $n = ' ' . BrainText::norm($text) . ' ';
        $found = BrainKnowledge::find($text, 3);
        $places = array_map(fn($h) => $h['id'], $found);

        // "from dhaka" / "from chattogram" -> origin (a known destination or a city name that follows 'from')
        $origin = null;
        if (preg_match('/\bfrom\s+([a-z\p{L}]+(?:\s+[a-z\p{L}]+)?)/u', mb_strtolower($text, 'UTF-8'), $m)) {
            $cand = BrainKnowledge::find($m[1], 1);
            if ($cand) { $origin = $cand[0]['id']; $places = array_values(array_diff($places, [$origin])); }
            else $origin = trim($m[1]);
        }

        $month = null; $mn = BrainKnowledge::global()['month_names'] ?? [];
        foreach (BrainText::tokens($text) as $w) { if (isset($mn[$w])) { $month = (int)$mn[$w]; break; } }
        if ($month === null) { foreach ($mn as $word => $num) { if (!ctype_alpha(str_replace(' ', '', (string)$word)) && mb_strpos($text, (string)$word) !== false) { $month = (int)$num; break; } } }

        $interests = [];
        foreach ((BrainKnowledge::global()['interest_words'] ?? []) as $tag => $words) {
            foreach ($words as $w) {
                $w = BrainText::norm((string)$w);
                if ($w !== '' && mb_strpos($n, ' ' . $w . ' ', 0, 'UTF-8') !== false) { $interests[$tag] = true; break; }
            }
        }

        $tier = null;
        if (preg_match('/\b(cheap|budget|low cost|affordable|economy|sosta)\b|সস্তা|কম খরচ/u', mb_strtolower($text, 'UTF-8'))) $tier = 'budget';
        elseif (preg_match('/\b(luxury|premium|5 star|five star|high end)\b|বিলাসবহুল/u', mb_strtolower($text, 'UTF-8'))) $tier = 'luxury';
        elseif (preg_match('/\b(mid range|midrange|comfortable|standard)\b/u', mb_strtolower($text, 'UTF-8'))) $tier = 'mid';

        $days = $q['days_max'] ?? $q['days_min'];
        return [
            'places' => $places, 'days' => $days !== null ? (int)$days : null, 'travelers' => $q['travelers'],
            'budget' => $q['max_price'], 'month' => $month, 'interests' => array_keys($interests), 'tier' => $tier, 'origin' => $origin,
        ];
    }
}
