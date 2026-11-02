<div class="page-subbody">
	<div class="page_form">
		<h4 class="font-weight-semibold text-uppercase mb-0">{lang('lost_password', 'recovery')}</h4>
		<p class="text-2 opacity-7">{lang('enter_your_email', 'recovery')}</p>
		<form class="recovery-form" onSubmit="Recovery.request(); return false">
			<div id="recovery-feedback" class="alert text-center error-feedback d-none" role="alert" tabindex="-1"></div>

			<label for="recovery-email">{lang('email', 'recovery')}</label>
			<input id="recovery-email" name="email" type="email" autocomplete="email" aria-describedby="recovery-feedback" class="email-input mb-3" required>

            <div class="captcha-field {if !$use_captcha}d-none{/if}">
                <div class="input-group mt-3 mb-3">
                    {if $captcha_type == 'image_captcha'}
                        <label for="floatingCaptcha" class="input-group-text w-100 rounded-0 rounded-top text-center d-block" id="recovery-captcha-label">
                            <img src="{$url}auth/getCaptcha?{time()}" alt="captcha" width="150" height="30" id="captchaImage">
                        </label>

                        <button type="button" class="input-group-text ms-0 rounded-0 rounded-bottom-start" aria-label="{lang('login_refresh_captcha', 'auth')|escape}" style="min-width:44px;min-height:44px;" data-captcha-id="captchaImage" onClick="Recovery.refreshCaptcha(this);">
                            <i class="fa-duotone fa-rotate" aria-hidden="true"></i>
                        </button>

                        <div class="form-floating ms-0 flex-grow-1">
                            <input type="text" class="form-control captcha-input border-0 rounded-0 rounded-bottom-end" id="floatingCaptcha" placeholder="{lang('login_label_captcha', 'auth')}" aria-describedby="recovery-feedback">
                            <label for="floatingCaptcha">{lang("login_label_captcha", "auth")}</label>
                        </div>
                    {elseif $captcha_type == 'recaptcha' || $captcha_type == 'recaptcha3'}
                        <div class="captcha">
                            {$recaptcha_html}
                        </div>
                    {elseif $captcha_type == 'fusion_captcha'}
                        <script type="text/javascript" src="{$url}application/js/captcha/cap_widget.min.js"></script>
                        <cap-widget
                                data-cap-api-endpoint="/captcha/"
                                data-cap-hidden-field-name="cap-token"
                                data-cap-background="#1e1e1e"
                                data-cap-color="#f0f0f0"
                                data-cap-direction="{if $isRTL}rtl{else}ltr{/if}"
                                data-cap-i18n-initial-state="{lang('initial_state', 'captcha')}"
                                data-cap-i18n-verifying-label="{lang('verifying_label', 'captcha')}"
                                data-cap-i18n-solved-label="{lang('solved_label', 'captcha')}"
                                data-cap-i18n-error-label="{lang('error_label', 'captcha')}"
                                data-cap-i18n-wasm-disabled="{lang('wasm_disabled', 'captcha')}"
                                data-cap-i18n-verify-aria-label="{lang('verify_aria_label', 'captcha')}"
                                data-cap-i18n-verifying-aria-label="{lang('verifying_aria_label', 'captcha')}"
                                data-cap-i18n-verified-aria-label="{lang('verified_aria_label', 'captcha')}"
                                data-cap-i18n-error-aria-label="{lang('error_aria_label', 'captcha')}">
                        </cap-widget>
                    {/if}
                </div>
            </div>

			<input type="submit" value="{lang('recover', 'recovery')}" class="nice_button text-center">
		</form>
	</div>
</div>

{if $use_captcha}
    {if $captcha_type == 'image_captcha'}
        <script>
            $(window).on("load", function() {
                Recovery.useCaptcha = true;
            });
        </script>
    {elseif $captcha_type == 'recaptcha'}
        <script>
            $(window).on("load", function() {
                Recovery.useRecaptcha = true;
            });
        </script>
    {elseif $captcha_type == 'recaptcha3'}
        <script>
            $(window).on("load", function() {
                Recovery.useRecaptcha3 = true;
            });
        </script>
    {elseif $captcha_type == 'fusion_captcha'}
        <script>
            $(window).on("load", function() {
                Recovery.useFusionCaptcha = true;
            });
        </script>
    {/if}
{/if}