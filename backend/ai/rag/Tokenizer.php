<?php
/**
 * Tokenizer — Unicode-aware tokenizer for English and Bengali used by the
 * local retrieval index. No external service, no model.
 *
 * - Lower-cases, splits on anything that is not a letter, digit or combining
 *   mark (so Bengali vowel signs stay attached to their consonants).
 * - Light English suffix stripping (plural / -ing) so "beaches" matches "beach".
 * - Drops a short stop-word list (English + Bengali).
 * - Expands a SMALL hand-written bilingual alias list, so a Bengali query such
 *   as "কক্সবাজার" also matches documents that spell it "Cox's Bazar".
 *   The list is intentionally modest; unknown words simply match lexically.
 */
final class RagTokenizer
{
    private const STOP = [
        'the','a','an','and','or','of','to','in','on','for','with','is','are','be','at','by','from','this','that',
        'it','as','do','does','i','me','my','we','you','your','can','what','how','which','any','have','has',
        'আমি','আমার','এবং','ও','কি','কী','এই','সেই','জন্য','থেকে','এর','না','আছে','কত','কোন','কোনো','করা','করে',
    ];

    /** Bengali (and a few variant spellings) -> English tokens. */
    private const ALIASES = [
        'কক্সবাজার' => ['cox','bazar','bazaar'],
        'সমুদ্র' => ['beach','sea'], 'সৈকত' => ['beach'],
        'সুন্দরবন' => ['sundarbans'], 'সিলেট' => ['sylhet'], 'বান্দরবান' => ['bandarban'],
        'রাঙ্গামাটি' => ['rangamati'], 'খাগড়াছড়ি' => ['khagrachari'], 'সেন্টমার্টিন' => ['saint','martin'],
        'ঢাকা' => ['dhaka'], 'চট্টগ্রাম' => ['chattogram','chittagong'],
        'পাহাড়' => ['hill','mountain'], 'নদী' => ['river'], 'জঙ্গল' => ['forest','wildlife'],
        'হোটেল' => ['hotel'], 'বাজেট' => ['budget','cheap'], 'সস্তা' => ['cheap','budget'],
        'পরিবার' => ['family'], 'হানিমুন' => ['honeymoon','couple'], 'ভ্রমণ' => ['trip','tour'],
        'প্যাকেজ' => ['package'], 'বাতিল' => ['cancel','cancellation'], 'ফেরত' => ['refund'],
        'দাম' => ['price'], 'মূল্য' => ['price'], 'দিন' => ['day'], 'রাত' => ['night'],
        'ভুটান' => ['bhutan'], 'নেপাল' => ['nepal'], 'ভারত' => ['india'], 'থাইল্যান্ড' => ['thailand'],
        'মালদ্বীপ' => ['maldives'], 'দুবাই' => ['dubai'], 'সিঙ্গাপুর' => ['singapore'],
    ];

    /** @return string[] */
    public static function tokens(string $text, bool $expand = true): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (!preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $m)) return [];
        $out = [];
        foreach ($m[0] as $w) {
            if (isset(self::ALIASES[$w])) {
                if ($expand) foreach (self::ALIASES[$w] as $a) $out[] = self::stem($a);
                continue;
            }
            if (in_array($w, self::STOP, true)) continue;
            $out[] = self::stem($w);
        }
        return $out;
    }

    private static function stem(string $w): string
    {
        if (!preg_match('/^[a-z0-9]+$/', $w) || strlen($w) <= 3) return $w;
        foreach (['ies' => 'y', 'ing' => '', 'es' => '', 's' => ''] as $suf => $rep) {
            $n = strlen($suf);
            if (strlen($w) > $n + 2 && substr($w, -$n) === $suf && substr($w, -2) !== 'ss') {
                return substr($w, 0, -$n) . $rep;
            }
        }
        return $w;
    }
}
