-- Per-call record of which model answered, so a model or version change shows up in failure rates and latency.
-- Stores no prompt or answer text. Additive only: nothing existing is altered.
CREATE TABLE IF NOT EXISTS `tbl_ai_model_calls` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `capability` varchar(30) NOT NULL DEFAULT '',
  `feature` varchar(60) NOT NULL DEFAULT '',
  `provider` varchar(30) NOT NULL,
  `model` varchar(120) NOT NULL,
  `model_digest` varchar(64) DEFAULT NULL,
  `model_size` bigint(20) DEFAULT NULL,
  `route_reason` varchar(30) NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `error_kind` varchar(20) DEFAULT NULL,
  `latency_ms` int(11) NOT NULL DEFAULT 0,
  `prompt_tokens` int(11) DEFAULT NULL,
  `completion_tokens` int(11) DEFAULT NULL,
  `context_dropped` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_model_created` (`model`, `created_at`),
  KEY `idx_cap_created` (`capability`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
