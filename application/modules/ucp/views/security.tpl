<div class="container">
    <div class="row">
        {$link_active = "security"}
        {include file="../../ucp/views/ucp_navigation.tpl"}
        <div class="col-lg-8 py-lg-5 pb-5 pb-lg-0">
            <div class="section-header">{lang("two_factor", "ucp")}</div>
            <div class="section-body">
                <p>{lang("two_factor_description", "ucp")}</p>
                <p>Enter your current password and a new authenticator code to change this setting. All browsers and remembered devices will be signed out.</p>
                {if !$security_enabled}
                    <div id="security_help" style="display: none;">
                        <p>Scan this QR code in your authenticator app. Setup expires in 10 minutes.</p>
                        <img class="mx-auto bg-light qr-two-factor" src="{$qr_code}" alt="Authenticator setup QR code">
                        <p>Manual setup key: <code id="setup-key">{$secret_key|escape}</code></p>
                    </div>
                {/if}
                <form onsubmit="Security.submit(); return false" class="page_form" id="security-settings">
                    <label for="security_enabled">{lang("select_authentication", "ucp")}</label>
                    <select class="form-select mb-3" name="security_enabled" id="security_enabled" onchange="Security.onChange(this.value)">
                        <option value="false" {if !$security_enabled}selected{/if}>{lang("disabled", "ucp")}</option>
                        <option value="true" {if $security_enabled}selected{/if}>{lang("google_authenticator", "ucp")}</option>
                    </select>
                    <label for="current_password">Current password</label>
                    <input class="form-control mb-3" type="password" id="current_password" name="current_password" autocomplete="current-password" required maxlength="16">
                    <label for="auth_code">{lang("six_digit_auth_code", "ucp")}</label>
                    <input class="form-control" type="text" id="auth_code" name="auth_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{ldelim}6{rdelim}" maxlength="6" required>
                    <p>Each code can be used once. If you just signed in, wait for the next code before saving.</p>
                    <input class="nice_button mt-3" type="submit" value="{lang("save_changes", "ucp")}">
                    <div id="security_ajax" class="text-center py-3" role="status"></div>
                </form>
            </div>
        </div>
    </div>
</div>
