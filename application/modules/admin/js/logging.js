var Logging = {
    offset: 0,
    loadMoreCount: 10,
    loading: false,
    filters: {search: '', module: ''},

    search: function() {
        if (this.loading) return;
        this.request(0, {search: $('#search').val(), module: $('#module').val()}, true);
    },

    loadMore: function() {
        if (this.loading) return;
        this.request(this.offset + this.loadMoreCount, this.filters, false);
    },

    request: function(offset, filters, replace) {
        this.loading = true;
        $('#log_list').attr('aria-busy', 'true');
        $.post(Config.URL + 'admin/logging/search', {search: filters.search, module: filters.module, offset: offset, csrf_token_name: Config.CSRF})
            .done(function(data) {
                Logging.offset = offset;
                Logging.filters = filters;
                $('#log_list .log-load-error').remove();
                if (replace) $('#log_list').html(data);
                else {
                    $('#show_more_count').remove();
                    $('#log_list').append(data);
                }
            }).fail(function() {
                $('#log_list .log-load-error').remove();
                $('<p class="log-load-error text-danger" role="alert">').text($('#log_list').data('load-error')).appendTo('#log_list');
            }).always(function() {
                Logging.loading = false;
                $('#log_list').attr('aria-busy', 'false');
            });
    }
};
