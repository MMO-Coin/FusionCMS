-- Apply only to an existing MMOCoin donation module database, with payments paused.
-- Back up the database first. Duplicate non-NULL payment signatures must be
-- reconciled before adding the unique index; this script never deletes them.
ALTER TABLE `donate_mmo_packages` MODIFY `price` decimal(20,9) NOT NULL;
ALTER TABLE `donate_mmo_transactions`
  MODIFY `reference` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  MODIFY `transaction_signature` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;

SET @mmo_schema_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donate_mmo_transactions' AND COLUMN_NAME = 'merchant_wallet') = 0,
  'ALTER TABLE `donate_mmo_transactions` ADD `merchant_wallet` varchar(44) DEFAULT NULL, ADD `token_mint` varchar(44) DEFAULT NULL, ADD `income_amount` int(11) DEFAULT NULL, ADD `expires_at` datetime DEFAULT NULL',
  'SELECT 1'
);
PREPARE mmo_schema_update FROM @mmo_schema_sql;
EXECUTE mmo_schema_update;
DEALLOCATE PREPARE mmo_schema_update;

UPDATE `donate_mmo_transactions` SET `status` = 'failed'
WHERE `status` = 'pending' AND (`merchant_wallet` IS NULL OR `token_mint` IS NULL OR `expires_at` IS NULL);

SET @mmo_index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'donate_mmo_transactions' AND INDEX_NAME = 'payment_signature') = 0,
  'ALTER TABLE `donate_mmo_transactions` ADD UNIQUE KEY `payment_signature` (`transaction_signature`)',
  'SELECT 1'
);
PREPARE mmo_index_update FROM @mmo_index_sql;
EXECUTE mmo_index_update;
DEALLOCATE PREPARE mmo_index_update;
