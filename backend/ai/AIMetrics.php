<?php
/**
 * AIMetrics — measurement of the AI layer. Pure aggregation functions (unit-tested, no DB) plus thin read-only DB
 * readers over tables that already exist: tbl_ai_telemetry (per provider call), tbl_ai_turn_log (per assistant turn,
 * counts only) and tbl_ai_feedback (thumbs). Every reader returns null/[] when its table is missing.
 *
 * What these numbers are and are not:
 *   - latency/failure come from real provider calls (telemetry), per provider+model.
 *   - retrieval hit rate = answered turns that ran a search tool AND showed at least one source / answered turns that ran a search tool.
 *   - groundedness = share of answered turns with NO price that failed CitationVerifier. It measures price grounding only.
 *   - feedback ratio = thumbs-up / (up + down) per feature; small samples are flagged, not hidden.
 * None of them is a judgement of answer helpfulness.
 */
final class AIMetrics
{
    /** Nearest-rank percentile (p in 0..100) of a list of numbers; null for an empty list. */
    public static function percentile(array $values, float $p): ?float
    {
        $v = array_values(array_filter($values, fn($x) => is_numeric($x)));
        if (!$v) return null;
        sort($v, SORT_NUMERIC);
        $rank = (int)ceil(max(0.0, min(100.0, $p)) / 100 * count($v));
        return (float)$v[max(0, min(count($v) - 1, $rank - 1))];
    }

    public static function ratio(int|float $num, int|float $den): ?float
    {
        return $den > 0 ? round($num / $den, 4) : null;
    }

    /**
     * @param array<int,array{provider?:string,model?:string,success?:mixed,cached?:mixed,latency_ms?:mixed}> $rows telemetry rows
     * @return array<string,array{provider:string,model:string,calls:int,failures:int,failure_rate:?float,cached:int,p50_ms:?float,p95_ms:?float,p99_ms:?float}>
     */
    public static function summarizeTelemetry(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $prov = (string)($r['provider'] ?? ''); $model = (string)($r['model'] ?? '');
            $k = $prov . '|' . $model;
            $g[$k] ??= ['provider' => $prov, 'model' => $model, 'calls' => 0, 'failures' => 0, 'cached' => 0, 'lat' => []];
            if (!empty($r['cached'])) { $g[$k]['cached']++; continue; }   // cache hits are not provider calls
            $g[$k]['calls']++;
            if (empty($r['success'])) $g[$k]['failures']++;
            elseif (isset($r['latency_ms']) && is_numeric($r['latency_ms'])) $g[$k]['lat'][] = (float)$r['latency_ms'];
        }
        $out = [];
        foreach ($g as $k => $x) {
            $out[$k] = ['provider' => $x['provider'], 'model' => $x['model'], 'calls' => $x['calls'], 'failures' => $x['failures'],
                'failure_rate' => self::ratio($x['failures'], $x['calls']), 'cached' => $x['cached'],
                'p50_ms' => self::percentile($x['lat'], 50), 'p95_ms' => self::percentile($x['lat'], 95), 'p99_ms' => self::percentile($x['lat'], 99)];
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string,mixed> $t   row from turnCounts()
     * @return array{retrieval_hit_rate:?float,groundedness:?float,answered:int,guard_blocked:int,model_error_rate:?float}
     */
    public static function turnRates(array $t): array
    {
        $answered = (int)($t['answered'] ?? 0);
        return [
            'answered' => $answered,
            'guard_blocked' => (int)($t['blocked'] ?? 0),
            'retrieval_hit_rate' => self::ratio((int)($t['search_with_sources'] ?? 0), (int)($t['search_turns'] ?? 0)),
            'groundedness' => $answered > 0 ? self::ratio($answered - (int)($t['unverified'] ?? 0), $answered) : null,
            'model_error_rate' => self::ratio((int)($t['errors'] ?? 0), (int)($t['turns'] ?? 0)),
        ];
    }

    /** @param array<int,array{rating:mixed}> $rows @return array{up:int,down:int,ratio:?float,low_sample:bool} */
    public static function feedbackRatio(array $rows, int $minSample = 20): array
    {
        $up = 0; $down = 0;
        foreach ($rows as $r) { ((int)$r['rating'] > 0) ? $up++ : $down++; }
        return ['up' => $up, 'down' => $down, 'ratio' => self::ratio($up, $up + $down), 'low_sample' => ($up + $down) < $minSample];
    }

    /** Regression status of a result against a baseline: 'ok' | 'regressed' | 'no_baseline'. */
    public static function regressionStatus(array $latest, ?array $baseline): string
    {
        if (!$baseline) return 'no_baseline';
        $cases = (int)($latest['pass'] ?? 0) + (int)($latest['fail'] ?? 0);
        if ($cases < (int)($baseline['min_cases'] ?? 0)) return 'regressed';
        if ((int)($latest['pass'] ?? 0) < (int)($baseline['min_pass'] ?? 0)) return 'regressed';
        if ((int)($latest['leaked'] ?? 0) > 0) return 'regressed';
        return 'ok';
    }

    // ── read-only DB readers ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>>|null */
    public static function telemetry(PDO $dbh, int $days = 7): ?array
    {
        $days = max(1, min(90, $days));
        try {
            $rows = $dbh->query("SELECT provider, model, success, cached, latency_ms FROM tbl_ai_telemetry
                                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) ORDER BY id DESC LIMIT 20000")->fetchAll(PDO::FETCH_ASSOC);
            return self::summarizeTelemetry($rows);
        } catch (Throwable $e) { return null; }
    }

    /** @return array<string,mixed>|null */
    public static function turnCounts(PDO $dbh, int $days = 7): ?array
    {
        $days = max(1, min(90, $days));
        try {
            $r = $dbh->query("SELECT COUNT(*) turns, SUM(outcome='answered') answered, SUM(outcome='guard_blocked') blocked, SUM(outcome='model_error') errors,
                    SUM(outcome='answered' AND unverified_prices>0) unverified,
                    SUM(outcome='answered' AND tools LIKE '%search_%') search_turns,
                    SUM(outcome='answered' AND tools LIKE '%search_%' AND sources>0) search_with_sources
                FROM tbl_ai_turn_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)")->fetch(PDO::FETCH_ASSOC);
            return $r ? array_map(fn($v) => $v === null ? 0 : $v + 0, $r) : null;
        } catch (Throwable $e) { return null; }
    }

    /** @return array<string,array{up:int,down:int,ratio:?float,low_sample:bool}>|null per feature */
    public static function feedback(PDO $dbh, int $days = 30): ?array
    {
        $days = max(1, min(365, $days));
        try {
            $rows = $dbh->query("SELECT feature, rating FROM tbl_ai_feedback WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 50000")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; }
        $by = [];
        foreach ($rows as $r) $by[(string)$r['feature']][] = $r;
        $out = [];
        foreach ($by as $f => $list) $out[$f] = self::feedbackRatio($list);
        ksort($out);
        return $out;
    }
}

/**
 * EvalRunLog — stores one summary row per eval / benchmark / live-quality run in tbl_ai_eval_runs (migration 035),
 * so the admin page can show results and regressions. Notes are redacted; no prompts or replies are stored.
 */
final class EvalRunLog
{
    public const KINDS = ['safety_eval', 'benchmark', 'live_quality'];

