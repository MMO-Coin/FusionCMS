-- Run against the website database before deploying session revocation.
-- Keep this table when clearing session storage or caches: its records revoke old sessions.
CREATE TABLE IF NOT EXISTS `account_session_versions` (
  `account_id` int(11) NOT NULL,
  `version` char(64) NOT NULL COMMENT 'Random session revocation version',
  PRIMARY KEY (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
