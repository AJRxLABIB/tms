-- 035_ai_eval_runs.sql
-- One summary row per AI evaluation / benchmark / live-quality run (counts and small metric JSON only; notes are redacted).
-- Additive: new table, nothing existing is altered.
CREATE TABLE IF NOT EXISTS `tbl_ai_eval_runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(20) NOT NULL,
  `provider` varchar(20) NOT NULL DEFAULT '',
  `model` varchar(60) NOT NULL DEFAULT '',
  `passed` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `leaked` int(11) NOT NULL DEFAULT 0,
  `metrics_json` mediumtext DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_evalruns_kind_time` (`kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
