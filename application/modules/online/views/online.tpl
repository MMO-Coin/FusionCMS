<div class="container" id="online-players" aria-busy="false">
    <form id="online-filters" class="row align-items-end g-2 mb-3">
        <div class="col-sm-4">
            <label for="realmSelect" class="form-label">{lang('realm', 'online')}</label>
            <select id="realmSelect" class="form-select" sb="none">
                {foreach from=$realms item=realm}
                    <option value="{$realm->getId()}">{$realm->getName()|escape}</option>
                {foreachelse}<option value="">{lang('choose_realm', 'online')}</option>{/foreach}
            </select>
        </div>
        <div class="col-sm-4">
            <label for="online-search" class="form-label">{lang('search_name', 'online')}</label>
            <input id="online-search" name="search" maxlength="100" class="form-control" type="search" autocomplete="off">
        </div>
        <div class="col-sm-4 d-flex flex-wrap gap-2">
            <button class="nice_button" type="submit">{lang('search', 'online')}</button>
            <button class="nice_button" id="online-refresh" type="button">{lang('refresh', 'online')}</button>
        </div>
    </form>
    <p id="online-status" role="status" aria-live="polite"></p>
    <button id="online-retry" class="nice_button d-none mb-3" type="button">{lang('try_again', 'online')}</button>
    <p id="online-updated" class="d-none">{lang('updated_at', 'online')}: <time id="online-updated-time"></time></p>
    <div id="playersTableContainer" class="table-responsive">
        <table id="playersTable" class="nice_table">
            <thead><tr>
                <th>{lang('character', 'online')}</th><th>{lang('level', 'online')}</th><th>{lang('race', 'online')}</th>
                <th>{lang('class', 'online')}</th><th>{lang('location', 'online')}</th>
            </tr></thead><tbody></tbody>
        </table>
    </div>
</div>
