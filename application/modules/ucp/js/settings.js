var Settings = {
    pending: {},
    clear: function(form) {
        $(form).find('[aria-invalid]').removeAttr('aria-invalid');
        $(form).find('.settings-error').text('');
        $(form).find('[role="status"]').text('');
    },
    errors: function(form, errors, message) {
        var first = null;
        Object.keys(errors || {}).forEach(function(id) {
            var field = document.getElementById(id), error = document.getElementById(id + '_error');
            if (!field || !error || !$.contains(form, field)) return;
            $(field).attr('aria-invalid', 'true'); $(error).text(errors[id]);
            if (!first) first = field;
        });
        $(form).find('[role="status"]').text(message || lang('settings_failed', 'ucp'));
        if (first) first.focus();
    },
    send: function(id, endpoint, values, password) {
        var form = document.getElementById(id);
        if (this.pending[id]) return;
        this.clear(form);
        if (!form.reportValidity()) return;
        this.pending[id] = true;
        var controls = $(form).find('input, select, button').toArray().map(function(node) {
            var before = node.disabled; node.disabled = true; return {node:node, disabled:before};
        });
        var proxies = $(form).find('.sbHolder').toArray().map(function(node) {
            var before = node.inert; node.inert = true; return {node:node, inert:before};
        });
        $(form).attr('aria-busy', 'true');
        $(form).find('[role="status"]').text(lang('settings_saving', 'ucp'));
        values.csrf_token_name = getCookie('csrf_cookie_name');
        $.ajax({url: Config.URL + endpoint, method: 'POST', data: values, dataType: 'json'}).done(function(result) {
            if (!result || result.status !== 'success') {
                Settings.errors(form, result && result.errors, result && result.message); return;
            }
            $(form).find('[role="status"]').text(result.message || lang('changes_saved', 'ucp'));
            if (password) {
                $(form).find('input[type="password"]').val('');
                Swal.fire({text: result.message || lang('changes_saved', 'ucp'), icon: 'success',
                    willClose: function() { window.location = Config.URL + 'login'; }});
            } else if (result.reload) {
                $('<button>', {type:'button', class:'nice_button mt-2', text:lang('settings_reload', 'ucp')})
                    .on('click', function() { window.location.reload(); }).appendTo($(form).find('[role="status"]'));
            }
        }).fail(function(xhr) {
            var result = xhr.responseJSON;
            Settings.errors(form, result && result.errors, result && result.message || lang('settings_failed', 'ucp'));
        }).always(function() {
            delete Settings.pending[id];
            controls.forEach(function(control) { control.node.disabled = control.disabled; });
            proxies.forEach(function(proxy) { proxy.node.inert = proxy.inert; });
            $(form).attr('aria-busy', 'false');
            var invalid = form.querySelector('[aria-invalid="true"]');
            if (invalid) invalid.focus();
        });
    },
    submit: function() {
        var form = document.getElementById('settings');
        if (this.pending.settings) return;
        this.clear(form);
        if ($('#new_password').val() !== $('#new_password_confirm').val()) {
            this.errors(form, {new_password_confirm: lang('pw_doesnt_match', 'ucp')}, lang('pw_doesnt_match', 'ucp')); return;
        }
        this.send('settings', 'ucp/settings/submit', {old_password:$('#old_password').val(), new_password:$('#new_password').val()}, true);
    },
    submitInfo: function() {
        this.send('settings_info', 'ucp/settings/submitInfo', {nickname:$('#nickname_field').val(), location:$('#location_field').val(),
            language:$('#language_field').length ? $('#language_field').val() : ''}, false);
    }
};
