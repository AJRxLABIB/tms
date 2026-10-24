<?php
/**
 * AIConfig — the few engine settings an administrator may change at runtime, from admin/ai-dashboard.php.
 *
 * Deliberately small and one-directional where safety is involved:
 *   - Admins can SWITCH THINGS OFF (assistant, memory, failover) and tighten or loosen the step/time limits within
 *     fixed bounds. They cannot switch ON anything that sends data to a new vendor: the cloud-fallback opt-in
 *     (AI_FALLBACK_ALLOW_EXTERNAL) and provider API keys stay in .env, and price/citation verification and the
 *     injection guard have no switch at all.
 *   - Every change is validated, bounded and written to tbl_ai_config_log (who, when, old, new).
 *   - Values live in tbl_app_meta ('ai_cfg_<name>'). A storage failure falls back to the defaults below.
 */
final class AIConfig
{
    /** name => [type, default, min, max, label] */
    public const SPEC = [
        'assistant_enabled' => ['bool', 1, 0, 1, 'Chat assistant (AI answers)'],
        'memory_enabled'    => ['bool', 1, 0, 1, 'Saved travel memory'],
        'failover_enabled'  => ['bool', 1, 0, 1, 'Provider failover (only matters if AI_FALLBACK_PROVIDERS is set)'],
        'max_steps'         => ['int', 4, 1, 6, 'Max tool steps per answer'],
        'deadline_sec'      => ['int', 8, 2, 15, 'Max seconds for tool steps per answer'],
    ];
    private static array $cache = [];

    public static function migrate(PDO $dbh): void
    {
        $dbh->exec("CREATE TABLE IF NOT EXISTS `tbl_ai_config_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `setting` varchar(40) NOT NULL,
  `old_value` varchar(20) NOT NULL,
  `new_value` varchar(20) NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cfg_changed` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function get(?PDO $db, string $name): int
    {
        if (!isset(self::SPEC[$name])) throw new InvalidArgumentException('unknown setting');
        $def = self::SPEC[$name][1];
        if (!$db) return $def;
        if (isset(self::$cache[$name])) return self::$cache[$name];
        try {
            $st = $db->prepare('SELECT v FROM tbl_app_meta WHERE k = ?'); $st->execute(['ai_cfg_' . $name]);
            $v = $st->fetchColumn();
            $val = ($v === false || !preg_match('/^-?\d+$/', (string)$v)) ? $def : self::clamp($name, (int)$v);
        } catch (Throwable $e) { $val = $def; }
        return self::$cache[$name] = $val;
    }

    public static function enabled(?PDO $db, string $name): bool { return self::get($db, $name) === 1; }
    public static function failoverEnabled(?PDO $db): bool { return self::enabled($db, 'failover_enabled'); }
    public static function clearCache(): void { self::$cache = []; }

    private static function clamp(string $name, int $v): int { return max(self::SPEC[$name][2], min(self::SPEC[$name][3], $v)); }

    /** @return array{ok:bool,error?:string,old?:int,new?:int} */
    public static function set(PDO $db, string $name, $raw, string $admin): array
    {
        if (!isset(self::SPEC[$name])) return ['ok' => false, 'error' => 'unknown_setting'];
        [$type, , $min, $max] = self::SPEC[$name];
        $s = is_bool($raw) ? ($raw ? '1' : '0') : trim((string)$raw);
        if (!preg_match('/^\d+$/', $s)) return ['ok' => false, 'error' => 'not_a_number'];
        $v = (int)$s;
        if ($v < $min || $v > $max) return ['ok' => false, 'error' => "out_of_range_{$min}_{$max}"];
        $old = self::get($db, $name);
        self::$cache[$name] = $v;
        try {
            $db->prepare('INSERT INTO tbl_app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute(['ai_cfg_' . $name, (string)$v]);
            if ($old !== $v) $db->prepare('INSERT INTO tbl_ai_config_log (setting, old_value, new_value, changed_by) VALUES (?,?,?,?)')->execute([$name, (string)$old, (string)$v, mb_substr($admin, 0, 100)]);
        } catch (Throwable $e) { self::$cache = []; error_log('[TourBuddy AI config] ' . $e->getMessage()); return ['ok' => false, 'error' => 'storage']; }
        return ['ok' => true, 'old' => $old, 'new' => $v];
    }

    /** Restore the default (deletes the stored value; logged like any change). */
    public static function reset(PDO $db, string $name, string $admin): array
    {
        if (!isset(self::SPEC[$name])) return ['ok' => false, 'error' => 'unknown_setting'];
        $r = self::set($db, $name, self::SPEC[$name][1], $admin);
        if ($r['ok']) { try { $db->prepare('DELETE FROM tbl_app_meta WHERE k = ?')->execute(['ai_cfg_' . $name]); } catch (Throwable $e) {} }
        return $r;
    }

    /** @return array<int,array{setting:string,old_value:string,new_value:string,changed_by:string,changed_at:string}> */
    public static function recentChanges(PDO $db, int $limit = 15): array
    {
        try { return $db->query('SELECT setting, old_value, new_value, changed_by, changed_at FROM tbl_ai_config_log ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }
}
