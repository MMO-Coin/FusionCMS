<section id="donate_mmo_checkout">
    <div class="page-title">
        <h2>Pay with Solana (MMOCoin)</h2>
    </div>

    <div class="checkout-box text-center" style="text-align:center; margin: 30px auto; max-width: 400px; background: #1a1e24; padding: 25px; border-radius: 12px; border: 1px solid #333;">
        <h3>{$package.price|escape} MMOCoin</h3>
        <p style="color: #aaa; font-size: 14px; margin-bottom: 20px;">Scan this QR code with your Phantom or Solflare mobile app.</p>

        <!-- Payment QR code rendered by the server -->
        <div id="solana-qr-container" style="background: white; padding: 15px; border-radius: 8px; display: inline-block;">
            <img src="{$qr_code|escape}" alt="Solana Pay payment QR code" width="256" height="256">
        </div>

        <div style="margin-top: 20px; font-family: monospace; font-size: 14px; color: #777; word-break: break-all;">
            <strong>Amount:</strong> {$package.price|escape} MMOCoin<br>
            <strong>Reference:</strong> {$reference|escape}
        </div>
        
        <div id="expiration-timer-container" style="margin-top: 15px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 6px; display: inline-block;">
            <i class="fa fa-clock-o"></i> This invoice will expire in <span id="expiration-timer" style="font-weight:bold; color: #e74c3c;">60:00</span>
        </div>
        
        <p id="payment-status" style="margin-top: 20px; font-weight: bold; color: orange;">
            <i class="fa fa-spinner fa-spin"></i> Awaiting payment...
        </p>

        <!-- Hidden inputs for JS to use -->
        <input type="hidden" id="verification-url" value="{$url|escape}donate_mmo/verify_payment/{$reference|escape}">
        <input type="hidden" id="expires-at" value="{$expires_at}">
        <input type="hidden" id="payment-csrf-name" value="{$csrf_name|escape}">
        <input type="hidden" id="payment-csrf-hash" value="{$csrf_hash|escape}">
    </div>
</section>
