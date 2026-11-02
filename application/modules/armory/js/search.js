/** One table instance per result type; filters and draws stay attached to their request. */
var Search = {
    tables: {}, filters: {}, pending: {}, active: null,
    escape: function(value) { return $('<div>').text(value == null ? '' : String(value)).html(); },
    error: function(message) { $('#armory-error').text(message || '').toggleClass('d-none', !message); },
    busy: function(value) { $('#armory-search').attr('aria-busy', String(value)).find(':submit').prop('disabled', value); },
    show_data: function() { this.run($('#table').val()); },
    toggle: function() { if ($('#search_field').val().trim().length >= 3) this.show_data(); },
    show_items: function() { this.run('items'); },
    show_guilds: function() { this.run('guilds'); },
    show_characters: function() { this.run('characters'); },
    show: function(type) {
        if (this.active !== type) return;
        $('#search_box').show();
        ['items', 'guilds', 'characters'].forEach(function(name) {
            $('#search_results_' + name + '_wrapper').toggle(name === type);
            $('#search_results_' + name).toggle(name === type);
        });
    },
    run: function(type) {
        var search = $('#search_field').val().trim(), realm = $('#realm').val();
        if (search.length < 3) { this.error(lang('search_too_short', 'armory')); return; }
        if (!realm || search.length > 100 || !['items', 'guilds', 'characters'].includes(type)) { this.error(lang('search_invalid', 'armory')); return; }
        Object.keys(this.pending).forEach(function(name) { if (Search.pending[name]) Search.pending[name].abort(); });
        this.active = type;
        this.filters[type] = {search: search, realm: realm};
        this.error('');
        if (this.tables[type]) this.tables[type].ajax.reload(null, true);
        else {
            this.tables[type] = $('#search_results_' + type).on('draw.dt', function() { Search.show(type); }).DataTable({
                processing: true, serverSide: true, searching: false, ordering: false, lengthChange: false, autoWidth: false,
                pageLength: 10, columns: this.columns(type),
                ajax: function(data, callback) {
                    var filter = Search.filters[type];
                    Search.busy(true);
                    var request = $.ajax({url: Config.URL + 'armory/search', method: 'POST', dataType: 'json', data: {
                        realm: filter.realm, table: type, search: filter.search, start: data.start, length: data.length,
                        draw: data.draw, csrf_token_name: Config.CSRF
                    }}).done(function(result) {
                        if (Search.filters[type] !== filter) return;
                        callback(result);
                        if (Search.active === type) Search.error('');
                        Tooltip.refresh();
                    }).fail(function(xhr, status) {
                        if (status === 'abort' || Search.filters[type] !== filter) return;
                        callback({draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: []});
                        if (Search.active === type) Search.error(lang('search_failed', 'armory'));
                    }).always(function() {
                        if (Search.pending[type] === request && Search.active === type) Search.busy(false);
                    });
                    Search.pending[type] = request;
                    return request;
                }
            });
        }
        this.show(type);
        this.tables[type].columns.adjust();
    },
    columns: function(type) {
        var text = function(value) { return Search.escape(value); };
        if (type === 'items') return [
            {data: 'name', render: function(value, mode, row) {
                return row.icon + ' <a data-realm="' + encodeURIComponent(row.realm) + '" rel="item=' + encodeURIComponent(row.id) + '" class="item-q' + Number(row.quality) + '" href="' + Config.URL + 'item/' + encodeURIComponent(row.realm) + '/' + encodeURIComponent(row.id) + '" target="_blank">' + text(value) + '</a>';
            }}, {data: 'level', render: text}, {data: 'required', render: text}, {data: 'type', render: text}
        ];
        if (type === 'guilds') return [
            {data: 'name', render: function(value, mode, row) { return '<a href="' + Config.URL + 'guild/' + encodeURIComponent(row.realm) + '/' + encodeURIComponent(row.id) + '" target="_blank" rel="noopener">' + text(value) + '</a>'; }},
            {data: 'members', render: text},
            {data: 'ownerName', render: function(value, mode, row) { return value ? '<a href="' + Config.URL + 'character/' + encodeURIComponent(row.realm) + '/' + encodeURIComponent(row.ownerGuid) + '" target="_blank" rel="noopener">' + text(value) + '</a>' : ''; }}
        ];
        return [
            {data: 'avatar', render: function(value) { return '<img src="' + Config.URL + 'application/images/avatars/' + encodeURIComponent(value) + '.gif" class="char_avatar" alt="">'; }},
            {data: 'name', render: function(value, mode, row) { return '<span class="color-c' + Number(row.class) + '">' + text(value) + '</span>'; }},
            {data: 'race', render: function(value) { return '<span class="faction-' + Number(value) + '"></span>'; }},
            {data: 'level', render: text},
            {data: 'guid', render: function(value, mode, row) { return '<a href="' + Config.URL + 'character/' + encodeURIComponent(row.realm) + '/' + encodeURIComponent(value) + '" target="_blank" rel="noopener">' + text(lang('view_character_profile', 'armory')) + '</a>'; }}
        ];
    }
};
