document.addEventListener('DOMContentLoaded', function () {
    const verificationUrl = document.getElementById('verification-url').value;
    const expiresAt = Number(document.getElementById('expires-at').value);
    const csrfName = document.getElementById('payment-csrf-name').value;
    const csrfInput = document.getElementById('payment-csrf-hash');
    const status = document.getElementById('payment-status');
    let polling = true;

    const timer = setInterval(function () {
        const remaining = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
        document.getElementById('expiration-timer').textContent =
            String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0');
        if (remaining === 0) {
            polling = false;
            clearInterval(timer);
            status.textContent = 'Invoice expired. Please create a new invoice.';
            document.getElementById('solana-qr-container').style.opacity = '0.2';
        }
    }, 1000);

    async function checkPayment() {
        if (!polling) return;
        try {
            const body = new URLSearchParams();
            body.set(csrfName, csrfInput.value);
            const response = await fetch(verificationUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body,
            });
            if (response.status === 403) {
                polling = false;
                status.textContent = 'Your session changed. Reload this page before checking the payment again.';
                return;
            }
            const result = await response.json();
            if (result.csrf_hash) csrfInput.value = result.csrf_hash;
            // A request may finish after the countdown has stopped polling.
            if (result.status === 'success') {
                polling = false;
                clearInterval(timer);
                status.textContent = 'Payment verified. Your donation points have been credited.';
                setTimeout(function () { window.location.href = Config.URL + 'donate_mmo/success'; }, 2000);
            } else if (result.status === 'error') {
                polling = false;
                status.textContent = result.message || 'Unable to verify this invoice.';
            }
        } catch (error) {
            // Retry temporary network or RPC failures without granting points.
        } finally {
            if (polling) setTimeout(checkPayment, 4000);
        }
    }
    setTimeout(checkPayment, 4000);
});
