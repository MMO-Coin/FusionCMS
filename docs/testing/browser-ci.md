# Authentication and donation browser tests

The browser suite starts the real FusionCMS application and drives its normal login, remember-me, password recovery and donation forms. GitHub CI runs it on PHP 8.3.36, 8.4.27 and 8.5.12, with Chromium at desktop and Pixel 7 viewport sizes.

## What it covers

- Guests must sign in before viewing donation packages.
- A wrong password shows an error. A correct password opens the account panel, the session survives reload, and logout removes access. Session and CSRF cookie flags are checked.
- Remember-me restores a lost session with a fresh token while retaining the same database row. Only its hash is stored. Tests replay the previous cookie, expire a token on the server, and verify that rejected credentials are cleared. Legacy password-hash cookies and malformed or unknown tokens cannot restore a session.
- Logout revokes every remembered device for that account, including the successor created when the logout request itself restores a lost session. Password recovery also revokes all of the account's remembered devices. Both flows preserve another account's remembered login.
- Password recovery sends a message through the application's SMTP client to a local inbox. Its actual link accepts a valid reset once, survives a password-validation error, and rejects reuse, malformed tokens and tokens at the one-hour expiry boundary. The old password stops working and the new password signs in normally.
- A successful reset revokes already-active sessions as well as remembered credentials. Tests retain the original session cookies, verify both browsers lose access, replay an old session ID, and check that another account stays signed in. A same-password reset rejects a POST from a previously opened checkout without creating an invoice. A fresh login remains valid after the old ID is replayed. A password-validation error leaves existing sessions usable.
- A form POST renders the complete HTML checkout without reflecting request headers. The actual QR image decodes to the invoice's merchant, mint, amount and reference.
- The checkout's own JavaScript polls by POST and uses the renewed CSRF nonce on its next poll.
- A valid RPC response completes the invoice, reaches the success page, grants 25 DP and records income once. Three repeated verification requests leave those records unchanged.
- Another signed-in account cannot verify that invoice. GET verification, missing CSRF and invalid packages are rejected.
- RPC outages, underpayment, wrong-token, wrong-recipient and failed payment responses leave the invoice pending without granting points.

The controller, session, CSRF middleware, templates, JavaScript, payment verifier and database transaction all run normally. The RPC server is a local HTTPS fixture. It adapts recorded public devnet responses to each new invoice's reference and payment time. It does not sign or broadcast transactions, or replace the separate [mobile wallet test](solana-mobile-devnet-2026-11-01.md).

The SMTP inbox binds only to loopback and captures messages without relaying them. Test email addresses use `example.invalid`. Mailbox contents and generated configuration stay in the temporary runtime and are excluded from artifact uploads. Expiry tests age only the fixture token's issue time rather than waiting an hour. The database regression additionally forces two independent MySQL workers to read the same token before competing to claim it.

Reset links are valid for one hour from issuance. A valid token is consumed before the auth database password is updated, so a failed password update requires a new recovery link. The CMS and game account databases can live on different servers; consuming first prevents reuse across that boundary. This change uses the existing token timestamp and needs no database migration.

Remember-me uses the configured `cookie_expire` lifetime, which defaults to 30 days and renews on successful restoration. Rotation updates the existing row only if its old hash still matches and it remains unexpired. Logout and password recovery delete all remembered tokens for the account. This prevents a rotation that already read the token from inserting a replacement after revocation.

After updating the password, recovery changes a random account session version and deletes remembered tokens in one CMS transaction. Every authenticated request reads that version before loading account privileges or running a controller. A stale session loses its authentication and 2FA state, and its ID is rotated with old storage removed. Revocation takes effect on the next request; it does not cancel a request already executing. This check uses the shared website database and does not inspect serialized session storage, so it is independent of the configured session handler.

