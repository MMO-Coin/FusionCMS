CREATE TABLE IF NOT EXISTS `donate_mmo_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `price` decimal(20,9) NOT NULL,
  `points` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `donate_mmo_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `mmo_amount` decimal(20,9) NOT NULL,
  `dp_amount` int(11) NOT NULL,
  `reference` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `merchant_wallet` varchar(44) NOT NULL,
  `token_mint` varchar(44) NOT NULL,
  `income_amount` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending',
  `transaction_signature` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  UNIQUE KEY `payment_signature` (`transaction_signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

