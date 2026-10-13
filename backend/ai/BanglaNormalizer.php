<?php
/**
 * BanglaNormalizer — small, deterministic helpers for Bangla / English / mixed requests.
 *
 * Money and routing decisions must not depend on a language model reading Bangla digits correctly, so this does
 * the numeric part with plain code:
 *   - Bangla (and Arabic-Indic) digits -> ASCII digits, WITHOUT touching the original text elsewhere. Callers keep the
 *     user's original message for display and for exact-match search; only a normalised COPY is used for parsing.
 *   - amounts such as "৫০ হাজার টাকা", "50k", "1.5 lakh", "২ লাখ", "৳50,000" -> whole taka (int).
 * Identifiers (package codes, URLs, booking numbers) are never rewritten by anything here except digit mapping on the
 * parsing copy.
 */
final class BanglaNormalizer
{
    private const DIGITS = ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9',
                            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    /** multiplier word -> factor */
    private const MULT = ['k' => 1000, 'thousand' => 1000, 'হাজার' => 1000, 'lakh' => 100000, 'lac' => 100000, 'lakhs' => 100000,
                          'লাখ' => 100000, 'লক্ষ' => 100000, 'crore' => 10000000, 'কোটি' => 10000000];
    private const MULT_RE = 'k|thousand|হাজার|lakhs?|lac|লাখ|লক্ষ|crore|কোটি';
    private const NUM_RE = '(\d[\d,]*(?:\.\d+)?)';

    public static function digits(string $s): string
    {
        return strtr($s, self::DIGITS);
    }

    /** Language of a message: 'bn', 'en' or 'mixed' by share of Bangla letters among letters. */
    public static function language(string $s): string
    {
        $bn = preg_match_all('/\p{Bengali}/u', $s); $lat = preg_match_all('/[A-Za-z]/', $s);
        if (!$bn && !$lat) return 'en';
        if (!$bn) return 'en';
        if (!$lat) return 'bn';
        return 'mixed';
    }

    /** Parse "50,000" / "1.5" with a multiplier word into whole taka; null if not a sane amount. */
    public static function toTaka(string $num, ?string $mult): ?int
    {
        $n = (float)str_replace(',', '', $num);
        if ($mult !== null && $mult !== '') $n *= self::MULT[mb_strtolower($mult, 'UTF-8')] ?? 1;
        $n = (int)round($n);
        return ($n >= 1 && $n <= 1000000000) ? $n : null;
    }

    /** Every amount in the text, in order, as whole taka (currency words/symbols are optional and ignored). @return int[] */
    public static function amounts(string $text): array
    {
        $t = mb_strtolower(self::digits($text), 'UTF-8');
        $out = [];
        if (preg_match_all('/' . self::NUM_RE . '\s*(' . self::MULT_RE . ')?(?![\p{L}\p{N}])/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) { $v = self::toTaka($x[1], $x[2] ?? null); if ($v !== null) $out[] = $v; }
        }
        return $out;
    }

    /**
     * "Up to how much?" — the budget ceiling stated in a request, or null. Understands English ("under 50k", "within 1.5 lakh",
     * "budget 40000") and Bangla ("৫০ হাজার টাকার মধ্যে", "৩০০০০ এর নিচে"). Ignores amounts below 500 taka (not a trip budget).
     */
    public static function budgetCeiling(string $text): ?int
    {
        $t = mb_strtolower(self::digits($text), 'UTF-8');
        $num = self::NUM_RE . '\s*(' . self::MULT_RE . ')?';
        $cur = '(?:\s*(?:taka|tk|bdt|টাকা|টাকার|৳))?';
        $patterns = [
            '/(?:under|below|less than|within|upto|up to|max(?:imum)?|budget(?: of| is)?|around|about)\D{0,8}' . $num . '/u',
            '/(?:বাজেট|সর্বোচ্চ)\D{0,6}' . $num . '/u',
            '/৳\s*' . $num . '/u',
            '/' . $num . $cur . '\s*(?:এর\s*|র\s*)?(?:নিচে|কম|মধ্যে|বাজেট|বাজেটে)/u',
            '/' . $num . '\s*(?:taka|tk|bdt)\s*(?:budget|max|or less)/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $t, $m)) {
                $v = self::toTaka($m[1], $m[2] ?? null);
                if ($v !== null && $v >= 500) return $v;
            }
        }
        return null;
    }
}
