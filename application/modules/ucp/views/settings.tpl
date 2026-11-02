<div class="container">
	<div class="row">
		{$link_active = "settings"}
		{include file="../../ucp/views/ucp_navigation.tpl"}
		
		<div class="col-lg-8 py-lg-5 pb-5 pb-lg-0">
			<div class="section-header">{lang("settings", "ucp")}</div>
			<div class="section-body">

				<form onSubmit="Settings.submitInfo(); return false" id="settings_info" aria-busy="false" class="page_form">

					<div class="form-group row">
						<label class="col-sm-3 col-form-label" for="nickname_field">{lang("nickname", "ucp")}</label>
						<div class="col-sm-9">
							<input class="form-control" type="text" id="nickname_field" name="nickname_field" placeholder="{lang("nickname", "ucp")}" value="{$nickname|escape}" aria-describedby="nickname_field_error" maxlength="14" required>
                            <p id="nickname_field_error" class="settings-error text-danger mb-0"></p>
						</div>
					</div>

					<div class="form-group row">
						<label class="col-sm-3 col-form-label" for="location_field">{lang("location", "ucp")}</label>
						<div class="col-sm-9">
							<input class="form-control" type="text" id="location_field" name="location_field" placeholder="{lang("location", "ucp")}" value="{$location|escape}" aria-describedby="location_field_error" maxlength="32">
                            <p id="location_field_error" class="settings-error text-danger mb-0"></p>
						</div>
					</div>
					
					{if $show_language_chooser}
						<div class="form-group row">
							<label class="col-sm-3 col-form-label" for="language_field">{lang('website_language', 'ucp')}</label>
							<div class="col-sm-9">			
							
								<select class="form-select" sb="native" aria-label="{lang('website_language', 'ucp')}" name="language_field" id="language_field" aria-describedby="language_field_error">
									{foreach from=$languages item=language}
										<option value="{$language|escape}" {if $userLanguage == $language}selected="selected"{/if}>{ucfirst($language)}</option>
									{/foreach}
								</select>
                                <p id="language_field_error" class="settings-error text-danger mb-0"></p>
								
							</div>
						</div>
					{/if}
					
					<input class="nice_button mt-3" type="submit" value="{lang("change_information", "ucp")}">

					<div id="settings_info_ajax" role="status" aria-live="polite" class=" text-center mt-3"></div>
				</form>
				
				<hr class="mb-4" />
				
				<form onSubmit="Settings.submit(); return false" id="settings" aria-busy="false" class="page_form">
				
					<div class="form-group row">
						<label class="col-sm-3 col-form-label" for="old_password">{lang("old_password", "ucp")}</label>
						<div class="col-sm-9">
							<input class="form-control" type="password" id="old_password" aria-describedby="old_password_error" required minlength="6" maxlength="16" name="old_password" placeholder="{lang("old_password", "ucp")}" autocomplete="current-password">
                            <p id="old_password_error" class="settings-error text-danger mb-0"></p>
						</div>
					</div>
				
					<div class="form-group row">
						<label class="col-sm-3 col-form-label" for="new_password">{lang("new_password", "ucp")}</label>
						<div class="col-sm-9">
							<input class="form-control" type="password" id="new_password" aria-describedby="new_password_error" required minlength="6" maxlength="16" name="new_password" placeholder="{lang("new_password", "ucp")}" autocomplete="new-password">
                            <p id="new_password_error" class="settings-error text-danger mb-0"></p>
						</div>
					</div>
				
					<div class="form-group row">
						<label class="col-sm-3 col-form-label" for="new_password_confirm">{lang("new_password_confirm", "ucp")}</label>
						<div class="col-sm-9">
							<input class="form-control" type="password" id="new_password_confirm" aria-describedby="new_password_confirm_error" required minlength="6" maxlength="16" name="new_password_confirm" placeholder="{lang("new_password_confirm", "ucp")}" autocomplete="new-password">
                            <p id="new_password_confirm_error" class="settings-error text-danger mb-0"></p>
						</div>
					</div>
					
					<input class="nice_button mt-3" type="submit" value="{lang("change_password", "ucp")}">
					
					<div id="settings_ajax" role="status" aria-live="polite" class="text-center py-3"></div>
				</form>

			</div>
		</div>
	</div>
</div>
