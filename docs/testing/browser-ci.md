# Browser and database tests

The browser suite starts the real FusionCMS application and checks account forms, donations, public lists, the store and admin pages. GitHub CI runs it on PHP 8.3.36, 8.4.27 and 8.5.12, with Chromium at desktop and Pixel 7 viewport sizes.

## What it covers

- Guests must sign in before viewing donation packages.
- A wrong password shows an error. A correct password opens the account panel, the session survives reload, and logout removes access. Session and CSRF cookie flags are checked.
- Remember-me restores a lost session with a fresh token while retaining the same database row. Only its hash is stored. Tests replay the previous cookie, expire a token on the server, and verify that rejected credentials are cleared. Legacy password-hash cookies and malformed or unknown tokens cannot restore a session.
- Logout revokes every remembered device for that account, including the successor created when the logout request itself restores a lost session. Password recovery also revokes all of the account's remembered devices. Both flows preserve another account's remembered login.
- Password recovery sends a message through the application's SMTP client to a local inbox. Its actual link accepts a valid reset once, survives a password-validation error, and rejects reuse, malformed tokens and tokens at the one-hour expiry boundary. The old password stops working and the new password signs in normally.
- A successful reset revokes already-active sessions as well as remembered credentials. Tests retain the original session cookies, verify both browsers lose access, replay an old session ID, and check that another account stays signed in. A same-password reset rejects a POST from a previously opened checkout without creating an invoice. A fresh login remains valid after the old ID is replayed. A password-validation error leaves existing sessions usable.
- Password changes use the actual account settings form and its confirmation dialogs. A wrong current password or invalid new password leaves sessions and remembered tokens usable. A successful change, including choosing the same password, revokes other active sessions and remembered devices, rejects old session IDs and tokens, and blocks a previously opened checkout from writing an invoice. The current browser returns to sign-in, another account stays signed in, and a fresh remembered login with the new password restores normally.
- A form POST renders the complete HTML checkout without reflecting request headers. The actual QR image decodes to the invoice's merchant, mint, amount and reference.
- The checkout's own JavaScript polls by POST and uses the renewed CSRF nonce on its next poll.
- A valid RPC response completes the invoice, reaches the success page, grants 25 DP and records income once. Three repeated verification requests leave those records unchanged.
- Another signed-in account cannot verify that invoice. GET verification, missing CSRF and invalid packages are rejected.
- RPC outages, underpayment, wrong-token, wrong-recipient and failed payment responses leave the invoice pending without granting points.

The controller, session, CSRF middleware, templates, JavaScript, payment verifier and database transaction all run normally. The RPC server is a local HTTPS fixture. It adapts recorded public devnet responses to each new invoice's reference and payment time. It does not sign or broadcast transactions, or replace the separate [mobile wallet test](solana-mobile-devnet-2026-11-01.md).

The SMTP inbox binds only to loopback and captures messages without relaying them. Test email addresses use `example.invalid`. Mailbox contents and generated configuration stay in the temporary runtime and are excluded from artifact uploads. Expiry tests age only the fixture token's issue time rather than waiting an hour. The database regression additionally forces two independent MySQL workers to read the same token before competing to claim it.

Reset links are valid for one hour from issuance. A valid token is consumed before the auth database password is updated, so a failed password update requires a new recovery link. The CMS and game account databases can live on different servers; consuming first prevents reuse across that boundary. This change uses the existing token timestamp and needs no database migration.

Remember-me uses the configured `cookie_expire` lifetime, which defaults to 30 days and renews on successful restoration. Rotation updates the existing row only if its old hash still matches and it remains unexpired. Logout and password recovery delete all remembered tokens for the account. This prevents a rotation that already read the token from inserting a replacement after revocation.

After updating the password, recovery and account settings change a random account session version and delete remembered tokens in one CMS transaction. Every authenticated request reads that version before loading account privileges or running a controller. A stale session loses its authentication and 2FA state, and its ID is rotated with old storage removed. Revocation takes effect on the next request; it does not cancel a request already executing. This check uses the shared website database and does not inspect serialized session storage, so it is independent of the configured session handler.

