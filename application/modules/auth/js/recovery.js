var Recovery = {
    loading: false,
    useCaptcha: false,
    useRecaptcha: false,
    useRecaptcha3: false,
    useFusionCaptcha: false,
    request: function() {
        var data = {email: $('.email-input').val(), captcha: $('.captcha-input').val()};
        if (this.useRecaptcha) data.recaptcha = grecaptcha.getResponse();
        if (this.useRecaptcha3) data.recaptcha = $('.g-recaptcha-response').val();
        if (this.useFusionCaptcha) data['cap-token'] = $('input[name="cap-token"]').val();
        this.send('create_request', data, '.email-input');
    },
    reset: function() {
        this.send('reset_password', {token: $('.token-input').val(), new_password: $('.password-input').val()}, '.password-input, .token-input');
    },
    send: function(action, data, clearFields) {
        if (this.loading) return;
        this.loading = true;
        data.csrf_token_name = Config.CSRF;
        var form = $('.recovery-form'), feedback = form.find('.error-feedback'), original = {};
        form.find(clearFields).each(function() { original[this.id] = this.value; });
        form.attr('aria-busy', 'true').find(':submit').prop('disabled', true);
        feedback.addClass('d-none').removeClass('d-block alert-success alert-danger valid-feedback invalid-feedback').empty();
        function message(text, success) {
            feedback.text(text).removeClass('d-none').addClass('d-block ' + (success ? 'alert-success valid-feedback' : 'alert-danger invalid-feedback')).trigger('focus');
        }
        $.ajax({url: Config.URL + 'password_recovery/' + action, method: 'POST', data: data, dataType: 'json'}).done(function(result) {
            if (result.showCaptcha === true) $('.captcha-field').removeClass('d-none');
            if (Recovery.useFusionCaptcha && result.captcha_error === true) Recovery.resetFusionCaptcha();
            if (Recovery.useRecaptcha3) setCaptchaToken();
            if (!result.messages) { message(lang('request_failed', 'auth'), false); return; }
            if (result.messages.error) message(result.messages.error, false);
            else if (result.messages.success) {
                message(result.messages.success, true);
                form.find(clearFields).each(function() { if (this.value === original[this.id]) this.value = ''; });
            } else message(lang('request_failed', 'auth'), false);
        }).fail(function(xhr) {
            var result = xhr.responseJSON;
            message(result && result.messages && result.messages.error ? result.messages.error : lang('request_failed', 'auth'), false);
        }).always(function() {
            Recovery.loading = false;
            form.attr('aria-busy', 'false').find(':submit').prop('disabled', false);
        });
    },
    showPassword: function(button) {
        var shown = $(button).attr('aria-pressed') !== 'true';
        $('#reset-password').attr('type', shown ? 'text' : 'password');
        $(button).attr('aria-pressed', String(shown)).find('i').toggleClass('fa-eye', shown).toggleClass('fa-eye-slash', !shown);
    },
    refreshCaptcha: function(button) {
        $('.captcha-input').val('').trigger('focus');
        var image = $('#' + $(button).data('captcha-id'));
        image.attr('src', image.attr('src') + '&d=' + Date.now());
    },
    resetFusionCaptcha: function() {
        $('input[name="cap-token"]').val('');
        var widget = document.querySelector('cap-widget');
        if (widget && typeof widget.reset === 'function') { widget.reset(); widget.removeAttribute('disabled'); }
    }
};
