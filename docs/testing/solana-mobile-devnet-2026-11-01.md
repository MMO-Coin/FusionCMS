# Mobile wallet devnet donation test, 2026-11-01

Solflare's native Android payment flow passed on public devnet. Phantom installed successfully but redirected to Google Play sign-in before wallet onboarding, so its payment test remains blocked. The earlier [SDK test](solana-devnet-2026-11-01.md) covers payment rejection cases separately.

## Setup

- A fresh Pixel 7 Android emulator runs Android 15, API 35, with an x86_64 Google Play image and ARM translation. No personal Google account is signed in.
- Aurora Store's anonymous mode downloads the Play packages. Each APK's signature is checked against the production package certificates published by the wallet's official domain before the app is launched: [Phantom](https://phantom.com/.well-known/assetlinks.json) and [Solflare](https://solflare.com/.well-known/assetlinks.json).
- Solflare creates a new local wallet. Its recovery phrase is not displayed or exported. Only the public receive address is used for funding. Phantom does not reach wallet creation.
- Solflare is set to Devnet before funding. The funding helper checks the public devnet endpoint and genesis hash before sending 0.05 test SOL and ten fresh test tokens to that wallet.
- The isolated CMS uses PHP 8.3.36, MariaDB 11.4.13 and separate test accounts. CSRF protection remains enabled. The package costs 1.25 test tokens and grants 25 DP.
- The actual checkout QR is captured from the browser and supplied to the emulator's synthetic camera. Solflare's scanner handles the QR and the installed app signs through its native approval slider.

The merchant is `GhFhRhmQs6r9BfRrwM9fLtc3s4CgC1Pv8EaR7zEo6S3T`. The accepted classic SPL test mint is `9FyXvF2VNncauXE1iv4KfCEQKWmGQVMuXPytCprHTyhg`, with nine decimals. Funding and CMS verification use `https://api.devnet.solana.com` and genesis hash `EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG`. No mainnet funds are used.

## Observed results

| Check | Solflare | Phantom |
| --- | --- | --- |
| App version | 2.31.2, build 21147 | 26.36.0, build 55426; publisher signature verified. |
| Native QR scan and payment review | Passed: Solana Pay showed 1.25 test tokens, the merchant, label and message. | Blocked before onboarding. |
| Native approval and finalized transfer | Passed | Not run. |
| Browser polling | Reached the donation success page. | Invoice stayed pending. |
| Account credit | 25 DP, exactly once. | 0 DP. |
| Three repeat verification POSTs | Each returned HTTP 200 and success; DP and income stayed unchanged. | Not run. |

Solflare's public test address is `Eq8iX9sUtwm2hdd1bZyT5pp7SsX2wtkb7XXtPAmM5wC7`. Its invoice reference is `EyiTjuGubjZboBFnwJRgPkpVs18WqYtS14jgiY4RbrZh`.

The [Solflare payment on devnet](https://explorer.solana.com/tx/5NA7bRpSBCmQLYGhcKmF4wtypfakPsxTWwKYEAYpqGynCd7x6sAWZQN9vXKfCv4VTgLwHdZmawDKxZ92tX7VLXZQ?cluster=devnet) finalized without an execution error and passed the application's verifier. The wallet paid a 165,000 lamport devnet fee.

The native [Solflare payment review](assets/solflare-devnet-review-2026-11-01.png) shows the test-token amount and merchant. Its finalized transaction is also saved in `tests/fixtures/solana_devnet_payments.json` for offline verification.

## Phantom launch block

The official Play package installed through Aurora without a personal Google sign-in. Both a normal launcher invocation and Aurora's Open button returned to the [Google Play sign-in screen](assets/phantom-play-redirect-2026-11-01.png). No Phantom onboarding or payment UI appeared. The precise cause of that redirect was not established.

Testing stopped there to keep the requested no-Google-sign-in setup. No Phantom wallet was created or funded. Payment compatibility with Phantom remains unverified. A new stable release remains on hold while that mobile test is incomplete.

## Checkout defect found

The first mobile checkout exposed a response-header regression in 9.5.1. Starting a session could remove the response's last default header. Reading that empty header collection then imported incoming request headers, including the form content type, request length and credentials. The browser downloaded a truncated file instead of rendering checkout.

`Response::headers()` now returns only outgoing headers. A real HTTP regression submits a CSRF-protected form with request-only headers, then checks the complete HTML response, its MIME type and the absence of reflected request headers. The fixed checkout rendered normally for this test.

The fix was pushed to `main` in commit `94b156dd`. [GitHub CI](https://github.com/MMO-Coin/FusionCMS/actions/runs/41297634502) passed on PHP 8.3.36, 8.4.27 and 8.5.12. Local validation passed 98 regression checks per PHP version, two frontend checks, strict Composer validation and the locked dependency audit. The recorded Solflare transaction adds a further verifier regression.

## Limits

This is an Android emulator test with a synthetic camera feed. It does not cover a physical phone camera, iOS, mainnet capacity or the real token's liquidity. Wallet interfaces can change between versions. Their displayed fiat estimates do not give these devnet assets monetary value.

All signing keys, temporary passcodes, local database credentials and site configuration remain outside the repository.
