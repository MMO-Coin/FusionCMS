/** Registration feedback uses text and keeps responses tied to the current value. */
var Validate = {
    requests: {},
    message: function(field, text, invalid) {
        $(field).attr('aria-invalid', String(invalid));
        $(field.replace('register_', '') + '_error').text(text);
    },
    valid: function(field) { this.message(field, field === '#register_username' || field === '#register_email' ? lang('validation_valid', 'register') : '', false); },
    invalid: function(field, error) { this.message(field, error, true); },
    check: function(field, url, data, unavailable) {
        var value = $(field).val(), key = field;
        if (this.requests[key]) this.requests[key].abort();
        this.message(field, lang('validation_checking', 'register'), false);
        this.requests[key] = $.ajax({url: Config.URL + url, method: data ? 'POST' : 'GET', data: data}).done(function(result) {
            if ($(field).val() !== value) return;
            result = String(result).trim();
            if (result === '1') Validate.valid(field);
            else Validate.invalid(field, lang(result.trim() === 'invalid' ? 'email_invalid' : unavailable, 'register'));
        }).fail(function(xhr, status) {
            if (status !== 'abort' && $(field).val() === value) Validate.message(field, lang('validation_unavailable', 'register'), false);
        });
    },
    checkUsername: function() {
        var field = '#register_username', value = $(field).val();
        if (value.length < 4 || value.length > 24) this.invalid(field, lang('username_limit_length', 'register'));
        else if (!/^[a-z0-9]+$/i.test(value)) this.invalid(field, lang('username_limit', 'register'));
        else this.check(field, 'register/check/username/' + encodeURIComponent(value), null, 'username_not_available');
    },
    checkEmail: function() {
        var field = '#register_email';
        if (!$(field).val() || !$(field)[0].checkValidity()) this.invalid(field, lang('email_invalid', 'register'));
        else this.check(field, 'register/check/email', {email: $(field).val(), csrf_token_name: Config.CSRF}, 'email_not_available');
    },
    checkPassword: function() {
        var field = '#register_password', value = $(field).val();
        if (value.length < 6 || value.length > 16) this.invalid(field, lang('password_limit', 'register'));
        else this.valid(field);
        if ($('#register_password_confirm').val()) this.checkPasswordConfirm();
    },
    checkPasswordConfirm: function() {
        var field = '#register_password_confirm';
        if (!$(field).val() || $(field).val() !== $('#register_password').val()) this.invalid(field, lang('password_match', 'register'));
        else this.valid(field);
    }
};
$(function() { $('#register_username').closest('form').find('[aria-invalid="true"]').first().trigger('focus'); });
