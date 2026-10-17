<?php
/**
 * QueryParser - deterministic understanding of a travel request in English, Bangla, Banglish (Bangla in Latin letters) or a mix.
 *
 * Money, dates and counts must not depend on a language model, so this is plain code. One pass finds EVERY number in the text, drops the
 * ones that are not money (days, nights, travellers, percentages), then classifies the rest by the words around them:
 *   max_price   "under 50k", "within 1.5 lakh", "cheaper than 40000", "family of 4 tk 60000 max", "১০ হাজারের নিচে", "50 hajar takar moddhe"
 *   min_price   "over 20k", "at least 30000", "50 hajar er upore"
 *   range       "20k-30k", "between 20 and 30 thousand", "20000 to 30000", "২০ হাজার থেকে ৩০ হাজার"
 *   days        "3 days", "3-4 days", "5 din", "৩ দিন", "weekend" (2-3), "a week" (7). Nights are read as days (same as NLSearchParser).
 *   travelers   "for 4 people", "family of 4", "৪ জন", "couple" (2), "solo" (1), "2 adults and 2 children" (4)
 *   season      winter / monsoon / summer / autumn / spring (+ Bangla and common Banglish) - REPORTED ONLY: packages carry no season data,
 *               so nothing is filtered by it.
 *   basis       'total' only when the user says so explicitly ("50k total", "in total", "মোট"); 'per_person' when explicit; else 'unspecified'.
 * It never guesses: a bare number with no currency word, multiplier or cue is NOT treated as a price, amounts under 500 taka are not a trip
 * budget, and "next friday" style dates are not interpreted.
 */
require_once __DIR__ . "/BanglaNormalizer.php";

final class QueryParser
{
    private const MULT = ['k' => 1000, 'thousand' => 1000, 'hajar' => 1000, 'hazar' => 1000, 'hajaar' => 1000, 'হাজার' => 1000,
                          'lakh' => 100000, 'lakhs' => 100000, 'lac' => 100000, 'lokkho' => 100000, 'laksh' => 100000, 'লাখ' => 100000, 'লক্ষ' => 100000,
                          'crore' => 10000000, 'koti' => 10000000, 'কোটি' => 10000000];
    private const WORDNUM = ['ek' => 1, 'এক' => 1, 'dui' => 2, 'দুই' => 2, 'tin' => 3, 'তিন' => 3, 'char' => 4, 'চার' => 4, 'pach' => 5, 'panch' => 5, 'পাঁচ' => 5,
                             'choy' => 6, 'chhoy' => 6, 'ছয়' => 6, 'saat' => 7, 'sat' => 7, 'সাত' => 7, 'aat' => 8, 'আট' => 8, 'noy' => 9, 'নয়' => 9, 'dosh' => 10, 'dos' => 10, 'দশ' => 10];
    private const MULT_RE = 'k|thousand|hajaar|hajar|hazar|হাজার|lakhs?|lac|lokkho|laksh|লাখ|লক্ষ|crore|koti|কোটি';
    private const CUR_RE = 'taka|takar|takay|tk|bdt|টাকা|টাকার|টাকায়|৳';
    private const UNIT_AFTER = '/^\s*-?\s*(?:days?|nights?|din|দিন|রাত|people|persons?|travell?ers?|adults?|pax|jon|জন|kids?|children|child|%|percent|stars?|hours?|km|kg|nos?)\b/u';

    /** @return array{max_price:?int,min_price:?int,days_min:?int,days_max:?int,travelers:?int,season:?string,basis:string,amounts:int[]} */
    public static function parse(string $text): array
    {
        $t = mb_strtolower(BanglaNormalizer::digits($text), 'UTF-8');
        $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t);
        $out = ['max_price' => null, 'min_price' => null, 'days_min' => null, 'days_max' => null, 'travelers' => null, 'season' => null, 'basis' => 'unspecified', 'amounts' => []];

