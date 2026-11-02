-- Run against the website database before deploying the Account Security page.
CREATE TABLE IF NOT EXISTS `account_sessions` (
  `session_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` int(11) NOT NULL,
  `version` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`session_hash`),
  INDEX `idx_account_sessions` (`account_id`, `version`, `expires_at`),
  INDEX `idx_session_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL and MariaDB compatible, preserving existing remembered tokens on rerun.
SET @device_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_me_tokens' AND COLUMN_NAME = 'ip_address'), 'SELECT 1', 'ALTER TABLE `remember_me_tokens` ADD COLUMN `ip_address` varchar(45) NOT NULL DEFAULT ''''');
PREPARE device_stmt FROM @device_ddl;
EXECUTE device_stmt;
DEALLOCATE PREPARE device_stmt;
SET @device_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_me_tokens' AND COLUMN_NAME = 'user_agent'), 'SELECT 1', 'ALTER TABLE `remember_me_tokens` ADD COLUMN `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''''');
PREPARE device_stmt FROM @device_ddl;
EXECUTE device_stmt;
DEALLOCATE PREPARE device_stmt;
SET @device_ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'remember_me_tokens' AND COLUMN_NAME = 'last_used_at'), 'SELECT 1', 'ALTER TABLE `remember_me_tokens` ADD COLUMN `last_used_at` datetime NULL DEFAULT NULL');
PREPARE device_stmt FROM @device_ddl;
EXECUTE device_stmt;
DEALLOCATE PREPARE device_stmt;
