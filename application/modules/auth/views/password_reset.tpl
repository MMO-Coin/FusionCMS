<div class="page-subbody">
	<div class="page_form">
		<form class="recovery-form" onSubmit="Recovery.reset(); return false">
            <div id="recovery-feedback" class="alert text-center error-feedback d-none" role="alert" tabindex="-1"></div>

			<label for="reset-token">{lang('token', 'recovery')}</label>
			<input id="reset-token" name="token" autocomplete="off" spellcheck="false" autocapitalize="none" aria-describedby="recovery-feedback" type="text" value="{$token|escape}" class="token-input mb-3" required>

			<label for="reset-password">{lang('new_password', 'recovery')}</label>
			<div class="input-group password-field mb-3"><input id="reset-password" name="new_password" type="password" autocomplete="new-password" minlength="6" maxlength="16" aria-describedby="recovery-feedback" class="password-input" required>
                <button type="button" class="input-group-text" style="min-width:44px;min-height:44px;" aria-label="{lang('login_show_password', 'auth')|escape}" aria-controls="reset-password" aria-pressed="false" onclick="Recovery.showPassword(this)"><i class="fa-duotone fa-eye-slash" aria-hidden="true"></i></button></div>

			<input type="submit" value="{lang('reset_password', 'recovery')}" class="nice_button text-center">
		</form>
	</div>
</div>
