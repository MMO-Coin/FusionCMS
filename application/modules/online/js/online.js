var OnlinePlayers = {
    table: null, pending: null, generation: 0, filter: null, refresh: false,
    text: function(value) { return $('<div>').text(value == null ? '' : String(value)).html(); },
    busy: function(value) {
        $('#online-players').attr('aria-busy', String(value));
        $('#online-filters :submit, #online-refresh, #online-retry').prop('disabled', value);
    },
    status: function(message, failed) {
        $('#online-status').text(message || '');
        $('#online-retry').toggleClass('d-none', !failed);
    },
    load: function(reset, fresh) {
        var next = {realm: $('#realmSelect').val() || '', search: $('#online-search').val().trim()};
        var changed = !this.filter || next.realm !== this.filter.realm || next.search !== this.filter.search;
        this.filter = next;
        this.refresh = !!fresh;
        this.table.ajax.reload(null, !!reset || changed);
    },
    request: function(data, callback) {
        var generation = ++this.generation, filter = this.filter;
        if (this.pending) this.pending.abort();
        if (!filter.realm) {
            callback({draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: []});
            this.busy(false); this.status(lang('choose_realm', 'online'), false); return;
        }
        this.busy(true); this.status(lang('loading', 'online'), false);
        var fresh = this.refresh; this.refresh = false;
        this.pending = $.ajax({
            url: Config.URL + 'online/online_refresh/' + encodeURIComponent(filter.realm), method: 'GET', dataType: 'json',
            data: {draw: data.draw, start: data.start, length: data.length, search: filter.search, refresh: fresh ? '1' : '0'}
        }).done(function(result) {
            if (generation !== OnlinePlayers.generation) return;
            callback(result);
            if (data.start > 0 && data.start >= result.recordsFiltered) {
                OnlinePlayers.refresh = true;
                OnlinePlayers.table.page('first').draw('page'); return;
            }
            var message = result.status === 'offline' ? lang('offline', 'online') : result.status === 'empty' ? lang('no_players', 'online')
                : result.recordsFiltered === 0 ? lang('no_matches', 'online') : '';
            OnlinePlayers.status(message, false);
            var updated = new Date(Number(result.updated_at) * 1000);
            if (Number.isFinite(updated.getTime())) {
                $('#online-updated-time').attr('datetime', updated.toISOString()).text(updated.toLocaleString());
                $('#online-updated').removeClass('d-none');
            }
        }).fail(function(xhr, status) {
            if (generation !== OnlinePlayers.generation || status === 'abort') return;
            callback({draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: []});
            $('#online-updated').addClass('d-none');
            OnlinePlayers.status(lang('load_failed', 'online'), true);
        }).always(function() { if (generation === OnlinePlayers.generation) OnlinePlayers.busy(false); });
        return this.pending;
    },
    initialize: function() {
        this.filter = {realm: $('#realmSelect').val() || '', search: ''};
        this.table = $('#playersTable').DataTable({
            serverSide: true, processing: true, searching: false, ordering: false, lengthChange: false, pageLength: 10, autoWidth: false,
            ajax: function(data, callback) { return OnlinePlayers.request(data, callback); },
            columns: [
                {data: 'name', render: function(value, mode, row) { return '<a href="' + Config.URL + 'character/' + encodeURIComponent(OnlinePlayers.filter.realm) + '/' + encodeURIComponent(row.guid) + '">' + OnlinePlayers.text(value) + '</a>'; }},
                {data: 'level', render: OnlinePlayers.text},
                {data: 'race', render: function(value, mode, row) { return $('<img>', {src: Config.URL + 'application/images/stats/' + Number(value) + '-' + Number(row.gender) + '.gif', alt: row.race_name || String(value), width: 20, height: 20}).prop('outerHTML'); }},
                {data: 'class', render: function(value, mode, row) { return $('<img>', {src: Config.URL + 'application/images/stats/' + Number(value) + '.gif', alt: row.class_name || String(value), width: 20, height: 20}).prop('outerHTML'); }},
                {data: 'zone', render: OnlinePlayers.text}
            ]
        });
        $('#realmSelect').on('change', function() { OnlinePlayers.load(true, false); });
        $('#online-filters').on('submit', function(event) { event.preventDefault(); OnlinePlayers.load(true, false); });
        $('#online-refresh, #online-retry').on('click', function() { OnlinePlayers.load(false, true); });
    }
};

$(document).ready(function() { OnlinePlayers.initialize(); });
