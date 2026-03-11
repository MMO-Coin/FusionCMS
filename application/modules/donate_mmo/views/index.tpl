<section id="donate_mmo">
    <div class="page-title">
        <h2>Select a donation package</h2>
    </div>

    <form method="post" action="{$url}donate_mmo/checkout">
        {if $packages}
            <ul class="donation-packages">
            {foreach from=$packages item=p}
                <li>
                    <label>
                        <input type="radio" name="package_id" value="{$p.id}" required>
                        <span class="package-name">{$p.price} MMOCoin &rarr; {$p.points} DP</span>
                    </label>
                </li>
            {/foreach}
            </ul>
            <div class="submit-btn" style="margin-top:20px;">
                <input type="submit" value="Check Out with Solana Pay" class="nice_button">
            </div>
        {else}
            <div class="alert alert-info">No MMOCoin packages are currently available.</div>
        {/if}
    </form>
    
    <div style="margin-top: 40px; padding: 20px; background: rgba(0,0,0,0.2); border-left: 4px solid #f39c12; border-radius: 4px;">
        <h3 style="margin-top: 0; color: #f39c12;">How to get MMOCoin Token?</h3>
        <p>Before you can donate, you need **MMOCoin** tokens on the Solana blockchain.</p>
        <ol style="margin-left: 20px; margin-top: 10px; line-height: 1.6;">
            <li>Download the <a href="https://phantom.app/" target="_blank" style="color: #61dafb; text-decoration: underline;">Phantom Wallet</a> app on your phone or browser.</li>
            <li>Send some SOL (Solana) to your Phantom wallet to cover network fees.</li>
            <li>Use a decentralized exchange (like Raydium or Jupiter) to swap your SOL or USDC for MMOCoin token.
                <br><small style="color: #999;">Contract Address: <code>6QhZ6WQyYjLGDXFuc9CP1MzzvfeA6rDVnPYUke6ybuff</code></small>
            </li>
            <li>Once you have MMOCoin in your wallet, return here, select a package, and click Checkout to scan the QR code!</li>
        </ol>
    </div>
</section>

