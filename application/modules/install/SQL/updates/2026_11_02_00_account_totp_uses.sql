-- Run against the website database before deploying the 2FA changes.
-- These durable high-water marks must survive session and cache cleanup.
CREATE TABLE IF NOT EXISTS `account_totp_uses` (
  `account_id` int(11) NOT NULL,
  `secret_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `time_step` bigint NOT NULL,
  PRIMARY KEY (`account_id`, `secret_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
