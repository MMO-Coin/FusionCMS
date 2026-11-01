MMOCoin Donation Module 1.1.0

This module accepts MMOCoin payments through Solana Pay transfer links.
Funds go to the configured merchant wallet. The website does not need a
wallet private key or a connection to the payer's wallet.

INSTALLATION

For a fresh module installation, enable donate_mmo and run sql/install.sql
against the FusionCMS website database. Configure the module before opening
donations. Existing module 1.0 users must follow the upgrade instructions;
replacing files alone is not enough.

Upgrade and rollback guide:
https://github.com/MMO-Coin/FusionCMS/blob/main/UPGRADING.md

CONFIGURATION

Edit config/donate_mmo.php:

- merchant_wallet: the Solana wallet that receives the tokens.
- mmo_coin_mint: the MMOCoin mint accepted by this site.
- rpc_url: a trusted HTTPS Solana RPC endpoint. Public endpoints may be
  rate limited; choose a provider that can handle your checkout traffic.
- exchange_rate: income tracking units per MMOCoin. The package's points
  field determines the donation points granted to the account.

Only classic SPL Token payments are supported. Package prices allow up to
nine decimal places. Donation points must be positive whole numbers.
Grant viewAdmin and the add, edit and delete package permissions only to
the staff groups that manage donations.

PAYMENT VERIFICATION

Creating an invoice stores its account owner, amount, points, merchant,
mint, income and 60-minute expiry. Later configuration changes do not
change an existing invoice's payment destination or point amount.

The checkout polls by authenticated POST with a CSRF token. A payment
must be finalized, successful, include the invoice reference, fall within
the invoice period and increase the merchant's balance of the correct
token by at least the invoiced amount. Amounts are checked as integer
token units. A matching signature alone is not proof of payment.

Invoice completion, donation points and income are committed together.
A signature can complete only one invoice, and repeated checks cannot
grant points again. Failed or unavailable verification leaves it pending.

The module checks the five newest signatures for a reference. Payments
outside that window, old invoices, expired invoices and payments that
finalize too late need manual reconciliation. Keep your points ledger
and on-chain records when handling these cases. Do not resend a payment
just because the RPC endpoint is temporarily unavailable.

MODULE 1.0 UPGRADE

Back up the website database and pause donations. Check for duplicate
recorded signatures, then run sql/upgrade_1_1.sql. It can be rerun after a
successful upgrade. Legacy pending invoices are marked failed because
they lack the verification snapshots required by version 1.1.0. Completed
history is preserved. Ask users to create new invoices and reconcile any
payments already sent to old invoices before reopening donations.
