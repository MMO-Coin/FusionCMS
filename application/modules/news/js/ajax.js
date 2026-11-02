/**
 * @package FusionCMS
 * @version 6.X
 * @author Jesper Lindström
 * @author Xavier Geernick
 * @link https://github.com/FusionWowCMS/FusionCMS
 */
function Ajax() {
    this.showComments = function(id) {
        var element = $('#comments_' + id);
        if (element.data('loading')) return;
        if (element.data('loaded')) { element.toggle(); return; }
        element.data('loading', true).attr('aria-busy', 'true').show().empty()
            .append($('<p role="status">').text(lang('comments_loading', 'news')));
        $.get(Config.URL + 'news/comments/get/' + id).done(function(data) {
            if (!$('<div>').html(data).find('.comments_area').length) { failed(); return; }
            element.html(data).data('loaded', true);
            Tooltip.refresh();
        }).fail(failed).always(function() { element.data('loading', false).attr('aria-busy', 'false'); });
        function failed() {
            element.empty().append($('<p role="alert">').text(lang('comment_load_error', 'news')))
                .append($('<button type="button" class="nice_button comment-retry">').text(lang('comment_retry', 'news')).on('click', function() { Ajax.showComments(id); }));
        }
    };

    this.loadMoreComments = function(id, button) {
        button = $(button);
        if (button.prop('disabled')) return;
        button.prop('disabled', true).attr('aria-busy', 'true').siblings('.comment-error').remove();
        $.get(Config.URL + 'news/comments/page/' + id, {before: button.data('before')}).done(function(data) {
            var page = $(data);
            if (!page.filter('.comment-page').length) { failed(); return; }
            button.closest('.comment-page').after(page);
            page.find('.comment').first().attr('tabindex', '-1').trigger('focus');
            button.remove();
            Tooltip.refresh();
        }).fail(failed).always(function() { button.prop('disabled', false).attr('aria-busy', 'false'); });
        function failed() { button.after($('<p class="comment-error" role="alert">').text(lang('comment_load_error', 'news'))); }
    };

    this.submitComment = function(id) {
        var content = $('#comment_field_' + id), button = $('#comment_button_' + id), error = $('#comment_error_' + id);
        if (button.prop('disabled') || !content.val().trim()) return;
        var message = content.val();
        content.prop('disabled', true).attr('aria-invalid', 'false');
        button.prop('disabled', true).attr('aria-busy', 'true');
        error.empty();
        function failed() { error.text(lang('comment_submit_error', 'news')); }
        $.post(Config.URL + 'news/comments/add/' + id, {content: message, csrf_token_name: Config.CSRF}).done(function(data) {
            if (!$('<div>').html(data).find('.comment').length) { failed(); return; }
            $('#comments_area_' + id).prepend(data);
            content.val('');
            $('#characters_remaining_' + id).text('0 / 255');
            Tooltip.refresh();
        }).fail(failed).always(function() {
            content.prop('disabled', false).trigger('focus');
            button.prop('disabled', false).attr('aria-busy', 'false');
        });
    };

    this.remove = function(button, id) {
        var row = $(button).closest('.comment');
        if (row.data('deleting')) return;
        row.data('deleting', true).find('.comment-error').remove();
        $.post(Config.URL + 'news/comments/delete/' + id, {csrf_token_name: Config.CSRF}).done(function(data) {
            if (data.trim() === 'Success') row.remove();
            else failed();
        }).fail(failed).always(function() { row.data('deleting', false); });
        function failed() { row.append($('<p class="comment-error" role="alert">').text(lang('comment_delete_error', 'news'))); }
    };
}
var Ajax = new Ajax();
