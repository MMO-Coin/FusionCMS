-- Run this against the existing FusionCMS website database before deploying
-- the rotating remember-me token feature. Existing token data is preserved.
CREATE TABLE IF NOT EXISTS `remember_me_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL COMMENT 'SHA-256 hash of the random token',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_token_hash` (`token_hash`),
  INDEX `idx_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