Login captures the version before verifying credentials, and remember-me restoration keeps the version captured before token rotation. Issuing a remember-me token checks the login's version again after insertion and removes the token if a reset changed it. These checks prevent an overlapping reset from granting an old login the new version or leaving behind a remembered token issued after revocation.

Existing sites must run `application/modules/install/SQL/updates/2026_11_01_01_account_session_versions.sql` before deploying this code. The fresh-install schema includes the table. Accounts with no revocation record retain the original empty version, so legacy sessions remain usable until their password is reset or changed. Revocation records are durable security state, not disposable session or cache data. Account settings use this same table and need no further migration.

`tests/remember_me_test.php` checks the model with SQLite, then uses independent MySQL workers to force overlapping reads. It verifies that exactly one rotation succeeds, revocation removes a completed successor, pending rotation cannot recreate a revoked row, and expiry changed between lookup and update is enforced. CI runs these checks on all three PHP versions. To run them locally, use `php tests/remember_me_test.php`; set `FUSION_TEST_MYSQL_HOST`, `FUSION_TEST_MYSQL_PORT` and `FUSION_TEST_MYSQL_PASSWORD` for a disposable loopback server containing `fusion_test` to include concurrency checks. The test creates and drops only its randomly prefixed table.

`php tests/account_session_test.php` checks account isolation, repeated resets, legacy sessions, stale remember-me claims, a reset overlapping password verification, and rollback if token deletion fails. With the same MySQL environment variables, it also runs revocation against InnoDB and verifies that reapplying the migration preserves existing versions. CI runs these checks on all three PHP versions.

## Run locally

Account Security tests open independent browsers for the same and different accounts. They verify current-browser markers, account isolation, escaped stored metadata, hidden token/session identities, remembered-token restoration and expiry, normal logout, confirmation cancellation and Sign out everywhere. A fresh login must replace this browser's registry entry and reject its previous session ID. Revocation checks replay old session IDs and remembered cookies, reject a stale checkout POST without creating an invoice, preserve another account and allow a fresh login. GET, missing CSRF, guest and incomplete-2FA requests must fail. A separate case uses the real file session handler with 2FA disabled and verifies that forged account IDs cannot target another user.

`php tests/account_device_test.php` checks inventory scoping, expiry, identity redaction, first-seen preservation and stale requests after revocation. With the MySQL test environment variables, it reapplies the new migration to an existing remembered token and verifies that its hash and expiry survive unchanged. Remember-me concurrency regressions continue to run against the metadata-aware token rotation.

The 2FA suite uses the real security forms and login challenge on desktop and mobile. It covers server-owned enrollment keys, expired setup, current-password checks, expired and reused OTPs, protected reads and writes, secure disable, replacement rejection, account-scoped throttling and recovery of a 2FA-enabled account. It also runs enrollment and login with Base32, binary and encrypted secret storage. The CLI fixture expires only its own pending setup in the temporary session database; no production clock or account is changed.

`php tests/totp_test.php` checks the SHA-1 vectors from [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238), the allowed clock window, malformed input, encrypted-secret integrity, persistent replay rejection and account/key isolation. With the MySQL test environment variables above, two independent PHP workers compete for the first use of the same code, and exactly one must succeed. Reapplying the migration must preserve that rejection. Encryption checks follow TrinityCore's [AES parameters](https://github.com/TrinityCore/TrinityCore/blob/master/src/common/Cryptography/AES.h) and [envelope layout](https://github.com/TrinityCore/TrinityCore/blob/master/src/common/Cryptography/CryptoGenerics.h). They do not run a game authserver.

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

The disposable PHP server disables OPcache so configuration edits made by fixtures take effect on the next request. This matters when tests switch between binary, Base32 and encrypted authenticator storage. Production PHP settings are unchanged.

The admin log browser checks use the normal owner login and admin security code. They cover filtered load-more pages, account log limits, server-side email pagination and escaped message details on desktop and mobile. Login checks also verify visible labels, password-manager autocomplete hints and the keyboard password toggle.

