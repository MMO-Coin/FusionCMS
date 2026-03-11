$(document).ready(function() {
    var solanaUrl = document.getElementById("solana-pay-url").value;
    var verifyUrl = document.getElementById("verification-url").value;
    
    var expiresAt = parseInt(document.getElementById("expires-at").value, 10);
    
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

    // Timer logic
    var isPolling = true;
    var timerInterval = setInterval(function() {
        var now = Math.floor(Date.now() / 1000);
        var distance = expiresAt - now;

        if (distance <= 0) {
            clearInterval(timerInterval);
            document.getElementById("expiration-timer").innerHTML = "00:00";
            
            // visually expire
            isPolling = false;
            document.getElementById("payment-status").innerHTML = '<i class="fa fa-times-circle"></i> Invoice Expired. Please create a new one.';
            document.getElementById("payment-status").style.color = 'red';
            qrContainer.style.opacity = "0.2";
            return;
        }

        var minutes = Math.floor(distance / 60);
        var seconds = distance % 60;

        minutes = minutes < 10 ? "0" + minutes : minutes;
        seconds = seconds < 10 ? "0" + seconds : seconds;

        document.getElementById("expiration-timer").innerHTML = minutes + ":" + seconds;
    }, 1000);

    // Polling logic
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
