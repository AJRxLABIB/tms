-- 010_ai_feedback.sql
-- Round 115, Phase E (master prompt item 14 — AI feedback loop).
--
-- A lightweight, non-invasive thumbs up/down + optional reason, tied to
-- whichever AI feature produced the content (recommend, itinerary, etc.)
-- and a feature-specific context reference (a package id for a
-- recommendation, a booking id for an itinerary, ...). Deliberately not a
-- foreign key to tbl_ai_telemetry: telemetry rows are per-API-call and
-- this project's frontend doesn't currently get a telemetry row's id back
-- in its API responses, so requiring that link would mean changing every
-- AI handler's response shape just to support feedback. This is simpler,
-- additive, and already enough to power a real per-feature satisfaction
-- rate for the future AI Quality Dashboard (master prompt item 15).
CREATE TABLE IF NOT EXISTS `tbl_ai_feedback` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_email` VARCHAR(100) DEFAULT NULL,
  `feature` VARCHAR(60) NOT NULL COMMENT 'e.g. recommend, itinerary',
  `context_ref` VARCHAR(120) DEFAULT NULL COMMENT 'feature-specific id, e.g. package id or booking id -- free-form, not a real FK',
  `rating` TINYINT(1) NOT NULL COMMENT '1 = thumbs up, -1 = thumbs down',
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ai_feedback_feature` (`feature`),
  INDEX `idx_ai_feedback_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
