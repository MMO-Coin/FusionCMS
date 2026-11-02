<div class="row g-0 registration-form">
	<div class="col-md-12 col-lg-5" col-l>
		<div class="register-banner">
			<a href="{$url}login" class="btn-gray" title="{lang('login', 'main')}">{lang('login', 'main')}</a>
		</div>
	</div>

	<div class="col-md-12 col-lg-7" col-r>
		<div class="register-content">
			<h3 class="register-title" title="{lang('register', 'register')}">{lang('register', 'register')}</h3>

			{form_open('register')}
				<div class="form-group">
					<label for="register_username">{lang('username', 'register')}</label>
					<input type="text" name="register_username" id="register_username" class="form-control" value="{set_value('register_username')}" placeholder="{lang('username', 'register')}" onChange="Validate.checkUsername()" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="4" maxlength="24" required aria-describedby="username_error" aria-invalid="{if $username_error}true{else}false{/if}"/>
					<span id="username_error" class="field-message" role="status" aria-live="polite">{$username_error|escape}</span>
				</div>

				<div class="form-group mt-3">
					<label for="register_email">{lang('email', 'register')}</label>
					<input type="email" name="register_email" id="register_email" class="form-control" value="{set_value('register_email')}" placeholder="{lang('email', 'register')}" onChange="Validate.checkEmail()" autocomplete="email" required aria-describedby="email_error" aria-invalid="{if $email_error}true{else}false{/if}"/>
					<span id="email_error" class="field-message" role="status" aria-live="polite">{$email_error|escape}</span>
				</div>

				<div class="form-group mt-3">
					<label for="register_password">{lang('password', 'register')}</label>
					<input type="password" name="register_password" id="register_password" class="form-control" value="{set_value('register_password')}" placeholder="{lang('password', 'register')}" onChange="Validate.checkPassword()" autocomplete="new-password" minlength="6" maxlength="16" required aria-describedby="password_error" aria-invalid="{if $password_error}true{else}false{/if}"/>
					<span id="password_error" class="field-message" role="status" aria-live="polite">{$password_error|escape}</span>
				</div>

				<div class="form-group mt-3">
					<label for="register_password_confirm">{lang('confirm', 'register')}</label>
					<input type="password" name="register_password_confirm" id="register_password_confirm" class="form-control" value="{set_value('register_password_confirm')}" placeholder="{lang('confirm', 'register')}" onChange="Validate.checkPasswordConfirm()" autocomplete="new-password" minlength="6" maxlength="16" required aria-describedby="password_confirm_error" aria-invalid="{if $password_confirm_error}true{else}false{/if}"/>
					<span id="password_confirm_error" class="field-message" role="status" aria-live="polite">{$password_confirm_error|escape}</span>
				</div>

				{if $use_captcha}
					<div class="form-group mt-3">
						{if $captcha_type == 'image_captcha'}
							<input type="text" name="register_captcha" id="register_captcha" aria-describedby="captcha_error" class="form-control" value="" placeholder="{lang('login_label_captcha', 'sidebox_info_login')}" />
							<img width="150" height="30" alt="{lang('login_label_captcha', 'sidebox_info_login')}" src="{$url}register/getCaptcha?{time()}" />
							<span id="captcha_error" role="alert">{$captcha_error|escape}</span>
						{elseif $captcha_type == 'recaptcha' || $captcha_type == 'recaptcha3'}
							<div class="captcha {if $captcha_error}alert-captcha{/if}">
								{$recaptcha_html}
							</div>
						{/if}
					</div>
				{/if}

				<div class="form-group mt-5">
					<button type="submit" name="login_submit" class="btn-blue d-block w-100">{lang('submit', 'register')}</button>
				</div>
			{form_close()}
		</div>
	</div>
</div>
