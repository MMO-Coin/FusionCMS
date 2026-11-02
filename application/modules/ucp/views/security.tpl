<div class="container">
    <div class="row">
        {$link_active = "security"}
        {include file="../../ucp/views/ucp_navigation.tpl"}
        <div class="col-lg-8 py-lg-5 pb-5 pb-lg-0" id="account-security">
            <div class="section-header">Account Security</div>
            <div class="section-body">
                <p>Review your signed-in browsers and saved logins. Times are shown in {$security_timezone|escape}.</p>
                <h2 class="h4">Active sessions</h2>
                <p>Shows up to 100 recently active, verified browsers. A closed browser may remain listed until its session expires. Older sessions appear when they next visit the site.</p>
                <ul class="security-devices" id="active-sessions">
                    {foreach $active_sessions as $device}
                        <li class="security-device">
                            <strong>{$device.label|escape}</strong>
                            {if $device.current}<span class="security-current">Current browser</span>{/if}
                            <div>IP: {$device.ip_address|default:'Unknown'|escape}</div>
                            <div>First seen: {$device.created_at|escape}</div>
                            <div>Last active: {$device.last_seen_at|escape}</div>
                            <details><summary>Browser details</summary><span class="security-agent">{$device.user_agent|default:'Details unavailable'|escape}</span></details>
                        </li>
                    {foreachelse}<li>No active sessions to display.</li>{/foreach}
                </ul>
                <h2 class="h4 mt-4">Remembered devices</h2>
                <p>These saved logins can restore a session without entering a password. Required two-factor verification still applies. Up to 100 unexpired entries are shown; older entries may have no browser details.</p>
                <ul class="security-devices" id="remembered-devices">
                    {foreach $remembered_devices as $device}
                        <li class="security-device">
                            <strong>{$device.label|escape}</strong>
                            {if $device.current}<span class="security-current">Saved on this browser</span>{/if}
                            <div>IP: {$device.ip_address|default:'Unknown'|escape}</div>
                            <div>Saved: {$device.created_at|escape}</div>
                            <div>Last restored: {if $device.last_used_at}{$device.last_used_at|escape}{else}Not restored yet{/if}</div>
                            <div>Expires: {$device.expires_at|escape}</div>
                            <details><summary>Browser details</summary><span class="security-agent">{$device.user_agent|default:'Details unavailable'|escape}</span></details>
                        </li>
                    {foreachelse}<li>No remembered devices.</li>{/foreach}
                </ul>
                <p>Browser names and IP addresses are approximate identifiers, not proof of a person's identity.</p>
                <h2 class="h4 mt-4">Sign out everywhere</h2>
                <p>End every active session and remove all remembered logins for your account, including this browser. Other browsers lose access on their next request.</p>
                <form id="sign-out-everywhere" onsubmit="Security.signOutEverywhere(); return false">
                    <button class="nice_button" type="submit">Sign out everywhere</button>
                </form>
            </div>
            {if $two_factor_available}
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
            {/if}
        </div>
    </div>
</div>
