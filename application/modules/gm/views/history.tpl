<div class="container">
    <div class="row">
        <div class="col-lg-4 py-5 pe-lg-5">
            <div class="section-header"><span>{lang("gm_panel", "gm")}</span></div>
            <div class="section-body">
                <div class="list-group mt-3">
                    <a href="{$url}gm" class="list-group-item list-group-item-action  {if $link_active == 'gm'}active{/if}">{lang("ticket_list", "gm")}</a>
                    {if hasPermission("ban")}<a href="{$url}gm/account_banned" class="list-group-item list-group-item-action  {if $link_active == 'account_banned'}active{/if}">{lang("banned_account_list", "gm")}</a>{/if}
                    {if hasPermission("ban")}<a href="{$url}gm/ip_banned" class="list-group-item list-group-item-action  {if $link_active == 'ip_banned'}active{/if}">{lang("banned_ip_list", "gm")}</a>{/if}
                    {if hasPermission("ban")}<a href="{$url}gm/history" class="list-group-item list-group-item-action  {if $link_active == 'history'}active{/if}">{lang("history_list", "gm")}</a>{/if}
                </div>
            </div>
        </div>
        <div class="col-lg-8 py-lg-5 pb-5 pb-lg-0 table-responsive">
            <div class="section-header"><span>{lang("history_list", "gm")}</span></div>
            <form method="get" class="d-flex flex-wrap gap-2 my-3" action="{$url}gm/history">
                <label for="history-type">{lang('type', 'gm')}</label>
                <input class="form-control" id="history-type" name="type" maxlength="32" value="{$filter_type|escape}">
                <label for="history-account">{lang('gm', 'gm')}</label>
                <input class="form-control" id="history-account" name="account" maxlength="32" value="{$search|escape}">
                <button class="nice_button" type="submit">{lang('filter_history', 'gm')}</button>
            </form>
            <p>{lang('history_count', 'gm')}: <span id="history-total">{$total}</span></p>
            {if $gmLogs}
                <table class="table table-bordered table-striped mb-0 dataTable no-footer nice_table">
                    <thead>
                        <tr>
                            <th>{lang("action", "gm")}</th>
                            <th>{lang("type", "gm")}</th>
                            <th>{lang("affected", "gm")}</th>
                            <th>{lang("gm", "gm")}</th>
                            <th>{lang("ip", "gm")}</th>
                            <th>{lang("time", "gm")}</th>
                        </tr>
                    </thead>

                    <tbody>
                        {foreach from=$gmLogs item=log}
                            <tr data-history="{$log.id}">
                                <td>{$log.action|escape}</td>
                                <td>
                                    {$log.type|escape}
                                </td>
                                <td>
                                    {if $log.type == 'account'}
                                        <a data-bs-toggle="tooltip" data-bs-placement="top" title="View profile" href="{$url}profile/{$log.affected|escape}" target="_blank">{$log.accountName|escape}</a>
                                    {elseif $log.type == 'characters'}
                                        <a data-bs-toggle="tooltip" data-bs-placement="top" title="View character" href="{$url}character/{$log.realm}/{$log.affected|escape}" target="_blank">{$log.charName|escape}</a>
                                    {else}
                                        {$log.affected|escape}
                                    {/if}
                                </td>
                                <td><a data-bs-toggle="tooltip" data-bs-placement="top" title="View profile" href="{$url}profile/{$log.gm_id}" target="_blank">{$log.gmName|escape}</a></td>
                                <td>{$log.ip|escape}</td>
                                <td>{date("Y-m-d H:i:s", $log.time)}</td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {else}
                <div style="padding:20px;">{lang("no_data", "gm")}</div>
            {/if}
            <nav class="d-flex gap-3 my-3" aria-label="{lang('history_list', 'gm')}">
                {if $previous}<a rel="prev" href="{$previous|escape}">{lang('history_previous', 'gm')}</a>{/if}
                {if $next}<a rel="next" href="{$next|escape}">{lang('history_next', 'gm')}</a>{/if}
            </nav>
        </div>
    </div>
</div>
