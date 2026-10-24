<?php
/**
 * BrainText - small text helpers shared by the TourBuddy Brain (the built-in travel engine, no outside AI).
 * Pure functions: lower-casing, accent/punctuation clean-up, tokens, edit distance for typo-tolerant place names.
 */
final class BrainText
{
    /** Lower-case, Bangla digits to ASCII, punctuation to spaces, collapsed spaces. Bangla letters are kept. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
        $s = preg_replace("/[’'`]/u", '', $s) ?? $s;                // cox's -> coxs
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** @return string[] */
    public static function tokens(string $s): array
    {
        $n = self::norm($s);
        return $n === '' ? [] : preg_split('/\s+/u', $n);
    }

    private const STOP = ['a','an','the','to','of','in','on','at','for','and','or','is','are','be','i','me','my','we','you','it','this','that','with','from','do','does','can','what','which','how','will','please','tell','about','give','show','want','need','would','should','there','some','any','am'];
    /** Tokens without very common words (used by the classifier). @return string[] */
    public static function content(string $s): array
    {
        return array_values(array_filter(self::tokens($s), fn($t) => !in_array($t, self::STOP, true) && mb_strlen($t, 'UTF-8') > 1));
    }

    /** Levenshtein for multibyte strings (small inputs only). */
    public static function lev(string $a, string $b): int
    {
        if ($a === $b) return 0;
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY); $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $la = count($a); $lb = count($b);
        if ($la === 0) return $lb; if ($lb === 0) return $la;
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
            $prev = $cur;
        }
        return $prev[$lb];
    }

    /** Join a list for display: "a, b and c". */
    public static function human(array $items): string
    {
        $items = array_values(array_filter(array_map('strval', $items), fn($x) => $x !== ''));
        if (count($items) <= 1) return $items[0] ?? '';
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    public static function money(float $n): string { return 'BDT ' . number_format((int)round($n)); }

    /** "150-400 BDT for most town trips" -> 275 (average of the first two numbers, or the one number). 0 if none. */
    public static function fare(string $text): int
    {
        if (!preg_match_all('/\d[\d,]*/', $text, $m)) return 0;
        $n = array_map(fn($x) => (int)str_replace(',', '', $x), array_slice($m[0], 0, 2));
        return (int)round(array_sum($n) / count($n));
    }
}