        // ── days ──
        if (preg_match('/(\d{1,2})\s*(?:-|–|to|থেকে)\s*(\d{1,2})\s*[- ]?(?:days?|nights?|din|দিন|রাত)\b/u', $t, $m) && (int)$m[1] <= (int)$m[2] && (int)$m[2] <= 60) {
            $out['days_min'] = (int)$m[1]; $out['days_max'] = (int)$m[2];
        } elseif (preg_match('/(\d{1,2})\s*-?\s*(?:days?|nights?|din|দিন|রাত)\b/u', $t, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 60) {
            $out['days_min'] = $out['days_max'] = (int)$m[1];
        } elseif (preg_match('/(\d{1,2})\s*-?\s*(?:weeks?|সপ্তাহ|shoptaho)\b/u', $t, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 8) {
            $out['days_min'] = $out['days_max'] = (int)$m[1] * 7;
        } elseif (preg_match('/\b(?:a|one)\s+week\b|\bweek[- ]?long\b|এক\s*সপ্তাহ/u', $t)) {
            $out['days_min'] = $out['days_max'] = 7;
        } elseif (preg_match('/\bweekend\b|সপ্তাহান্ত|উইকেন্ড/u', $t)) {
            $out['days_min'] = 2; $out['days_max'] = 3;
        }

        // ── travellers ──
        if (preg_match('/(\d{1,2})\s*(?:adults?|people|persons?|travell?ers?|pax|jon|জন)\b(?:\s*(?:and|&|\+)\s*(\d{1,2})\s*(?:kids?|children|child))?/u', $t, $m)) {
            $out['travelers'] = (int)$m[1] + (int)($m[2] ?? 0);
        } elseif (preg_match('/(?:family of|group of|party of|for|couple of|we are|amra)\s*(\d{1,2})\b(?!\s*(?:days?|nights?|din|দিন|রাত|k\b|thousand|hajar|lakh|%))/u', $t, $m)) {
            $out['travelers'] = (int)$m[1];
        } elseif (preg_match('/\b(?:couple|honeymoon|duo)\b|দম্পতি|হানিমুন/u', $t)) {
            $out['travelers'] = 2;
        } elseif (preg_match('/\bsolo\b|\balone\b|একা/u', $t)) {
            $out['travelers'] = 1;
        }
        if ($out['travelers'] !== null && ($out['travelers'] < 1 || $out['travelers'] > 50)) $out['travelers'] = null;

        // ── season (reported only) ──
        foreach (['winter' => '/\bwinter\b|শীত|\bshit(?:e|er)?\b/u', 'monsoon' => '/\bmonsoon\b|\brainy\b|বর্ষা|borsha/u', 'summer' => '/\bsummer\b|গ্রীষ্ম|grishmo/u',
                  'autumn' => '/\bautumn\b|\bfall\b|শরৎ|shorot/u', 'spring' => '/\bspring\b|বসন্ত|boshonto/u'] as $name => $re) {
            if (preg_match($re, $t)) { $out['season'] = $name; break; }
        }

        // ── basis ──
        if (preg_match('/\b(?:in total|total budget|total of|all together|altogether|overall)\b|\btotal\b|মোট/u', $t)) $out['basis'] = 'total';
        if (preg_match('/\b(?:per person|per head|each|p\/p|pp|per jon|prottek|jonoprotti)\b|জনপ্রতি|প্রতি জন/u', $t)) $out['basis'] = 'per_person';

        // ── amounts ──
        $words = implode('|', array_map('preg_quote', array_keys(self::WORDNUM)));
        $re = '/(?<![\p{L}\p{N}.])(?:(\d[\d,]*(?:\.\d+)?)|(' . $words . '))\s*(' . self::MULT_RE . ')?(?:ের|এর|er|র)?(?:\s*(' . self::CUR_RE . '))?(?![\p{L}\p{N}])/u';
        if (!preg_match_all($re, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $out;
        $items = [];
        foreach ($mm as $x) {
            $raw = $x[1][0] ?? ''; $word = $x[2][0] ?? ''; $mult = $x[3][0] ?? ''; $cur = $x[4][0] ?? '';
            if ($raw === '' && ($word === '' || $mult === '')) continue;       // a number word is only an amount with a multiplier ("ek lakh")
            $start = $x[0][1]; $end = $start + strlen($x[0][0]);
            // mb_strcut, not substr: cutting a multi-byte Bangla character in half makes every later /u regex fail silently
            $after = mb_strcut($t, $end, 60, 'UTF-8'); $before = mb_strcut($t, max(0, $start - 60), min(60, $start), 'UTF-8');
            $hasMark = $mult !== '' || $cur !== '' || preg_match('/(?:৳|(?:^|\s)(?:tk|bdt|taka|টাকা)\s*)$/u', $before);
            if (!$hasMark && (preg_match(self::UNIT_AFTER, $after) || preg_match('/(?:family of|group of|party of|couple of|we are|amra|for)\s*$/u', $before))) continue;
            $val = $raw !== '' ? (float)str_replace(',', '', $raw) : (float)self::WORDNUM[$word];
            $factor = $mult !== '' ? self::MULT[$mult] : 1;
            $items[] = ['v' => (int)round($val * $factor), 'raw' => $val, 'hasMult' => $mult !== '', 'mark' => (bool)$hasMark, 'start' => $start, 'end' => $end, 'before' => $before, 'after' => $after];
        }
        // "20 to 30 thousand": the first number inherits the second one's multiplier
        for ($i = 0; $i + 1 < count($items); $i++) {
            $gap = mb_strcut($t, $items[$i]['end'], $items[$i + 1]['start'] - $items[$i]['end'], 'UTF-8');
            if (preg_match('/^\s*(?:-|–|—|to|থেকে|theke|and|&)\s*$/u', $gap)) {
                $a = &$items[$i]; $b = &$items[$i + 1];
                if (!$a['hasMult'] && $b['hasMult'] && $a['raw'] < 1000) { $a['v'] = (int)round($a['raw'] * ($b['v'] / max(1, $b['raw']))); $a['hasMult'] = true; $a['mark'] = true; }
                $a['range'] = $i + 1; unset($a, $b);
            }
        }
        // A bare 4+ digit number is a budget only when the sentence is clearly about money (explicit total/per-person, or budget/cost/price words).
        $loose = $out['basis'] !== 'unspecified' || (bool)preg_match('/\b(?:budget|cost|price|spend|afford|expensive|cheap)\b|খরচ|বাজেট|দাম/u', $t);
        $maxes = []; $maxCue = []; $mins = []; $skip = [];
        $maxBefore = '/(?:under|below|less than|within|upto|up to|max(?:imum)?|cheaper than|not more than|no more than|at most|budget(?: of| is)?|around|about|ceiling|limit|bajet|বাজেট|সর্বোচ্চ)\W{0,10}(?:rs|tk|bdt|taka|৳)?\W{0,3}$/u';
        $maxAfter  = '/^\s*(?:(?:' . self::CUR_RE . ')\s*)?(?:(?:er|এর|র)\s*)?(?:niche|nichey|নিচে|kom\b|কম|moddhe|modhye|মধ্যে|max(?:imum)?\b|or less|or under|or below|e hobe|a hobe|বাজেটে|budget)/u';
        $minBefore = '/(?:over|above|more than|at least|minimum|min\b|starting(?: from| at)?|from|beshi|বেশি)\W{0,10}(?:rs|tk|bdt|taka|৳)?\W{0,3}$/u';
        $minAfter  = '/^\s*(?:(?:' . self::CUR_RE . ')\s*)?(?:(?:er|এর|র)\s*)?(?:upore|upor|উপরে|beshi|বেশি|or more|plus\b|\+|and above|and up)/u';
        foreach ($items as $i => $it) {
            if (isset($skip[$i])) continue;
            $v = $it['v']; if ($v < 500 || $v > 1000000000) continue;
            $out['amounts'][] = $v;
            if (isset($it['range'])) {
                $j = $it['range']; $w = $items[$j]['v'];
                if ($w >= 500 && $w <= 1000000000) { $mins[] = min($v, $w); $maxCue[] = max($v, $w); $skip[$j] = true; $out['amounts'][] = $w; continue; }
            }
            if (preg_match($maxBefore, $it['before']) || preg_match($maxAfter, $it['after'])) { $maxCue[] = $v; continue; }
            if (preg_match($minBefore, $it['before']) || preg_match($minAfter, $it['after'])) { $mins[] = $v; continue; }
            if ($it['mark'] || ($loose && $v >= 1000)) $maxes[] = $v;                                     // "20k for 3 days", "tk 60000": a stated sum with no cue is a budget
        }
        $out['max_price'] = $maxCue ? min($maxCue) : ($maxes ? min($maxes) : null);
        $out['min_price'] = $mins ? max($mins) : null;
        if ($out['max_price'] !== null && $out['min_price'] !== null && $out['min_price'] > $out['max_price']) { $out['min_price'] = null; }
        return $out;
    }

    /** Per-person ceiling: converts an explicit TOTAL budget for N travellers to a per-person figure (package prices are per person). */
    public static function perPersonCeiling(array $q): ?int
    {
        if ($q['max_price'] === null) return null;
        if ($q['basis'] === 'total' && ($q['travelers'] ?? 0) > 1) return intdiv($q['max_price'], (int)$q['travelers']);
        return $q['max_price'];
    }
}
