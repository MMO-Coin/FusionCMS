<!-- Popup background -->
<div id="popup_bg"></div>

<!-- Confirm box -->
<div id="confirm" role="dialog" aria-modal="true" aria-labelledby="confirm_question" aria-hidden="true" tabindex="-1" class="popup-box type-confirm v-center" style="display:none">
	<div id="confirm_question" class="popup-content"></div>

	<div class="popup-buttons">
		<button type="button" id="confirm_button" class="nice_button popup-button type-action"></button>
		<button type="button" id="confirm_hide" class="nice_button popup-button type-cancel">{lang('general_cancel', 'theme')}</button>
	</div>
</div>

<!-- Alert box -->
<div id="alert" role="dialog" aria-modal="true" aria-labelledby="alert_message" aria-hidden="true" tabindex="-1" class="popup-box type-alert v-center" style="display:none">
	<div id="alert_message" class="popup-content"></div>

	<div class="popup-buttons">
		<button type="button" id="alert_button" class="nice_button popup-button type-okay">{lang('general_okay', 'theme')}</button>
	</div>
</div>

{if $vote_reminder}
	<!-- Vote reminder popup -->
	<div id="vote_reminder" aria-label="{lang('vote', 'main')}">
		<a href="{$url}vote"><img width="" height="" alt="" src="{$vote_reminder_image}" /></a>
	</div>
{/if}