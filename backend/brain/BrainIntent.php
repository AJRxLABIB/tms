<?php
require_once __DIR__ . '/BrainText.php';
require_once __DIR__ . '/BrainKnowledge.php';

/**
 * BrainIntent - what is the visitor asking? A small multinomial Naive Bayes text classifier (unigrams + bigrams, Laplace
 * smoothing) trained at start-up from data/brain/intents.json, which holds example questions in English, Banglish and Bangla.
 * Place names are masked first so it learns the question, not the destination. This is real (tiny) machine learning, fully
 * local: no network, no model file. Add examples to intents.json to teach it new phrasings.
 * Below MIN_CONFIDENCE the answer is "unknown" and the caller falls back to the catalogue assistant.
 */
final class BrainIntent
{
    public const MIN_CONFIDENCE = 0.45;
    private const ALPHA = 0.25;

    /** Plain keyword cues, used when the classifier is unsure. intent => words/phrases (matched on padded, normalised text). */
    private const CUES = [
        'greeting' => ['hello','hi','hey','salam','assalamu alaikum','good morning','good evening','হ্যালো','হাই','সালাম','আসসালামু আলাইকুম'],
        'thanks' => ['thanks','thank you','thx','dhonnobad','ধন্যবাদ'],
        'about' => ['who are you','what can you do','tumi ke','তুমি কে','help me'],
        'weather' => ['weather','rain','temperature','climate','abohawa','আবহাওয়া','বৃষ্টি','তাপমাত্রা'],
        'best_time' => ['best time','when to go','when should','which month','season','kokhon','কখন','সেরা সময়','কোন মাসে'],
        'cost' => ['cost','price','budget','how much','khoroch','koto taka','খরচ','বাজেট','কত টাকা'],
        'how_to_reach' => ['how to go','how to reach','how do i get','route','bus','train','flight','ferry','kivabe','kibhabe','jabo','যাব','যাওয়ার','কিভাবে'],
        'stay' => ['hotel','stay','resort','room','accommodation','thakbo','থাকব','হোটেল','রিসোর্ট'],
        'food' => ['food','eat','restaurant','khabar','khabo','dish','খাবার','খাব'],
        'things_to_do' => ['things to do','places to visit','attractions','tourist spot','what to see','dekhbo','ghurar','দেখব','দর্শনীয়','ঘোরার'],
        'safety' => ['safe','safety','danger','rules','precaution','permit','নিরাপদ','সতর্ক'],
        'packing' => ['pack','packing','carry','bring','nibo','নিব','ব্যাগে'],
        'plan_trip' => ['plan','itinerary','schedule','প্ল্যান','পরিকল্পনা','ভ্রমণসূচি'],
        'suggest' => ['suggest','recommend','where should i go','where to go','kothay ghurte','কোথায় ঘুরতে','ভালো জায়গা'],
    ];
    private static ?array $model = null;

    /** @return string[] */
    private static function feats(string $text): array
    {
        $t = BrainText::content(BrainKnowledge::maskPlaces($text));
        $f = $t;
        for ($i = 0; $i + 1 < count($t); $i++) $f[] = $t[$i] . '_' . $t[$i + 1];
        return $f;
    }

    private static function train(): array
    {
        if (self::$model !== null) return self::$model;
        $count = []; $docs = []; $vocab = []; $total = [];
        foreach (BrainKnowledge::intentExamples() as $intent => $examples) {
            foreach ($examples as $ex) {
                $docs[$intent] = ($docs[$intent] ?? 0) + 1;
                foreach (self::feats((string)$ex) as $f) { $count[$intent][$f] = ($count[$intent][$f] ?? 0) + 1; $total[$intent] = ($total[$intent] ?? 0) + 1; $vocab[$f] = true; }
            }
        }
        return self::$model = ['count' => $count, 'docs' => $docs, 'vocab' => count($vocab), 'total' => $total, 'n' => array_sum($docs)];
    }

    public static function reset(): void { self::$model = null; }

    /** First intent whose cue words appear in the text (most specific match wins), or null. */
    private static function cue(string $text): ?string
    {
        $n = ' ' . BrainText::norm($text) . ' ';
        $best = null; $bestLen = 0;
        foreach (self::CUES as $intent => $words) {
            foreach ($words as $w) {
                $w = BrainText::norm($w);
                if ($w !== '' && mb_strpos($n, ' ' . $w . ' ', 0, 'UTF-8') !== false && mb_strlen($w, 'UTF-8') > $bestLen) { $best = $intent; $bestLen = mb_strlen($w, 'UTF-8'); }
            }
        }
        return $best;
    }

    /**
     * @return array{intent:string,confidence:float,scores:array<string,float>}
     */
    public static function classify(string $text): array
    {
        $m = self::train();
        $feats = self::feats($text);
        if (!$m['n'] || !$feats) return ['intent' => 'unknown', 'confidence' => 0.0, 'scores' => []];
        $logs = [];
        foreach ($m['docs'] as $intent => $nd) {
            // Uniform class prior: the small classes (greeting, thanks) must not lose to the big ones just for having fewer examples.
            $lp = 0.0;
            $den = ($m['total'][$intent] ?? 0) + self::ALPHA * $m['vocab'];
            foreach ($feats as $f) $lp += log((($m['count'][$intent][$f] ?? 0) + self::ALPHA) / $den);
            $logs[$intent] = $lp;
        }
        $max = max($logs); $sum = 0.0; $p = [];
        foreach ($logs as $k => $l) { $p[$k] = exp($l - $max); $sum += $p[$k]; }
        foreach ($p as $k => $v) $p[$k] = $v / $sum;
        arsort($p);
        $best = array_key_first($p);
        // an input with no known word at all must not be "classified": require at least one feature seen in training
        $seen = 0; foreach ($feats as $f) foreach ($m['count'] as $c) { if (isset($c[$f])) { $seen++; break; } }
        $conf = $seen === 0 ? 0.0 : $p[$best];
        if ($conf < self::MIN_CONFIDENCE) {
            $cue = self::cue($text);
            if ($cue !== null) return ['intent' => $cue, 'confidence' => 0.5, 'scores' => array_slice($p, 0, 4, true), 'by' => 'cue'];
        }
        return ['intent' => $conf >= self::MIN_CONFIDENCE ? $best : 'unknown', 'confidence' => round($conf, 3), 'scores' => array_slice($p, 0, 4, true)];
    }
}
