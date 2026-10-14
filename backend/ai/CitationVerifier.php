<?php
/**
 * CitationVerifier — checks a model answer against what the tools actually returned, in code.
 *
 *  - Money figures (a number next to ৳ / Tk / BDT / taka / টাকা) must come from the retrieved facts or from
 *    what the user themselves wrote. A figure that appears in neither is reported as "unverified" and the
 *    answer gets a plain caution line. We do not silently rewrite numbers and we do not regenerate.
 *    A small whole multiple (x2..x20) of a verified amount is accepted as per-person -> group arithmetic.
 *  - [pkg:N] style markers that were not in the retrieved citations are removed (a model can invent them).
 *  - "sources" are only ever taken from retrieved citations (so every link is a real, published package),
 *    and only those whose title the answer actually mentions.
 *
 * This is a consistency check against our own data. It cannot tell whether a prose claim is true.
 */
require_once __DIR__ . '/BanglaNormalizer.php';

final class CitationVerifier
{
    private const NUM = '(\d[\d,]*(?:\.\d+)?)';
    private const MULT = '(?:k|thousand|হাজার|lakhs?|lac|লাখ|লক্ষ|crore|কোটি)';
    private const CUR = '(?:৳|bdt|tk\.?|taka|টাকা|টাকার)';

    /** @return int[] whole-taka amounts that sit next to a currency marker */
    public static function pricedAmounts(string $text): array
    {
        $t = mb_strtolower(BanglaNormalizer::digits($text), 'UTF-8'); $out = [];
        $n = self::NUM . '\s*(' . self::MULT . ')?';
        if (preg_match_all('/' . self::CUR . '\s*' . $n . '(?![\p{L}\p{N}])/u', $t, $m, PREG_SET_ORDER))
            foreach ($m as $x) if (($v = BanglaNormalizer::toTaka($x[1], $x[2] ?? null)) !== null) $out[] = $v;
        if (preg_match_all('/' . $n . '\s*' . self::CUR . '/u', $t, $m, PREG_SET_ORDER))
            foreach ($m as $x) if (($v = BanglaNormalizer::toTaka($x[1], $x[2] ?? null)) !== null) $out[] = $v;
        return array_values(array_unique($out));
    }

    /**
     * @param array<int,array{id:string,title:string,url:string}> $citations
     * @return array{reply:string,unverified_amounts:int[],removed_markers:int,sources:array,checked:int}
     */
    public static function verify(string $reply, string $facts, array $citations, string $userText = '', string $language = 'en'): array
    {
        // Grounding set: every number in the facts and in the user's own words (digits normalised, commas removed).
        $ground = [];
        foreach ([$facts, $userText] as $src) {
            $s = BanglaNormalizer::digits($src);
            if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', $s, $m)) foreach ($m[0] as $x) { $v = (int)round((float)str_replace(',', '', $x)); if ($v > 0) $ground[$v] = true; }
            foreach (BanglaNormalizer::amounts($src) as $v) $ground[$v] = true;
        }
        $claimed = self::pricedAmounts($reply); $bad = [];
        foreach ($claimed as $v) {
            if (isset($ground[$v])) continue;
            $ok = false;
            foreach (array_keys($ground) as $g) if ($g >= 500 && $v % $g === 0 && $v / $g <= 20) { $ok = true; break; }
            if (!$ok) $bad[] = $v;
        }

        // Invented citation markers.
        $known = []; foreach ($citations as $c) $known[$c['id']] = true;
        $removed = 0;
        $reply = preg_replace_callback('/\s?\[(pkg:\d+)\]/u', function ($m) use ($known, &$removed) { if (isset($known[$m[1]])) return ''; $removed++; return ''; }, $reply) ?? $reply;

        if ($bad) {
            $note = $language === 'bn'
                ? "\n\n_(সতর্কতা: এই উত্তরের কিছু দাম আমাদের ক্যাটালগ থেকে যাচাই করা যায়নি। বুকিং করার আগে প্যাকেজ পেজে দাম মিলিয়ে নিন।)_"
                : "\n\n_(Note: some prices in this answer could not be verified against our catalogue. Please confirm them on the package page before booking.)_";
            $reply .= $note;
        }

        $lower = mb_strtolower($reply, 'UTF-8'); $sources = [];
        foreach ($citations as $c) {
            if (count($sources) >= 3) break;
            if (!empty($c['title']) && mb_strpos($lower, mb_strtolower($c['title'], 'UTF-8')) !== false)
                $sources[] = ['title' => (string)$c['title'], 'url' => (string)$c['url']];
        }
        return ['reply' => $reply, 'unverified_amounts' => $bad, 'removed_markers' => $removed, 'sources' => $sources, 'checked' => count($claimed)];
    }
}
