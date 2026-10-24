<?php
require_once __DIR__ . '/BrainText.php';

/**
 * BrainKnowledge - the travel knowledge base of the TourBuddy Brain.
 * Reads data/brain/destinations/*.json (one file per destination), data/brain/global.json and data/brain/intents.json.
 * Everything here is curated reference data kept in the repository: prices are APPROXIMATE ranges and are labelled as
 * estimates wherever they are shown. Nothing is fetched from the internet.
 */
final class BrainKnowledge
{
    private static ?array $dest = null;      // id => destination
    private static ?array $global = null;
    private static ?array $aliasIndex = null; // normalised alias => id
    private static ?string $dir = null;

    public static function setDataDir(?string $dir): void { self::$dir = $dir; self::$dest = self::$global = self::$aliasIndex = null; }
    private static function dir(): string { return self::$dir ?? dirname(__DIR__, 2) . '/data/brain'; }

    /** @return array<string,array> */
    public static function all(): array
    {
        if (self::$dest !== null) return self::$dest;
        self::$dest = [];
        foreach (glob(self::dir() . '/destinations/*.json') ?: [] as $f) {
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d) && !empty($d['id']) && !empty($d['name'])) self::$dest[$d['id']] = $d;
        }
        ksort(self::$dest);
        return self::$dest;
    }

    public static function get(string $id): ?array { return self::all()[$id] ?? null; }

    public static function global(): array
    {
        if (self::$global === null) {
            $g = json_decode((string)@file_get_contents(self::dir() . '/global.json'), true);
            self::$global = is_array($g) ? $g : [];
        }
        return self::$global;
    }

    public static function intentExamples(): array
    {
        $x = json_decode((string)@file_get_contents(self::dir() . '/intents.json'), true);
        return is_array($x) ? $x : [];
    }

    private static function aliasIndex(): array
    {
        if (self::$aliasIndex !== null) return self::$aliasIndex;
        self::$aliasIndex = [];
        foreach (self::all() as $id => $d) {
            $names = array_merge([$d['name'], $d['bn'] ?? '', $id], (array)($d['aliases'] ?? []));
            // short forms: "Cox's Bazar" also answers to its first word only when that word is unique and long enough
            foreach ($names as $n) { $k = BrainText::norm((string)$n); if ($k !== '') self::$aliasIndex[$k] = self::$aliasIndex[$k] ?? $id; }
        }
        return self::$aliasIndex;
    }

    /**
     * Destinations mentioned in the text, best first. Matches whole aliases (exact phrase), then single words by typo-tolerant
     * comparison (edit distance 1 for words of 5+ letters, 2 for 8+). @return array<int,array{id:string,score:float,pos:int}>
     */
    public static function find(string $text, int $limit = 3): array
    {
        $n = ' ' . BrainText::norm($text) . ' ';
        if (trim($n) === '') return [];
        $hits = [];
        foreach (self::aliasIndex() as $alias => $id) {
            if ($alias === '') continue;
            $p = mb_strpos($n, ' ' . $alias . ' ', 0, 'UTF-8');
            if ($p !== false) { $s = 10 + mb_strlen($alias, 'UTF-8') / 10; if (!isset($hits[$id]) || $hits[$id]['score'] < $s) $hits[$id] = ['id' => $id, 'score' => $s, 'pos' => (int)$p]; }
        }
        if (true) {   // typo-tolerant pass over single words and adjacent word pairs (adds places the exact pass missed, e.g. "cox bazaar")
            $words = BrainText::tokens($text);
            $cands = $words;
            for ($i = 0; $i + 1 < count($words); $i++) $cands[] = $words[$i] . $words[$i + 1];
            foreach ($cands as $w) {
                $lw = mb_strlen($w, 'UTF-8');
                if ($lw < 4 || isset(self::aliasIndex()[$w])) continue;   // an exact alias is never a typo of another place
                foreach (self::aliasIndex() as $alias => $id) {
                    $a = str_replace(' ', '', $alias); $la = mb_strlen($a, 'UTF-8');
                    if ($la < 4 || abs($la - $lw) > 2 || mb_substr($a, 0, 1, 'UTF-8') !== mb_substr($w, 0, 1, 'UTF-8')) continue;
                    $maxD = $la >= 10 ? 2 : ($la >= 5 ? 1 : 0);
                    $d = BrainText::lev($w, $a);
                    if ($d <= $maxD && !isset($hits[$id])) { $hits[$id] = ['id' => $id, 'score' => 6 - $d, 'pos' => (int)(mb_strpos($n, ' ' . mb_substr($w, 0, 3, 'UTF-8'), 0, 'UTF-8') ?: 0)]; }
                }
            }
        }
        usort($hits, fn($a, $b) => [$b['score'], $a['pos']] <=> [$a['score'], $b['pos']]);
        return array_slice(array_values($hits), 0, $limit);
    }

    /** Text with every destination alias replaced by the word "placeholder" (so a classifier learns the question, not the place). */
    public static function maskPlaces(string $text): string
    {
        $n = ' ' . BrainText::norm($text) . ' ';
        $aliases = array_keys(self::aliasIndex());
        usort($aliases, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        foreach ($aliases as $a) { if ($a !== '') $n = str_replace(' ' . $a . ' ', ' placeholder ', $n); }
        return trim($n);
    }

    public static function monthLabel(int $m): string { return self::global()['month_labels'][$m] ?? (string)$m; }

    /** "November to March" style text for a list of month numbers. */
    public static function monthsText(array $months): string
    {
        $m = array_values(array_unique(array_map('intval', $months))); sort($m);
        if (!$m) return '';
        // rotate so a run across the new year stays together (e.g. 11,12,1,2,3)
        $set = array_flip($m); $start = null;
        foreach ($m as $x) { $prev = $x === 1 ? 12 : $x - 1; if (!isset($set[$prev])) { $start = $x; break; } }
        if ($start === null) return 'all year';
        $run = []; $cur = $start;
        while (isset($set[$cur]) && count($run) < 12) { $run[] = $cur; $cur = $cur === 12 ? 1 : $cur + 1; }
        $out = count($run) === 1 ? self::monthLabel($run[0]) : self::monthLabel($run[0]) . ' to ' . self::monthLabel(end($run));
        if (count($run) < count($m)) $out .= ' (and ' . implode(', ', array_map([self::class, 'monthLabel'], array_values(array_diff($m, $run)))) . ')';
        return $out;
    }

    /** Typical price tiers for stays: [min, max] BDT per room per night for the tier, or null. */
    public static function stayTier(array $dest, string $tier): ?array
    {
        $s = $dest['stay'][$tier] ?? null;
        return is_array($s) && ($s['price_max'] ?? 0) > 0 ? $s : null;
    }
}
