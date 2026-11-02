
function UI()
{
	/**
	 * Initializing actions
	 */
	this.initialize = function()
	{

		// Is the vote reminder enabled?
		if(Config.voteReminder)
		{
			UI.voteReminder();
		}

		// Give older browsers some html5-placeholder love!
		$('input[placeholder], textarea[placeholder]').placeholder();

		// Enable tooltip
		Tooltip.initialize();

		$.ajaxSetup({ beforeSend: function(jqXHR, settings) {
			if(settings.data && typeof settings.data === 'object' && typeof settings.data.append === 'function')
				settings.data.append('csrf_token_name', getCookie('csrf_cookie_name'));

			if(typeof settings.data === 'string')
				settings.data += (settings.data ? '&' : '') + 'csrf_token_name' + '=' + getCookie('csrf_cookie_name');
		} }); // adds CSRF token to posts actions, automatically

		$.ajaxPrefilter(function(options) {
			if(typeof options.forceCrossDomain === 'undefined' || (typeof options.forceCrossDomain !== 'undefined' && !options.forceCrossDomain))
				options.crossDomain = false;
		}); // increases the compatibility

		$(document).ajaxComplete(function(event, jqXHR, settings) {
			if((settings.data && typeof settings.data === 'string' && settings.data.indexOf('csrf_token_name') >= 0 && $('input[name="' + 'csrf_token_name' + '"]').length)
				|| (settings.data && typeof settings.data === 'object' && 'csrf_token_name' in settings.data && $('input[name="' + 'csrf_token_name' + '"]').length))
				$('input[name="' + 'csrf_token_name' + '"]').val(getCookie('csrf_cookie_name'));
		}); // renew CSRF field value, automatically
	}

    var popup = null;

    function focusable(dialog) {
        return dialog.find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
            .filter(':visible').filter(function() { return !this.inert && this.getAttribute('aria-disabled') !== 'true'; });
    }

    function closePopup(cancelled, restoreFocus) {
        var state = popup;
        popup = null;
        if (state) {
            clearTimeout(state.timer);
            state.dialog.css({width:state.width, marginLeft:state.margin}).attr('aria-hidden', 'true');
            state.inert.forEach(function(entry) { entry.node.inert = entry.before; });
            document.body.style.overflow = state.overflow;
        }
        $('#popup_bg, #confirm, #alert, #vote_reminder').stop(true, true).hide();
        $('#popup_bg, #confirm_button, #confirm_hide, #alert_button').off('.fusionPopup');
        $(document).off('.fusionPopup');
        if (state && restoreFocus !== false && state.origin && state.origin.isConnected && typeof state.origin.focus === 'function') state.origin.focus();
        if (state && cancelled && typeof state.cancel === 'function') state.cancel();
    }

    function openPopup(id, cancel, width) {
        var origin = popup ? popup.origin : document.activeElement;
        closePopup(true, false);
        var dialog = $('#' + id);
        if (!dialog.length) return null;
        var state = {dialog:dialog, origin:origin, cancel:cancel, inert:[], overflow:document.body.style.overflow,
            width:dialog[0].style.width, margin:dialog[0].style.marginLeft};
        popup = state;
        dialog.attr({role:'dialog', 'aria-modal':'true', 'aria-hidden':'false', tabindex:'-1'}).stop(true, true).show();
        var question = id === 'confirm' ? $('#confirm_question') : $('#alert_message');
        if (question.length && question.text().trim()) dialog.attr('aria-labelledby', question.attr('id')).removeAttr('aria-label');
        else dialog.removeAttr('aria-labelledby').attr('aria-label', dialog.attr('aria-label') || $('#confirm_button').text() || document.title);
        if (Number(width) > 0) {
            var fit = Math.min(Number(width), Math.max(240, window.innerWidth - 32));
            dialog.css({width:fit+'px', marginLeft:-(fit/2)+'px'});
        }
        var background = document.getElementById('popup_bg'), branch = dialog[0];
        while (branch.parentElement && branch.parentElement !== document.documentElement) {
            var parent = branch.parentElement;
            Array.prototype.forEach.call(parent.children, function(node) {
                if (node === branch || node === background || node.contains(background) || /^(SCRIPT|STYLE|LINK)$/.test(node.tagName)) return;
                state.inert.push({node:node, before:node.inert}); node.inert = true;
            });
            if (parent === document.body) break;
            branch = parent;
        }
        document.body.style.overflow = 'hidden';
        $('#popup_bg').stop(true, true).show().css('opacity', 0.5).on('click.fusionPopup', function() { if (popup === state) closePopup(true); });
        $('#confirm_hide, #alert_button').on('click.fusionPopup', function(event) { event.preventDefault(); if (popup === state) closePopup(true); });
        $(document).on('keydown.fusionPopup', function(event) {
            if (popup !== state) return;
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closePopup(true); return; }
            if (event.key === 'Enter' && !$(event.target).is('button, a[href]')) { event.preventDefault(); return; }
            if (event.key !== 'Tab') return;
            var controls = focusable(dialog), first = controls[0], last = controls[controls.length - 1];
            if (!first) { event.preventDefault(); dialog[0].focus(); }
            else if (event.shiftKey && (document.activeElement === first || !$.contains(dialog[0], document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog[0])) { event.preventDefault(); first.focus(); }
        }).on('focusin.fusionPopup', function(event) {
            if (popup === state && event.target !== dialog[0] && !$.contains(dialog[0], event.target)) (focusable(dialog)[0] || dialog[0]).focus();
        });
        var input = dialog.find('[autofocus], input:not([disabled]), textarea:not([disabled]), select:not([disabled])').filter(':visible')[0];
        (input || (id === 'confirm' ? document.getElementById('confirm_hide') : focusable(dialog)[0]) || dialog[0]).focus();
        return state;
    }

    this.voteReminder = function() { openPopup('vote_reminder'); };

    /** Trusted HTML remains supported for existing messages and input prompts. */
    this.alert = function(question, time) {
        $('#alert_message').html(question);
        var state = openPopup('alert');
        if (state && Number(time) >= 0) state.timer = setTimeout(function() { if (popup === state) closePopup(false); }, Number(time));
    };

    this.confirm = function(question, button, callback, callback_cancel, width) {
        $('#confirm_question').html(question); $('#confirm_button').html(button);
        var state = openPopup('confirm', callback_cancel, width);
        if (!state) return;
        $('#confirm_button').on('click.fusionPopup', function(event) {
            event.preventDefault();
            if (popup !== state) return;
            closePopup(false);
            if (typeof callback === 'function') callback();
        });
    };

    this.hidePopup = function() { closePopup(true); };

	/**
	 * Display the amount of remaining characters
	 * @param Object field
	 * @param Object indicator
	 */
	this.limitCharacters = function(field, indicator)
	{
		// Get the values
		var max = field.maxLength;
		var length = field.value.length;

		// Change the indicator
		document.getElementById(indicator).innerHTML = length + " / " + max;
	}
}

var UI = new UI();
