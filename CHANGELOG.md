# Changelog

## Unreleased

- Make website authenticator codes single-use across browsers and PHP workers with atomic, persistent time-step claims. Require the current password and an unused code to enable or disable 2FA, bind enrollment to a server-side setup that expires after 10 minutes, and revoke sessions and remembered devices after changes. Existing sites must run `2026_11_02_00_account_totp_uses.sql` before deployment.
- Clear previous 2FA approval on every new login. Add desktop/mobile coverage for enrollment, expiry, replay rejection, protected requests, secure disable, account rate limits and password recovery, plus concurrent MySQL claims and RFC 6238 vectors. Support Base32, binary and authenticated TrinityCore encrypted secret storage.
- Apply account-wide session revocation to password changes in account settings. Successful changes, including choosing the same password, revoke active sessions and remembered devices; rejected changes leave them usable. Add desktop and mobile coverage for stale checkout writes, old session IDs and tokens, account isolation, and fresh remembered login. This uses the existing session-revocation migration.
- Invalidate all active PHP sessions after password recovery, including a reset to the same password. Check an account revocation version before authenticated requests, clear stale authentication and 2FA state, and prevent stale login or remember-me claims from receiving the new version. Existing sites must run `2026_11_01_01_account_session_versions.sql` before deploying these files.
- Add desktop and mobile checks for active-session revocation, stale session-ID replay, rejected checkout writes, fresh login and isolation between accounts. Add session-version, overlapping-login, transaction rollback and migration regressions.
- Rotate remember-me tokens with an atomic update to the existing row. Reject expired and reused tokens, clear invalid cookies, and revoke all remembered devices for the account on logout or password recovery. Retire legacy password-hash cookies; users with that old format must sign in again. No schema migration is needed.
- Add desktop and mobile remember-me browser coverage and concurrent MySQL checks for rotation, expiry and account revocation.
- Expire password reset links after one hour and atomically consume each valid token before changing the password, preventing sequential and concurrent reuse. No schema migration is needed. Escape reset-token form values and remove recovery request logging from the browser console.
- Add password recovery browser tests using a local SMTP inbox, covering the actual emailed link, validation retries, token expiry, reuse rejection and old/new password login. Add a concurrent MySQL token-claim regression.
- Fix SMTP hostname lookup so recovery email delivery works without the unavailable `superglobals` service.
- Run the full CMS login and donation checkout flows in Chromium at desktop and mobile sizes on all three supported PHP versions in CI.
- Add isolated CMS and auth database fixtures, a local HTTPS RPC fixture, QR decoding, payment rejection checks and one-time credit assertions. Browser CI does not sign or broadcast transactions.
- Save browser reports, failure screenshots, videos, traces and server logs as CI artifacts. Document the local setup in the [browser test guide](docs/testing/browser-ci.md).

## 9.5.2 - 2026-11-01

- Keep outgoing response headers separate from incoming request headers. A session could remove the response's last default header, causing form POST responses to copy the request's content type, length and credentials. Checkout now returns the full HTML page instead of downloading a truncated file.
- Add regressions for empty response headers and a real CSRF-protected HTML POST with request-only headers.
- Verify a native Solflare Android donation on public devnet, including QR scanning, approval, finalization and one-time credit. Record the transaction for offline regression coverage. Phantom installs without Google sign-in but redirects to Play sign-in before onboarding, so its mobile payment test remains blocked. See the [mobile test report](docs/testing/solana-mobile-devnet-2026-11-01.md).

This stable patch accepts the untested Phantom mobile flow as a coverage gap based on the passing Solflare flow and automated regressions. Phantom compatibility remains unverified. No database migration is needed when upgrading from 9.5.1.

## 9.5.1 - 2026-11-01

This is the stable MMO-Coin fork release following `9.5.0-mmo.1`. The MMOCoin donation module is now version 1.1.1.

- Fix the outgoing HTTP protocol version and share the response object so donation endpoints retain their JSON headers and `404`, `405` and `429` status codes.
- Make the CSRF nonce readable by AJAX clients while keeping session cookies HttpOnly and CSRF cookies SameSite=Strict.
- Register the missing request limiter with file locking across PHP workers. Keep two-factor limits separate for each account.
- Add the donation module's missing English strings and show its configured token mint in the package instructions.
- Add HTTP, CSRF and concurrent rate limit regressions, plus five recorded finalized devnet transaction responses.

A browser checkout against public Solana devnet completed a 1.25 test-token payment and credited 25 DP once. Underpayment, wrong-token, wrong-recipient and failed transactions granted no points. Repeated verification, invoice ownership, expiry and CSRF rejection also passed. Only disposable wallets, valueless devnet SOL and a fresh test token were used. See the [test report](docs/testing/solana-devnet-2026-11-01.md) for transaction links and the scope of verification.

## 9.5.0-mmo.1 - 2026-11-01

### Security and compatibility

- Merge 82 upstream commits through `1fd809c7`, preserving this fork's MMOCoin integration and security customizations.
- Require patched PHP releases: 8.3.36+, 8.4.27+ or 8.5.12+ within those branches. Apply the same rule in Composer, startup and installation checks.
- Include upstream authentication, 2FA, SQL injection, configuration escaping, upload and PayPal verification changes. Bundle Smarty 5.8.5 and synchronize Composer metadata with the lock file.
- Preserve nested settings when ConfigEditor adds a sibling value.

### MMOCoin donations 1.1.0

- Verify finalized transactions against the invoice reference, merchant, token mint, payment time and net token credit using integer arithmetic.
- Snapshot payment settings and expiry when an invoice is created. Store package prices as decimals.
- Complete the invoice, grant points and record income in one database transaction. Reject repeated completions and reused payment signatures.
- Limit invoice access to its owner, require POST for state changes and keep CSRF tokens current during polling and admin operations.
- Add module permissions, fix package form routing and validation, and render QR codes with the bundled PHP library.

### Contributor checks and upgrade support

- Add a three-version PHP CI matrix, manual workflow dispatch, locked dependency auditing and donation regression tests with a disposable MySQL service.
- Add an existing-site migration for remember-me tokens and a repeatable MMOCoin module migration.
- Document configuration preservation, legacy invoice handling, database backups and rollback in [UPGRADING.md](UPGRADING.md).

### Validation limits

Payment tests use recorded transaction structures and isolated databases. They do not move tokens or verify a live wallet payment. GitHub-hosted CI status must be checked separately; adding a workflow does not prove it has run.

Local validation passed on PHP 8.3.36, 8.4.27 and 8.5.12 with MariaDB 11.4.13, including schema upgrades, rollback and simultaneous invoice completion. Request fixtures and frontend checks cover POST polling, CSRF renewal, RPC failures and rejected payments. Checkout and admin Smarty templates were rendered with the bundled QR library.
