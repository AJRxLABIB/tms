<?php
/**
 * Banglish — Bangla written in Latin letters ("sylhet e sosta bhromon", "poribar niye cox bazar jete chai").
 *
 * Deterministic, data-table based; no model. Two jobs:
 *   - tokens(): the English search tokens a Banglish word stands for (used by RagTokenizer so retrieval matches the catalogue);
 *   - hints():  a short English string appended to a message so the rule-based intent detection in ToolRouter sees the same cues it
 *               sees in English or Bangla. The visitor's original text is never rewritten.
 * The table is deliberately small and limited to words that are not ordinary English words; unknown words simply match lexically.
 * Spelling varies a lot in Banglish, so each concept lists its common variants.
 */
final class Banglish
{
    /** word -> English tokens it stands for */
    private const MAP = [
        // trip / travel words
        'bhromon' => ['trip', 'tour'], 'bhromone' => ['trip', 'tour'], 'vromon' => ['trip', 'tour'], 'vromone' => ['trip', 'tour'], 'ghurte' => ['trip', 'tour'], 'ghurbo' => ['trip', 'tour'], 'ghuraghuri' => ['trip', 'tour'],
        'jabo' => ['visit'], 'jete' => ['visit'], 'jaite' => ['visit'],
        // money
        'sosta' => ['cheap', 'budget'], 'shosta' => ['cheap', 'budget'], 'sasta' => ['cheap', 'budget'], 'kom' => ['cheap'], 'bajet' => ['budget'],
        'daam' => ['price'], 'khoroch' => ['price', 'cost'], 'khorcho' => ['price', 'cost'], 'koto' => ['price'],
        // people
        'poribar' => ['family'], 'poribare' => ['family'], 'poribarer' => ['family'], 'bondhu' => ['friends', 'group'], 'bondhura' => ['friends', 'group'],
        'bou' => ['couple', 'honeymoon'], 'dompoti' => ['couple', 'honeymoon'],
        // nature / places
        'pahar' => ['hill', 'mountain'], 'pahare' => ['hill', 'mountain'], 'pahari' => ['hill', 'mountain'],
        'shomudro' => ['beach', 'sea'], 'somudro' => ['beach', 'sea'], 'samudra' => ['beach', 'sea'], 'shoikot' => ['beach'], 'soikot' => ['beach'],
        'nodi' => ['river'], 'jongol' => ['forest', 'wildlife'], 'bon' => ['forest'], 'jhorna' => ['waterfall'], 'chaa' => ['tea'], 'cha' => ['tea'],
        'sylet' => ['sylhet'], 'coxs' => ['cox', 'bazar'], 'coxbazar' => ['cox', 'bazar'], 'coxsbazar' => ['cox', 'bazar'], 'kuakata' => ['kuakata'],
        'bandorban' => ['bandarban'], 'bandarbon' => ['bandarban'], 'sundarban' => ['sundarbans'], 'sundorbon' => ['sundarbans'], 'sundarbon' => ['sundarbans'],
        'saintmartin' => ['saint', 'martin'], 'sentmartin' => ['saint', 'martin'], 'rangamati' => ['rangamati'], 'srimongol' => ['sreemangal', 'sylhet'], 'sreemongol' => ['sreemangal', 'sylhet'],
        'chittagong' => ['chattogram', 'chittagong'], 'chottogram' => ['chattogram', 'chittagong'],
        // lodging / actions
        'thakar' => ['hotel'], 'thakbo' => ['hotel'],
        'bukking' => ['booking'], 'buking' => ['booking'], 'bukkingta' => ['booking'],
        'batil' => ['cancel', 'cancellation'], 'bhatil' => ['cancel', 'cancellation'], 'cancelkora' => ['cancel'],
        'ferot' => ['refund'], 'ferat' => ['refund'],
        'bhisa' => ['visa'], 
        // time
        'din' => ['day'], 'diner' => ['day'], 'raat' => ['night'], 'shoptaho' => ['week'], 'soptaho' => ['week'],
    ];

    /** Banglish function words that carry no topical meaning (so they do not dilute retrieval). */
    private const STOP = [
        'ami', 'amar', 'amader', 'amra', 'tumi', 'apni', 'apnar', 'ache', 'ase', 'achhe', 'ki', 'kon', 'kothay', 'kothai', 'kemon', 'keno',
        'chai', 'chaii', 'lagbe', 'dekhao', 'dekhan', 'bolo', 'bolun', 'den', 'dao', 'er', 'ke', 'te', 'e', 'jonno', 'jonne', 'moddhe', 'modhye',
        'niche', 'nichey', 'niye', 'sathe', 'ta', 'ti', 'gulo', 'taka', 'takar', 'tk', 'hobe', 'hoy', 'pabo', 'pawa', 'jay', 'jabe',
    ];

    /** Banglish phrases that signal an intent the rule router already knows in English/Bangla. [regex => English hint] */
    private const HINTS = [
        '/\b(?:koto|kto)\s*(?:taka|tk|dam|khoroch|lagbe|porbe)\b/u' => 'how much price cost',
        '/\b(?:sosta|shosta|sasta)\b/u' => 'cheap budget',
        '/\b(?:bhromon|vromon|bhromone|ghurte|ghurbo)\b/u' => 'trip tour',
        '/\bporibar(?:er|e)?\b/u' => 'family',
        '/\b(?:batil|bhatil)\b/u' => 'cancel cancellation',
        '/\bferot\b/u' => 'refund',
        '/\bamar\s+(?:bukking|buking|booking|trip|bhromon)\b/u' => 'my booking',
        '/\b(?:bhisa|visa)\b/u' => 'visa',
    ];

    /** @return string[]|null English tokens, or null when the word is not in the table */
    public static function tokens(string $word): ?array
    {
        $w = mb_strtolower($word, 'UTF-8');
        return self::MAP[$w] ?? null;
    }

    public static function isStopWord(string $word): bool
    {
        return in_array(mb_strtolower($word, 'UTF-8'), self::STOP, true);
    }

    /** English cues for the rule router, or '' when the message has no Banglish cue. */
    public static function hints(string $message): string
    {
        $m = mb_strtolower($message, 'UTF-8');
        $out = [];
        foreach (self::HINTS as $re => $hint) if (preg_match($re, $m)) $out[] = $hint;
        // single-word map hits that matter for catalogue intent
        if (preg_match_all('/[a-z]+/u', $m, $w)) {
            foreach (array_unique($w[0]) as $x) { $t = self::MAP[$x] ?? null; if ($t ) $out[] = implode(' ', $t); }
        }
        return trim(implode(' ', array_unique($out)));
    }

    /** True when the message looks like Banglish: Latin letters containing at least two table or stop words that are not plain English. */
    public static function looksBanglish(string $message): bool
    {
        if (preg_match('/\p{Bengali}/u', $message)) return false;
        if (!preg_match_all('/[a-z]+/u', mb_strtolower($message, 'UTF-8'), $w)) return false;
        $hits = 0;
        foreach (array_unique($w[0]) as $x) {
            if ($x === 'e' || $x === 'er' || $x === 'te' || $x === 'ta' || $x === 'ti' || $x === 'den' || $x === 'bon' || $x === 'cha' || $x === 'kom' || $x === 'ke' || $x === 'din') continue;
            if (isset(self::MAP[$x]) || in_array($x, self::STOP, true)) $hits++;
        }
        return $hits >= 2;
    }
}
