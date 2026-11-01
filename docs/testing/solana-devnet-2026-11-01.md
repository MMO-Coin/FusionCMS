# Solana devnet donation test, 2026-11-01

The donation flow passed against public Solana devnet after the fixes included in this fork's 9.5.1 release. A browser created the invoice, the rendered QR supplied the payment request, and the application verified the finalized transfer before granting points. No mainnet funds or production accounts were used.

## Setup

- An isolated site copy ran on PHP 8.3.36 with MariaDB 11.4.13 and disposable CMS and emulator account databases. Browser checks used Chromium through Playwright.
- The site kept CSRF protection enabled. CAPTCHA was disabled in the disposable test configuration so seeded accounts could sign in.
- All chain requests used `https://api.devnet.solana.com`. Signing scripts checked the devnet genesis hash, `EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG`, before submitting transactions.
- Fresh wallets and a classic SPL Token mint with nine decimals were created for this test. The configured package cost 1.25 test tokens and granted 25 DP. Its income snapshot was 1.
- PHP used a checksum-verified CA bundle for HTTPS requests. Certificate verification stayed enabled.

Public test addresses:

| Role | Address |
| --- | --- |
| Payer | `58JvBnsr4stm87ZhXnaMS16zsPKTriKcupZTCFoWWLh1` |
| Merchant | `GhFhRhmQs6r9BfRrwM9fLtc3s4CgC1Pv8EaR7zEo6S3T` |
| Accepted test mint | `9FyXvF2VNncauXE1iv4KfCEQKWmGQVMuXPytCprHTyhg` |
| Wrong-token test mint | `22bEoo7YTPPbhYXJzzgT35YHBjUrPyBNdMKYgu2hwrUj` |

## Results

| Check | Observed result |
| --- | --- |
| Browser sign-in and package checkout | The account signed in through the normal form and created an invoice by POST. |
| Rendered QR | A screenshot of the actual QR decoded to the configured merchant, mint, amount and invoice reference. |
| Valid payment | The Solana Pay SDK signed the decoded request. After finalization, browser polling reached the success page. The invoice stored the payment signature and the account received 25 DP. |
| Repeated verification | Repeated POSTs returned success for the completed invoice. Points stayed at 25 and monthly income stayed at 1. |
| One-unit underpayment | A finalized transfer of 1.249999999 test tokens left the other invoice pending. No additional points or income appeared. |
| Wrong token | A finalized transfer of 1.25 tokens from a different mint left the invoice pending. |
| Wrong recipient | A finalized transfer to a different recipient, with the invoice reference attached, left the invoice pending. |
| Failed transaction | The chain finalized a transfer with an insufficient-token-balance execution error. Verification left the invoice pending. |
| Invoice ownership | A second account signed in separately and received `404` when it tried to verify the paid account's invoice. |
| Missing CSRF nonce | A verification POST without the nonce returned `403`. |
| GET verification | The endpoint returned `405`. |
| Invoice expiry | Moving only the disposable invoice's expiry into the past made verification return an expiry error and mark it failed. Points stayed unchanged. |
| Cookie flags | The browser could read the CSRF nonce. The session cookie retained HttpOnly and the CSRF cookie retained SameSite=Strict. |
| RPC outage | Before the test runtime had a CA bundle, RPC verification failed closed and left the paid invoice pending. It completed through normal polling after the trust store was configured. |

The final database contained one completed invoice, one failed expired invoice, 25 DP for the paying account, zero DP for the other accounts, and one monthly income entry with amount 1.

Finalized transactions can be inspected on Solana Explorer:

| Case | Transaction |
| --- | --- |
| Valid payment | [View on devnet](https://explorer.solana.com/tx/2JAVoJoCMcnKzQkCdsXxStQffCTELYBfJLYctATrHnqfKj3uCKsRKSMad3JVNkU3KrNPG5YqRQHew9AKaK19iXq6?cluster=devnet) |
| Underpayment | [View on devnet](https://explorer.solana.com/tx/hdP1bCXEvwTmmmsdVpaZxqv7MLZSTB4YVkYUdVGUz3Z2Wsx1eNBXB4Z5Qc5hgoJ6PkHX2VgWXMYdYV5WNhSN87V?cluster=devnet) |
| Wrong token | [View on devnet](https://explorer.solana.com/tx/3xruf7XUXoThusJYXRwoHPohxAbCVZ75m6wVU6Gg12Es2pnj3dpZdmWGWkS9fz62ZCTXsic83zxWE5A9XcAYCEAZ?cluster=devnet) |
| Wrong recipient | [View on devnet](https://explorer.solana.com/tx/2FrAVk9KQX3BvZJB5FBDxt24CFSBkZxqtXjVaLNChRSK5VkTECFyhXCCjcSHZPLqi3XhSti41vWip9XSDr9e83A8?cluster=devnet) |
| Failed transaction | [View on devnet](https://explorer.solana.com/tx/ApLrmfdKQpP8mEwxUhW52W7dSAj7iW6wD2GfxJDJQAe7wmBCqs4kogpYwwkYjgwMGEr5PPeu4skvc1C6NK8hkwP?cluster=devnet) |

## Fixes found during the test

The browser run exposed failures that controller stubs did not catch: an invalid outgoing HTTP status line, an HttpOnly CSRF nonce, a missing limiter service, missing module language strings, and separate response instances that discarded controller status codes. These were fixed before completing the checks. The package page also now displays its configured mint instead of a hardcoded address.

Regression tests cover the real response service, HTTP cookie flags and CSRF rejection, plus rate limit persistence, expiry, account isolation, storage failure and concurrent workers. Five public transaction responses are saved in `tests/fixtures/solana_devnet_payments.json` for offline verifier regressions. No signing keys are included.

## Scope and repeating the test

The payer used the Solana Pay SDK to sign the decoded QR request. This did not test the Phantom or Solflare mobile interface. The test used a fresh token on public devnet, so it does not establish mainnet RPC capacity or liquidity for the real MMOCoin token. The expiry check used a database clock fixture rather than waiting an hour.

To repeat it, create an isolated CMS installation with disposable accounts and a new devnet wallet. Fund that wallet with test SOL, create a classic SPL mint and recipient token account, and configure only the isolated site with that mint, merchant and devnet HTTPS endpoint. Sign in through the browser, create an invoice, decode its QR and compare the request with the invoice snapshot before signing. Confirm the transaction at finalized commitment, then check the browser result and database balances. Repeat verification and the rejection cases above. Keep signing keys and local site configuration outside the repository.
