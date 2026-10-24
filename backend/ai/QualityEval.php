<?php
/**
 * QualityEval — scores ONE real assistant turn against code-checkable expectations.
 * Pure functions (no DB, no network) so the scoring itself is unit-tested; the live runner (tests/ai/live_quality.php)
 * supplies real replies. These checks measure objective, auditable properties only:
 *   lang            reply is mostly Bengali / Latin script as expected
 *   tools_include   the named tools actually ran for this turn
 *   no_unverified   no price in the reply failed CitationVerifier
 *   forbid          regexes that must NOT match the reply
 *   require_any     at least one regex must match (e.g. "cannot confirm" hedging)
 *   refuse          the turn was blocked by the safety guard (model never called)
 * They do NOT judge helpfulness, tone or factual correctness of prose; that needs a human reviewer.
 */
final class QualityEval
{
    /** Share of letters that are Bengali: 0..1 */
    public static function bengaliShare(string $s): float
    {
        $bn = preg_match_all('/\p{Bengali}/u', $s); $all = preg_match_all('/\p{L}/u', $s);
        return $all > 0 ? $bn / $all : 0.0;
    }

    /** @param array<string,mixed> $case @param array{reply:string,meta?:array} $res @return array{pass:bool,failed:string[]} */
    public static function score(array $case, array $res): array
    {
        $c = $case['checks'] ?? []; $reply = (string)($res['reply'] ?? ''); $meta = $res['meta'] ?? []; $f = [];
        if (!empty($c['refuse'])) {
            if (($meta['outcome'] ?? '') !== 'guard_blocked') $f[] = 'refuse: the safety guard did not block this request';
            return ['pass' => !$f, 'failed' => $f];   // a refusal is a fixed string; no other check applies
        }
        if (($meta['outcome'] ?? 'answered') !== 'answered') $f[] = 'no answer (outcome=' . ($meta['outcome'] ?? '?') . ')';
        if (isset($c['lang'])) {
            $sh = self::bengaliShare($reply);
            if ($c['lang'] === 'bn' && $sh < 0.5) $f[] = sprintf('lang: expected Bengali reply, Bengali share %.0f%%', $sh * 100);
            if ($c['lang'] === 'en' && $sh > 0.2) $f[] = sprintf('lang: expected English reply, Bengali share %.0f%%', $sh * 100);
        }
        foreach ($c['tools_include'] ?? [] as $t) if (!in_array($t, $meta['tools'] ?? [], true)) $f[] = "tools_include: '$t' did not run";
        if (!empty($c['no_unverified']) && (int)($meta['unverified_prices'] ?? 0) > 0) $f[] = 'no_unverified: reply contained a price not found in tool results';
        foreach ($c['forbid'] ?? [] as $re) if (@preg_match('/' . $re . '/iu', $reply) === 1) $f[] = "forbid: matched /$re/";
        if (!empty($c['require_any'])) {
            $hit = false; foreach ($c['require_any'] as $re) if (@preg_match('/' . $re . '/iu', $reply) === 1) { $hit = true; break; }
            if (!$hit) $f[] = 'require_any: none of the expected phrases present';
        }
        return ['pass' => !$f, 'failed' => $f];
    }

    /** Validate the case file structure (ids unique, known check names, valid regexes). @return string[] problems */
    public static function validateCases(array $cases): array
    {
        $known = ['lang', 'tools_include', 'no_unverified', 'forbid', 'require_any', 'refuse']; $p = []; $ids = [];
        foreach ($cases as $i => $c) {
            $id = $c['id'] ?? "#$i";
            if (isset($ids[$id])) $p[] = "$id: duplicate id"; $ids[$id] = true;
            if (empty($c['prompt']) || !is_string($c['prompt'])) $p[] = "$id: missing prompt";
            if (empty($c['checks']) || !is_array($c['checks'])) { $p[] = "$id: no checks"; continue; }
            foreach (array_keys($c['checks']) as $k) if (!in_array($k, $known, true)) $p[] = "$id: unknown check '$k'";
            foreach (array_merge($c['checks']['forbid'] ?? [], $c['checks']['require_any'] ?? []) as $re) if (@preg_match('/' . $re . '/iu', '') === false) $p[] = "$id: bad regex /$re/";
        }
        return $p;
    }
}
