# Upgrading the MMO-Coin FusionCMS fork

This guide covers the `9.5.2` release of [MMO-Coin/FusionCMS](https://github.com/MMO-Coin/FusionCMS), including upgrades from `9.5.1` and the `9.5.0-mmo.1` preview. It includes upstream FusionCMS 9.5.0 changes and the MMOCoin donation module 1.1.1.

## Before you upgrade

1. Test the upgrade on a separate copy of your site and website database. Pause donations and other writes while upgrading the actual site.
2. Back up the website database and the current site files together. Keep your database configuration, `application/config/fusion.php`, `application/config/owner.php`, donation settings, custom themes and uploaded files. Store backups outside the public web directory.
3. Install PHP 8.3.36+, 8.4.27+ or 8.5.12+ within those branches, with the extensions listed in the README. Check the PHP runtime used by the website as well as the command line. On Windows, use the updated `-1` packages or newer builds for the Brotli fix.
4. For the additional session-upload advisory, set `session.upload_progress.enabled=0` in the server's PHP configuration and restart PHP workers. This setting must apply before request parsing; setting it in application code is too late. See the [PHP security notices](https://www.php.net/archive/2026.php).

The version check uses upstream PHP version numbers. It does not recognize distribution backports or detect Windows package rebuilds.

PHP's cURL runtime must have a working CA trust store for the configured HTTPS RPC provider. Configure `curl.cainfo` when your PHP distribution does not provide one. Keep TLS certificate verification enabled.

If upgrading from `9.5.1`, deploy the updated files while preserving local configuration, then clear compiled templates. No database migration is needed for this patch. It fixes checkout responses that could copy request headers and download a truncated file instead of rendering HTML.

If upgrading from `9.5.0-mmo.1`, deploy the updated files and clear compiled templates. The donation schema has not changed since that preview. The request limiter creates `writable/cache/throttle`; PHP must be able to write there. Its limits are shared by workers using the same filesystem. Installations with multiple web nodes need shared storage for that directory to enforce one limit across nodes.

## Update an existing FusionCMS site

1. Deploy the release files while preserving the local configuration and customizations you backed up. Keep public traffic paused until the SQL steps finish.
2. Run `application/modules/install/SQL/updates/2026_11_01_00_remember_me_tokens.sql` against the **website database**. The script creates the new remember-me table without replacing an existing table.
3. If you use the MMOCoin module, complete the module upgrade below before reopening donations. Sites that do not use it do not need its SQL scripts.
4. If Composer is available, run:

   ```sh
   composer install --no-dev --prefer-dist --no-interaction
   composer validate --strict
   composer audit --locked --no-interaction
   composer check-platform-reqs --no-dev
   ```

5. Clear the CMS and compiled template caches through the admin panel. Do not delete uploaded files or payment records.
6. Check sign-in, remember-me, sign-out, 2FA, account settings and the admin panel on the test copy. Check that an account awaiting 2FA cannot call protected AJAX endpoints.

**Do not import `fusion_final_full.sql` into an existing site.** It contains statements that drop existing tables. It is for fresh installations.

## Upgrade an existing MMOCoin module

1. Save the configured merchant wallet, token mint, HTTPS RPC endpoint and exchange rate. Keep donation traffic paused.
2. Check for duplicate recorded payment signatures:

   ```sql
   SELECT BINARY transaction_signature AS transaction_signature, COUNT(*) AS invoices
   FROM donate_mmo_transactions
   WHERE transaction_signature IS NOT NULL
   GROUP BY BINARY transaction_signature
   HAVING COUNT(*) > 1;
   ```

   If this returns rows, reconcile those records and any points already granted before continuing. The upgrade deliberately fails when a duplicate prevents the unique index from being created. It does not delete payment history to hide duplicates.
3. Run `application/modules/donate_mmo/sql/upgrade_1_1.sql` against the website database. It changes package prices to decimals, adds invoice snapshots and expiry, and makes payment signatures unique. It can be rerun after a successful upgrade.
4. Pending invoices from module 1.0 are marked failed because they do not contain the wallet, mint and expiry snapshots required for verification. Ask users to create new invoices. Reconcile payments sent to old or expired invoices manually against the chain and your points ledger.
5. Restore the module configuration and grant the new MMOCoin admin permissions to the appropriate staff groups. Clear the ACL cache through the admin panel if permissions do not appear immediately.
6. On an isolated test setup, check a valid payment and verify that underpayments, wrong-token payments and repeated verification do not grant extra points. Test RPC outages and a pending invoice reaching its 60-minute expiry.

The module accepts finalized payments from the classic SPL Token program. Verification checks the invoice reference, signature, merchant, mint, payment time and net received token units. It examines the five newest signatures for a reference. Payments outside that window, payments finalized after invoice expiry, and unavailable or incomplete RPC results require a retry or manual reconciliation. Treat the configured RPC provider as part of your trusted infrastructure.

The checkout and admin forms use POST with CSRF tokens. Reload the page if another request changes the session token. QR codes are rendered with the bundled PHP library.

## Fresh installation

Use the normal FusionCMS installer with a new website database. If enabling MMOCoin donations, run the module's `sql/install.sql`, configure its wallet and RPC settings, then grant staff permissions. Do not use the module upgrade script as a substitute for creating its tables.

## Rollback

Keep the site paused and restore the matching **database and file backup** together, including configuration. Reconcile any on-chain payments received during the upgrade before reopening donations. A file-only rollback can mix incompatible invoice schemas and verification code. Keep PHP on a patched release when rolling back application files.

## Validation for contributors

The CI workflow targets PHP 8.3.36, 8.4.27 and 8.5.12, audits locked dependencies and runs the configuration, PHP requirement, HTTP response, CSRF, rate limit and donation regression tests. It uses a disposable MySQL database named `fusion_test` for schema and transaction checks. Transaction fixtures replay recorded devnet responses without network access or signing keys.

For local tests without MySQL, run `php tests/donation_security_test.php` with SQLite3 enabled. To include the MySQL-specific migrations, set `FUSION_TEST_MYSQL_HOST`, `FUSION_TEST_MYSQL_PORT` and `FUSION_TEST_MYSQL_PASSWORD` for a disposable server containing a database named `fusion_test`. The test drops and recreates its tables. Never point it at a database containing real site data.

Automated fixtures do not replace a wallet-to-site test against your chosen RPC provider. The [2026-11-01 devnet report](docs/testing/solana-devnet-2026-11-01.md) records the browser and public-chain checks completed for this release.
