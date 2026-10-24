-- 033_ai_action_proposals.sql
-- Confirmable action proposals created by the assistant's server-side code (never by the model).
-- A proposal never changes a booking; it records that the signed-in visitor was offered an action and what they decided.
CREATE TABLE IF NOT EXISTS `tbl_ai_action_proposals` (
  `ProposalId` bigint(20) NOT NULL AUTO_INCREMENT,
  `UserEmail` varchar(100) NOT NULL,
  `ActionType` varchar(30) NOT NULL,
  `Params` varchar(500) NOT NULL,
  `Summary` varchar(300) NOT NULL,
  `IdempotencyKey` char(64) NOT NULL,
  `Status` varchar(15) NOT NULL DEFAULT 'proposed',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `ExpiresAt` datetime NOT NULL,
  `DecidedAt` datetime DEFAULT NULL,
  `Result` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`ProposalId`),
  UNIQUE KEY `uq_ai_action_key` (`IdempotencyKey`),
  KEY `idx_ai_action_user` (`UserEmail`, `Status`, `CreatedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
