<?php
/**
 * AITurnLog — one anonymous row per assistant turn, for measuring the engine (not the people using it).
 * Stores NO message text, NO email and NO user id: only counts and categories. Rows are deleted after 90 days.
 * Writing is best-effort and never affects the reply.
 */
final class AITurnLog
{
    public const RETENTION_DAYS = 90;
    /** Set by the quality-eval runner so synthetic questions never reach the production metrics. */
    public static bool $suppress = false;
    private const OUTCOMES = ['answered', 'guard_blocked', 'memory_command', 'model_error'];

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_ai_turn_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `outcome` varchar(16) NOT NULL,
  `signed_in` tinyint(1) NOT NULL DEFAULT 0,
  `language` char(2) NOT NULL DEFAULT 'en',
  `steps` tinyint(4) NOT NULL DEFAULT 0,
  `follow_ups` tinyint(4) NOT NULL DEFAULT 0,
  `tool_errors` tinyint(4) NOT NULL DEFAULT 0,
  `stopped` varchar(12) DEFAULT NULL,
  `tools` varchar(200) NOT NULL DEFAULT '',
  `unverified_prices` tinyint(4) NOT NULL DEFAULT 0,
  `removed_markers` tinyint(4) NOT NULL DEFAULT 0,
  `sources` tinyint(4) NOT NULL DEFAULT 0,
  `memory_items` tinyint(4) NOT NULL DEFAULT 0,
  `provider` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_turn_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** @param array<string,mixed> $d */
    public static function record(?PDO $dbh, array $d): void
    {
        if (!$dbh || self::$suppress) return;
        try {
            $trace = is_array($d['trace'] ?? null) ? $d['trace'] : [];
            $tools = implode(',', array_map(fn($x) => preg_replace('/[^a-z_]/', '', (string)($x['tool'] ?? '')), $trace));
            $dbh->prepare('INSERT INTO tbl_ai_turn_log (outcome,signed_in,language,steps,follow_ups,tool_errors,stopped,tools,unverified_prices,removed_markers,sources,memory_items,provider)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                in_array($d['outcome'] ?? '', self::OUTCOMES, true) ? $d['outcome'] : 'answered',
                !empty($d['signed_in']) ? 1 : 0,
                ($d['language'] ?? 'en') === 'bn' ? 'bn' : 'en',
                min(127, count($trace)),
                min(127, count(array_filter($trace, fn($x) => !empty($x['follow_up'])))),
                min(127, count(array_filter($trace, fn($x) => isset($x['ok']) && !$x['ok']))),
                in_array($d['stopped'] ?? null, ['step_limit', 'deadline'], true) ? $d['stopped'] : null,
                mb_substr($tools, 0, 200),
                min(127, (int)($d['unverified_prices'] ?? 0)),
                min(127, (int)($d['removed_markers'] ?? 0)),
                min(127, (int)($d['sources'] ?? 0)),
                min(127, (int)($d['memory_items'] ?? 0)),
                preg_replace('/[^a-z0-9_-]/i', '', mb_substr((string)($d['provider'] ?? ''), 0, 20)),
            ]);
            if (random_int(1, 200) === 1) $dbh->exec('DELETE FROM tbl_ai_turn_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::RETENTION_DAYS . ' DAY)');
        } catch (Throwable $e) { error_log('[TMS AI turnlog] ' . $e->getMessage()); }
    }

    /** @return array<string,mixed>|null null when the table is unavailable */
    public static function summary(PDO $dbh, int $days = 7): ?array
    {
        $days = max(1, min(90, $days));
        try {
            $r = $dbh->query("SELECT COUNT(*) turns,
                SUM(outcome='answered') answered, SUM(outcome='guard_blocked') blocked, SUM(outcome='memory_command') memcmd, SUM(outcome='model_error') errors,
                SUM(outcome='answered' AND steps>1) multistep, SUM(stopped IS NOT NULL) stopped_early, SUM(tool_errors>0) tool_err,
                SUM(outcome='answered' AND unverified_prices>0) unverified, SUM(removed_markers>0) markers, SUM(outcome='answered' AND sources>0) with_sources,
                SUM(language='bn') bn, SUM(signed_in=1) signed, SUM(memory_items>0) used_memory,
                AVG(CASE WHEN outcome='answered' THEN steps END) avg_steps
                FROM tbl_ai_turn_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)")->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $tools = [];
            foreach ($dbh->query("SELECT tools FROM tbl_ai_turn_log WHERE tools<>'' AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN) as $t)
                foreach (explode(',', $t) as $x) if ($x !== '') $tools[$x] = ($tools[$x] ?? 0) + 1;
            arsort($tools);
            $r = array_map(fn($v) => $v === null ? 0 : $v + 0, $r);
            $r['tools'] = $tools; $r['days'] = $days;
            return $r;
        } catch (Throwable $e) { return null; }
    }

    /** Most recent turns, counts only (no text, no user). @return array<int,array<string,mixed>> */
    public static function recent(PDO $dbh, int $limit = 25): array
    {
        try { return $dbh->query('SELECT created_at, outcome, language, steps, follow_ups, stopped, tools, unverified_prices, removed_markers, sources, memory_items, provider FROM tbl_ai_turn_log ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }
}
