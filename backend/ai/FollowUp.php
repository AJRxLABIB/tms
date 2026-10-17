<?php
/**
 * FollowUp — resolves references to the PREVIOUS answer ("the second one", "the cheaper one", "compare them") to real package
 * ids, in code. The ids come only from package links the server itself saved in the assistant's last reply that listed
 * packages; nothing is guessed. If a reference cannot be resolved unambiguously the result says so (never a silent pick).
 */
final class FollowUp
{
    private const ORD = [
        'first' => 1, '1st' => 1, 'second' => 2, '2nd' => 2, 'third' => 3, '3rd' => 3, 'fourth' => 4, '4th' => 4,
        'প্রথম' => 1, 'প্রথমটি' => 1, 'দ্বিতীয়' => 2, 'দ্বিতীয়টি' => 2, 'তৃতীয়' => 3, 'তৃতীয়টি' => 3, 'চতুর্থ' => 4, 'fifth' => 5, '5th' => 5, 'sixth' => 6, '6th' => 6, 'পঞ্চম' => 5, 'ষষ্ঠ' => 6,
    ];

    /** Package ids, in order of appearance, from the most recent assistant turn that linked at least one package. @return int[] */
    public static function recentIds(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'assistant') continue;
            if (!preg_match_all('/package-details\.php\?pkgid=(\d{1,9})/', (string)($history[$i]['content'] ?? ''), $m)) continue;
            $ids = [];
            foreach ($m[1] as $id) { $id = (int)$id; if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id; }
            return array_slice($ids, 0, 6);
        }
        return [];
    }

    /**
     * @param int[]               $ids    from recentIds()
     * @param array<int,int|float> $prices package id => price per person (only used for "cheaper/cheapest")
     * @return array{kind:string,ids:int[],target:?int,unresolved:bool}
     *   kind: none | compare | single. unresolved=true means the visitor referred to an earlier result but it cannot be identified.
     */
    public static function resolve(string $message, array $ids, array $prices = []): array
    {
        $none = ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => false];
        $m = mb_strtolower(BanglaNormalizer::digits($message), 'UTF-8');
        $compare = (bool)preg_match('/\b(compare|comparison|versus|vs\.?|difference between|which (?:one )?is better|which (?:one )?(?:should|to) (?:i|we) (?:pick|choose|take))\b|তুলনা|কোনটা ভালো|কোনটি ভালো/u', $m);
        $ordinals = [];
        foreach (self::ORD as $w => $n) if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $m)) $ordinals[$n] = true;
        $last = (bool)preg_match('/\b(the )?last one\b|\bthe last\b|শেষ(?:টি|টা)?/u', $m);
        $cheap = (bool)preg_match('/\b(cheaper|cheapest|less expensive|lowest price|more affordable)\b|সস্তা|কম দামের|কম দাম/u', $m);
        $thatOne = (bool)preg_match('/\b(that one|this one|the one you (?:just )?(?:mentioned|showed|suggested)|those|these|them|both)\b|ওটা|এটা|ওগুলো|দুটো|দুটি/u', $m);
        $ref = $ordinals || $last || $cheap || ($compare && $thatOne) || ($compare && preg_match('/\b(them|these|those|both|all)\b|দুটো|দুটি/u', $m));
        if (!$compare && !$ordinals && !$last && !$cheap) return $none; // "that one" alone is too vague to act on without another cue
        if (!$ids) return $compare || $ref ? ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => $ref || $compare] : $none;

        // Which ids does the message point at?
        $picked = [];
        foreach (array_keys($ordinals) as $n) { if (isset($ids[$n - 1])) $picked[] = $ids[$n - 1]; else return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true]; }
        if ($last) $picked[] = $ids[count($ids) - 1];
        $picked = array_values(array_unique($picked));
        if ($cheap) {
            $pool = $picked ?: $ids; $best = null; $tie = false;
            foreach ($pool as $id) {
                if (!isset($prices[$id])) continue;
                if ($best === null || $prices[$id] < $prices[$best]) { $best = $id; $tie = false; }
                elseif ($prices[$id] === $prices[$best]) $tie = true;
            }
            if ($best === null || $tie) return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
            if (!$compare) return ['kind' => 'single', 'ids' => [$best], 'target' => $best, 'unresolved' => false];
        }
        if ($compare) {
            $set = $picked ?: $ids;
            $set = array_slice($set, 0, 4);
            if (count($set) < 2) return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
            return ['kind' => 'compare', 'ids' => $set, 'target' => null, 'unresolved' => false];
        }
        if (count($picked) === 1) return ['kind' => 'single', 'ids' => $picked, 'target' => $picked[0], 'unresolved' => false];
        return ['kind' => 'none', 'ids' => [], 'target' => null, 'unresolved' => true];
    }
}