Public list checks enable caching in the disposable site and cover news, inbox and sent page boundaries, tied timestamps, comment cursors after a new post, and account search limits. Failed comment loads and submissions must leave a usable retry path and preserve the draft. Registration checks cover email aliases, longer domain suffixes and linked text errors. Recovery checks include keyboard controls and failed-request recovery.

`php tests/list_pagination_test.php` checks the real list models with SQLite and, when the loopback MySQL test variables are supplied, a guarded disposable MySQL schema. It verifies row limits, aggregate account counts, literal search characters and a constant number of comment profile queries per page.

`php tests/log_pagination_test.php` checks page boundaries, tied timestamps, input bounds and filtered counts with SQLite. With the MySQL test variables above, it creates a randomly named schema on the disposable loopback server, seeds 12,000 logs and 12,000 comments, and compares `EXPLAIN` plans before and after the index migration. It checks actual index selection, migration reruns and equivalent custom indexes, then removes only its guarded schema. These plans measure access paths and sorting, not production response times.

Each run creates uniquely named `fusion_e2e_<random>_cms` and `fusion_e2e_<random>_auth` databases. Existing databases are never imported into. Resets and cleanup check an owner token before modifying fixture data. Only tracked working-tree runtime files are copied into a temporary site; local database, owner and fusion configuration is generated separately. CAPTCHA is disabled only in that copy, and CSRF protection stays enabled.

The local RPC endpoint uses a newly generated localhost certificate. PHP trusts that certificate for this process, with HTTPS certificate and hostname verification enabled. No wallet keys or public network connection are needed. Browser requests to external origins are blocked.

The runner stops the site and RPC server and removes its databases and temporary directory after testing. Run one suite at a time from a checkout.

Set `FUSION_E2E_OUTPUT_DIR` to an absolute path outside the checkout to keep reports, screenshots, traces, logs and the temporary runtime manifest there. For example, PowerShell can use `$env:FUSION_E2E_OUTPUT_DIR = Join-Path $env:TEMP 'fusioncms-browser-checks'`. Generated artifacts do not belong in commits.

## Reports and failures

`php tests/next_batch_test.php` checks bounded store/GM reads, explicit order completion, refund rollback and Trinity/VMaNGOS guild rosters. With the loopback MySQL fixture enabled, two worker processes read the same failed order before either claims it; exactly one may credit the refund. Seeded query plans verify the order and GM indexes, migration reruns and custom-index reuse. `php tests/public_assets_test.php` checks dependency selection and separate bundle names for both bundled themes.

The next-batch browser cases cover server pagination, filters, deleted-item details, refund failure/retry, CSRF and method enforcement, cached guild pages, and public dependencies with minification enabled and disabled in both Default and Heaven. Each case runs on desktop and mobile. The full suite has a twenty-minute overall limit, while individual tests retain their existing one-minute limit.

Admin asset browser checks visit basic log pages, account search, the dashboard, the email editor, news uploads, module management, the theme carousel and store icons. They check working controls, missing dependencies and browser script errors on desktop and mobile. Basic pages must omit unrelated plugin bundles. `php tests/admin_assets_test.php` also checks dependency files and load order.

Armory browser checks use a realm inside the owned disposable auth schema, with 125 characters, items and guilds. They cover page boundaries, literal wildcard searches, empty guilds, malformed requests, escaped result names and retry after a failed request. `php tests/armory_search_test.php` exercises the real query builder with Trinity and VMaNGOS column maps on SQLite and the optional loopback MySQL server. MySQL runs with `ONLY_FULL_GROUP_BY`; query checks verify one character query per page and member aggregation restricted to the current guild page.

Two additional browser cases cover count reuse across pages, fresh row values, realm and result-type isolation, explicit search refresh, expiry, changes after zero matches and disabled caching. They check actual POST refresh values and the application's cache data while changing only the owned fixture database.