    /** @param array<string,mixed> $metrics small JSON-able summary */
    public static function save(?PDO $dbh, string $kind, int $pass, int $fail, int $leaked, array $metrics = [], string $provider = '', string $model = '', string $note = ''): bool
    {
        if (!$dbh || !in_array($kind, self::KINDS, true)) return false;
        require_once __DIR__ . '/PromptRedactor.php';
        try {
            $dbh->prepare('INSERT INTO tbl_ai_eval_runs (kind, provider, model, passed, failed, leaked, metrics_json, note) VALUES (?,?,?,?,?,?,?,?)')->execute([
                $kind, preg_replace('/[^a-z0-9_-]/i', '', mb_substr($provider, 0, 20)), preg_replace('/[^a-z0-9_.:\-]/i', '', mb_substr($model, 0, 60)),
                max(0, $pass), max(0, $fail), max(0, $leaked),
                mb_substr((string)json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 60000),
                PromptRedactor::forLog($note, 250),
            ]);
            return true;
        } catch (Throwable $e) { error_log('[TourBuddy AI evalrun] ' . $e->getMessage()); return false; }
    }

    /** @return array<int,array<string,mixed>> newest first; [] when the table is missing */
    public static function latest(PDO $dbh, ?string $kind = null, int $limit = 10): array
    {
        try {
            $sql = 'SELECT id, kind, provider, model, passed, failed, leaked, metrics_json, note, created_at FROM tbl_ai_eval_runs'
                 . ($kind !== null ? ' WHERE kind = ?' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit));
            $st = $dbh->prepare($sql); $st->execute($kind !== null ? [$kind] : []);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
