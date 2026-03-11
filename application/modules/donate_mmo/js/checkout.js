$(document).ready(function() {
    var solanaUrl = document.getElementById("solana-pay-url").value;
    var verifyUrl = document.getElementById("verification-url").value;
    
    // Generate QR Code
    var qrContainer = document.getElementById("solana-qr-container");
    if(qrContainer && solanaUrl) {
        new QRCode(qrContainer, {
            text: solanaUrl,
            width: 256,
            height: 256,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });
    }

    // Polling logic
    var isPolling = true;
    var pollInterval = 4000; // 4 seconds

    function checkPaymentStatus() {
        if(!isPolling) return;

        $.ajax({
            url: verifyUrl,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if(response.status === 'success') {
                    isPolling = false;
                    document.getElementById("payment-status").innerHTML = '<i class="fa fa-check-circle"></i> Payment confirmed! Redirecting...';
                    document.getElementById("payment-status").style.color = 'lime';
                    
                    setTimeout(function() {
                        window.location.href = Config.URL + 'donate_mmo/success';
                    }, 2000);
                } else if(response.status === 'error') {
                    isPolling = false;
                    document.getElementById("payment-status").innerHTML = '<i class="fa fa-times-circle"></i> Error checking payment.';
                    document.getElementById("payment-status").style.color = 'red';
                }
            },
            error: function() {
                // Ignore errors and keep polling (could be temporary network issue)
            },
            complete: function() {
                if(isPolling) {
                    setTimeout(checkPaymentStatus, pollInterval);
                }
            }
        });
    }

    // Start polling Loop
    setTimeout(checkPaymentStatus, pollInterval);
});