`php tests/armory_count_cache_test.php` checks the 30-second expiry without renewal, isolated keys, the 128-entry limit, disabled or unavailable caching and database failures that must not store a false zero. Independent PHP workers exercise concurrent writes and protect a newer count from an older overlapping read. Clearing cached data must retain the worker lock and allow the next count to rebuild normally.

CI also runs `tests/armory_profile.php` with 1,000 synthetic rows per searchable table and three measured samples on MySQL 8.0. It checks results and cleanup on each PHP version, including a primed count-cache comparison that fetches fresh page rows with one fewer SQL query. An item-page experiment compares the current query with a derived ID page joined to its item details in one statement. It alternates query order and verifies all eight fields and page ordering. A second run adds 2,048 bytes of unselected item data per row and omits the item name index. These are synthetic sensitivity cases; neither changes application queries or realm schemas. There is no timing threshold. Both `armory-profile.json` and `armory-items-wide.json` are included in the seven-day browser artifacts. For larger local runs and measured optimization priorities, see the [Armory profiling guide](armory-performance.md).

Dashboard browser cases use UTC fixture dates and compare the seed date with the current date after each page render. A date change permits a bounded rebaseline with fresh data and cleared dashboard caches; assertion failures still fail immediately. Both desktop and mobile also start one case with yesterday's data to exercise this path without waiting for midnight. Data edits and graph expiry use the seed date for that attempt.

Open `output/playwright/report/index.html` for the HTML report. Failed tests retain screenshots, video and traces in `output/playwright/results`. PHP and RPC diagnostics are in `output/playwright/logs`. GitHub uploads those directories as a separate artifact for each PHP version, retained for seven days.

## Store, online players, dashboard and account settings

The store starts with 25 items from the first realm. Players can choose a realm, group, quality, currency or sort order, search by name, and load another page without losing their cart. Items whose group was removed remain under Ungrouped. The bundled store now uses a group selector instead of collapsible groups; `minimize_groups_by_default` still exists for older or custom templates. Catalogue requests read current prices directly, so an admin price change does not wait for a catalogue cache to expire.

Online players use server pages with a maximum of 100 rows per request. Staff exclusions follow the configured emulator mapping and include global grants and grants for the selected realm. The normal ten-row pages can be cached for 30 seconds; Refresh bypasses that cache. Searches do not create cache entries. Failed requests leave a retry control, and changing realms cancels an older request before its results can replace the new realm.

Dashboard totals use calendar months, including February and year changes. Monthly signups add every daily signup row. A visitor IP counts once per day: the monthly unique figure counts that IP once for the month, while monthly daily visitors add its separate days. These figures are not pageviews. Current totals, account counts and current graphs refresh after at most 60 seconds when caching is enabled. Historical graphs use separate period keys and a one-day cache.

Account settings preserve drafts after a failed save, show errors beside the relevant field and prevent duplicate submissions. Shared confirmation dialogs support keyboard focus, Tab, Escape, cancellation and focus restoration in both bundled themes. Password changes retain the existing session and remembered-login revocation behavior.

Run `php tests/store_catalogue_test.php`, `php tests/online_players_test.php` and `php tests/dashboard_totals_test.php` for the model checks. They use SQLite by default and add guarded disposable MySQL schemas when the loopback test variables above are set. Coverage includes literal search characters, matching counts and pages, staff scopes, calendar boundaries, future-dated rows and a fixed number of database queries. No game database schema changes are required.

The matching browser files are `store_catalogue.spec.cjs`, `online_players.spec.cjs`, `dashboard_totals.spec.cjs` and `ucp_modals.spec.cjs`. They run on desktop and mobile with real controllers, templates and database fixtures. Tests cover cart preservation, malformed requests, stale responses, retry, cache refresh, escaped names, settings drafts and modal keyboard controls. Existing password-change tests continue to check session revocation.

The runtime manifest, generated configuration and ephemeral TLS private key are excluded from CI artifact uploads and Git. If the runner is forcibly terminated, its terminal output and temporary fixture configuration can help identify the remaining test resources; cleanup must still check fixture ownership before dropping a database.
