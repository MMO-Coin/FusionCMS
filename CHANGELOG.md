# Changelog

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
