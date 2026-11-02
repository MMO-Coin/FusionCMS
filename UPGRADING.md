# Upgrading the MMO-Coin FusionCMS fork

This guide covers the `9.5.2` release of [MMO-Coin/FusionCMS](https://github.com/MMO-Coin/FusionCMS), including upgrades from `9.5.1` and the `9.5.0-mmo.1` preview. It includes upstream FusionCMS 9.5.0 changes and the MMOCoin donation module 1.1.1.

## Before you upgrade

The public list update fixes news, inbox and sent pagination, bounds account search pages, and loads comments in batches of 25. News follows newest article ID first; messages use time and ID ordering. Existing news and message page caches are bypassed automatically. This update does not need an additional database migration. Keep the log index migration below if you have not applied it yet.

Clear compiled templates after updating registration and recovery forms. If you maintain a custom registration template, merge the linked field errors, input attributes and text feedback from the bundled template. The bundled Heaven registration override is included. Custom language packs also need the new comment, registration and recovery feedback keys from the English files.

On existing sites, apply these pagination updates from `main` to the **website database**:

- `application/modules/install/SQL/updates/2026_11_02_02_log_query_indexes.sql` adds indexes for account log ordering, website log ordering and article comments.
- `application/modules/install/SQL/updates/2026_11_02_03_list_query_indexes.sql` adds two store order indexes and two GM history indexes. See the [list performance guide](docs/list-performance.md) for details.

Both scripts preserve existing rows, can be rerun and reuse equivalent custom indexes. Fresh installations already include these indexes. The scripts do not change game databases. On large tables, schedule index creation during a maintenance window because it can take time and may block writes depending on the database version.

Email logs now show 25 records per page, newest ID first, with search handled by the server. Account and website log pages use timestamp and ID ordering, and requests cannot select more than 100 records at once. Clear compiled templates after deploying the login labels and keyboard password toggle.

The Armory count cache requires no SQL migration or game database changes. With the global `cache` setting enabled, totals can lag database changes by up to 30 seconds while fetched rows stay fresh. Submit Search again, or change the realm or result type, to refresh totals immediately. Browsing pages does not renew the expiry, and setting `cache` to `false` disables count reuse. See the [Armory search notes](docs/admin-assets.md#armory-search) for the cache limit and fallback behavior.

The bundled English, French, Persian, Portuguese and Spanish language files include the new login and log navigation labels. Merge those new keys from the English files into any custom language packs you maintain.

1. Test the upgrade on a separate copy of your site and website database. Pause donations and other writes while upgrading the actual site.
2. Back up the website database and the current site files together. Keep your database configuration, `application/config/fusion.php`, `application/config/owner.php`, donation settings, custom themes and uploaded files. Store backups outside the public web directory.
3. Install PHP 8.3.36+, 8.4.27+ or 8.5.12+ within those branches, with the extensions listed in the README. Check the PHP runtime used by the website as well as the command line. On Windows, use the updated `-1` packages or newer builds for the Brotli fix.
4. For the additional session-upload advisory, set `session.upload_progress.enabled=0` in the server's PHP configuration and restart PHP workers. This setting must apply before request parsing; setting it in application code is too late. See the [PHP security notices](https://www.php.net/archive/2026.php).

The version check uses upstream PHP version numbers. It does not recognize distribution backports or detect Windows package rebuilds.

Changes on `main` after `9.5.2` expire password reset links after one hour and harden remembered logins. Logout and password recovery revoke every remembered device for that account. Password recovery and password changes in account settings revoke all active PHP sessions and remembered devices, even when the same password is chosen. Each browser loses access on its next request and must sign in again. Legacy `fcms_password` cookies no longer sign users in; affected users must enter their password again.

Before deploying `main` to an existing site, run `application/modules/install/SQL/updates/2026_11_01_01_account_session_versions.sql` against the **website database**. The script adds the account revocation table and can be rerun without changing existing records. Fresh installations already include it. Keep this table when clearing session storage or caches; deleting its records can revalidate older sessions. Back it up with the website database.

PHP's cURL runtime must have a working CA trust store for the configured HTTPS RPC provider. Configure `curl.cainfo` when your PHP distribution does not provide one. Keep TLS certificate verification enabled.

The 2FA changes on `main` also require `application/modules/install/SQL/updates/2026_11_02_00_account_totp_uses.sql` in the **website database**, before deploying the new files. Fresh installations include this table. Keep its records during session or cache cleanup and back it up with the website database. Removing them can make a recently used code acceptable again. The migration can be rerun without resetting previous claims.

Changing 2FA now requires the current password and a new authenticator code. Setup expires after 10 minutes; reload the page and scan the new QR code if it expires. Disable the current authenticator before enrolling a replacement. Successful changes sign out every browser and remembered device. Codes used for setup or login cannot be reused for another website action; wait for the app's next code.

For `totp_secret` accounts using TrinityCore encryption, `TOTPMasterSecret` must match the authserver's hexadecimal 128-bit key. The CMS now reads and writes authenticated AES-128-GCM envelopes with the IV and tag retained. Earlier CMS code discarded that metadata; entries produced by that broken encryption path cannot be recovered from the stored ciphertext alone. Restore a known-good secret or use your account recovery procedure on a test copy before upgrading affected accounts. Base32 `token_key` and unencrypted binary `totp_secret` accounts remain supported. Check sign-in against the actual game server before enabling encrypted enrollment for public users.

Replay protection is shared by website workers using the same CMS database. It does not record authenticator codes accepted directly by a separate game authserver.

The Account Security page requires `application/modules/install/SQL/updates/2026_11_02_01_account_devices.sql` in the **website database** before deployment. It adds a session inventory and browser/IP/last-restored metadata to remembered logins. It preserves existing tokens and can be rerun. Fresh installations include the schema. Apply the earlier remember-me, session-version and TOTP migrations first when upgrading from an older release.

Account Security remains available with 2FA disabled. It shows up to 100 recent verified sessions and 100 unexpired remembered logins belonging to the signed-in account. Existing sessions appear on their next authenticated visit; old remembered tokens show unknown browser details until restored. A closed browser can remain listed until its configured session inactivity window ends. Browser names and IPs come from requests and are approximate identifiers.

The inventory stores a hash of a separate random tracking token, not a PHP session ID. It works with database, file, Redis and other configured PHP session handlers without reading serialized session contents. Expired inventory is pruned on account-page visits and in bounded batches during normal traffic. A successful new login destroys this browser's previous session ID and replaces its inventory entry. Registry entries are display metadata; clearing them does not sign users out. Use Sign out everywhere to change the durable account revocation version and remove remembered tokens. That action also ends the current browser session. Other requests lose access on their next authentication check; requests already executing are not cancelled. Include the new metadata in your site's retention and backup policy.

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