Login captures the version before verifying credentials, and remember-me restoration keeps the version captured before token rotation. Issuing a remember-me token checks the login's version again after insertion and removes the token if a reset changed it. These checks prevent an overlapping reset from granting an old login the new version or leaving behind a remembered token issued after revocation.

Existing sites must run `application/modules/install/SQL/updates/2026_11_01_01_account_session_versions.sql` before deploying this code. The fresh-install schema includes the table. Accounts with no revocation record retain the original empty version, so legacy sessions remain usable until their account is reset. Revocation records are durable security state, not disposable session or cache data.

`tests/remember_me_test.php` checks the model with SQLite, then uses independent MySQL workers to force overlapping reads. It verifies that exactly one rotation succeeds, revocation removes a completed successor, pending rotation cannot recreate a revoked row, and expiry changed between lookup and update is enforced. CI runs these checks on all three PHP versions. To run them locally, use `php tests/remember_me_test.php`; set `FUSION_TEST_MYSQL_HOST`, `FUSION_TEST_MYSQL_PORT` and `FUSION_TEST_MYSQL_PASSWORD` for a disposable loopback server containing `fusion_test` to include concurrency checks. The test creates and drops only its randomly prefixed table.

`php tests/account_session_test.php` checks account isolation, repeated resets, legacy sessions, stale remember-me claims, a reset overlapping password verification, and rollback if token deletion fails. With the same MySQL environment variables, it also runs revocation against InnoDB and verifies that reapplying the migration preserves existing versions. CI runs these checks on all three PHP versions.

## Run locally

Use a supported PHP version with the repository's required extensions, Node.js 20 or newer, Git, Composer dependencies and a MySQL or MariaDB server on loopback. The database user needs permission to create and drop the suite's temporary databases.

From the repository root:

```sh
composer install --prefer-dist --no-interaction
npm ci --prefix tests/browser
cd tests/browser
npx playwright install chromium
cd ../..
```

Then run on Linux or macOS:

```sh
FUSION_E2E_DB_PASSWORD=fusion_test npm test --prefix tests/browser
```

Or in PowerShell:

```powershell
$env:FUSION_E2E_DB_PASSWORD = 'fusion_test'
npm.cmd test --prefix tests/browser
```

The defaults are `127.0.0.1:3306`, database user `root`, test password `fusion_test`, site port `8098` and PHP executable `php`. Override them with `FUSION_E2E_DB_HOST`, `FUSION_E2E_DB_PORT`, `FUSION_E2E_DB_USER`, `FUSION_E2E_DB_PASSWORD`, `FUSION_E2E_PORT` and `FUSION_E2E_PHP`. For example, PowerShell can select a PHP executable with `$env:FUSION_E2E_PHP = 'C:\tools\php\php.exe'`.

Each run creates uniquely named `fusion_e2e_<random>_cms` and `fusion_e2e_<random>_auth` databases. Existing databases are never imported into. Resets and cleanup check an owner token before modifying fixture data. Only tracked working-tree runtime files are copied into a temporary site; local database, owner and fusion configuration is generated separately. CAPTCHA is disabled only in that copy, and CSRF protection stays enabled.

The local RPC endpoint uses a newly generated localhost certificate. PHP trusts that certificate for this process, with HTTPS certificate and hostname verification enabled. No wallet keys or public network connection are needed. Browser requests to external origins are blocked.

The runner stops the site and RPC server and removes its databases and temporary directory after testing. Run one suite at a time from a checkout.

## Reports and failures

Open `output/playwright/report/index.html` for the HTML report. Failed tests retain screenshots, video and traces in `output/playwright/results`. PHP and RPC diagnostics are in `output/playwright/logs`. GitHub uploads those directories as a separate artifact for each PHP version, retained for seven days.

The runtime manifest, generated configuration and ephemeral TLS private key are excluded from CI artifact uploads and Git. If the runner is forcibly terminated, its terminal output and temporary fixture configuration can help identify the remaining test resources; cleanup must still check fixture ownership before dropping a database.
