========================================================================
MMOCoin DONATION MODULE FOR FUSIONCMS
========================================================================

This is a standalone, decentralized payment gateway module for FusionCMS 
that allows you to accept MMOCoin (a Solana SPL Token) without 
interacting with third-party custodial services.

It utilizes the Solana Pay Standard to generate QR codes with unique 
reference keys, allowing your backend to automatically verify transactions 
directly from the Solana blockchain—without requiring users to connect 
their Web3 wallets to your website.

========================================================================
FEATURES
========================================================================
- Non-Custodial: Funds go directly to your designated Solana wallet.
- No Wallet Connection Needed: Protects users by simply offering a QR 
  code or Solana Pay URL they can scan from Phantom or Solflare on 
  their phone or desktop.
- Admin Interface: Full CRUD operations for creating MMOCoin vs. Donation 
  Point (DP) packages in your FusionCMS ACP.
- Auto-Verification: The checkout page polls the Solana RPC and 
  instantly credits the user's DP via AJAX once the transaction is 
  signed on-chain.

========================================================================
INSTALLATION
========================================================================

METHOD 1: VIA ADMIN CONTROL PANEL (RECOMMENDED)
1. Compress the 'donate_mmo' folder into a '.zip' archive.
2. Go to your FusionCMS Admin Control Panel > Modules.
3. Upload the '.zip' file. FusionCMS will automatically extract it, 
   enable the module, and run the included 'install.sql' file to 
   create the necessary database tables.

METHOD 2: MANUAL INSTALLATION
1. Drop the 'donate_mmo' directory into 'application/modules/'.
2. Run the SQL file located at 'application/modules/donate_mmo/sql/install.sql' 
   in your database manually using phpMyAdmin, HeidiSQL, etc.
3. Enable the module inside the FusionCMS ACP.

========================================================================
CONFIGURATION
========================================================================
The core settings for the module are located in:
application/modules/donate_mmo/config/donate_mmo.php

There you need to establish:
- merchant_wallet : The wallet that will receive the MMOCoin Tokens.
- rpc_url : Your Solana RPC endpoint (See recommendations below).
- mmo_coin_mint : Official contract address (default: 
  6QhZ6WQyYjLGDXFuc9CP1MzzvfeA6rDVnPYUke6ybuff).
- exchange_rate : Exchange rate for stats tracking.

*** IMPORTANT RPC RECOMMENDATION ***
By default, the module uses the public mainnet API 
(https://api.mainnet-beta.solana.com). However, the public endpoints 
are heavily rate-limited and often fail or hang during checkout.

It is highly recommended to sign up for a custom RPC endpoint to 
ensure fast and reliable verification. 
We strongly recommend using: https://helius.dev
Note: Helius offers a generous Free Account that provides more than 
enough monthly credits for medium-traffic servers, meaning this will 
work perfectly without needing a premium subscription!
Just create a free account, copy your API URL, and paste it into the 
$config['rpc_url'] field!

========================================================================
HOW IT WORKS (ARCHITECTURE)
========================================================================

1. The Solana Pay Flow
When a user clicks "Checkout", FusionCMS generates a unique 32-byte 
Base58 Reference Key. This acts as an invoice ID. The transaction is 
saved to the database as 'pending'.

The frontend generates a QR code representing a standard Solana Pay URL:
solana:<MERCHANT>?amount=<AMOUNT>&spl-token=<MMO_MINT>&reference=<KEY>

2. Scanning the QR Code & Verification
Here is what happens on the user's end:
- The user opens a Solana wallet app on their phone (like Phantom or 
  Solflare) and clicks the "Scan QR" button.
- Once scanned, their wallet instantly reads the Solana Pay URL. It 
  automatically fills in your Merchant address, the specific MMOCoin 
  token contract, and the exact amount of MMOCoin required for the package.
- Crucially, the wallet also hides the generated Reference Key silently 
  in the transaction metadata.
- The user hits "Confirm/Send" on their phone to broadcast this 
  transaction to the Solana blockchain.

Behind the scenes, the FusionCMS checkout page is running a Javascript 
loop that pings your PHP backend every 4 seconds.

The PHP backend queries your RPC (Helius) for any signatures attached 
specifically to that Reference Key. The moment it finds a matching 
signature, it knows the transaction broadcasted successfully. It then 
marks the transaction as 'completed' in the database and automatically 
credits the user's Donation Points.
