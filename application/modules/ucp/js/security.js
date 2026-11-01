const Security = {
    canSubmit: true,

    onChange(value) {
        $('#security_help').toggle(value === 'true');
    },

    submit() {
        if (!Security.canSubmit) return;
        Security.canSubmit = false;
        const token = document.cookie.split('; ').find(value => value.startsWith('csrf_cookie_name='));
        if (token) Config.CSRF = decodeURIComponent(token.substring('csrf_cookie_name='.length));
        $('#security_ajax').text('Verifying...');
        $.ajax({
            url: Config.URL + 'ucp/security/submit',
            method: 'POST',
            dataType: 'json',
            data: {
                security_enabled: $('#security_enabled').val(),
                current_password: $('#current_password').val(),
                auth_code: $('#auth_code').val(),
                csrf_token_name: Config.CSRF,
            },
        }).done(data => {
            Swal.fire({text: data.message, icon: data.status ? 'success' : 'error'}).then(() => {
                if (data.status) window.location.replace(Config.URL + 'login');
            });
        }).fail(xhr => {
            Swal.fire({text: xhr.responseJSON?.message || 'Unable to save. Reload the page and try again.', icon: 'error'});
        }).always(() => {
            Security.canSubmit = true;
            $('#security_ajax').text('');
            // The response renews the CSRF cookie, including rejected attempts.
            const token = document.cookie.split('; ').find(value => value.startsWith('csrf_cookie_name='));
            if (token) Config.CSRF = decodeURIComponent(token.substring('csrf_cookie_name='.length));
        });
    },
};
