<div class="card">
    <header class="card-header">
        <h2 class="card-title">{lang('sent_email_log', 'admin')}</h2>
    </header>
    <div class="card-body">
        <form method="get" action="{base_url('admin/emaillogs')}" class="mb-3">
            <label for="email-log-search">{lang('email_log_search', 'admin')}</label>
            <div class="input-group">
                <input id="email-log-search" name="search" type="search" class="form-control" maxlength="255" value="{$search|escape}">
                <button type="submit" class="btn btn-primary">{lang('search', 'admin')}</button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="emaillogs">
                <thead><tr>
                    <th scope="col">#</th>
                    <th scope="col">{lang('user', 'admin')}</th>
                    <th scope="col">{lang('email', 'admin')}</th>
                    <th scope="col">{lang('subject', 'admin')}</th>
                    <th scope="col">{lang('time', 'admin')}</th>
                    <th scope="col">{lang('message', 'admin')}</th>
                </tr></thead>
                <tbody>
                    {foreach from=$emaillogs item=log}
                    <tr>
                        <td>{$log.id}</td>
                        <td>{$CI->user->getUsername($log.uid)|escape}</td>
                        <td>{$log.email|escape}</td>
                        <td>{$log.subject|escape}</td>
                        <td>{date('Y-m-d H:i:s', (int)$log.timestamp)}</td>
                        <td><details><summary>{lang('message', 'admin')}</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:20rem;overflow:auto;" tabindex="0">{$log.message|escape}</pre></details></td>
                    </tr>
                    {foreachelse}
                    <tr><td colspan="6">{lang('no_results', 'admin')}</td></tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <nav aria-label="{lang('email_log_pages', 'admin')|escape}" class="d-flex flex-wrap align-items-center gap-3">
            {if $previous_url}<a class="btn btn-secondary" href="{$previous_url|escape}">{lang('log_previous', 'admin')}</a>{/if}
            <span aria-current="page">{lang('log_page_status', 'admin', [$page, $page_count, $total])}</span>
            {if $next_url}<a class="btn btn-secondary" href="{$next_url|escape}">{lang('log_next', 'admin')}</a>{/if}
        </nav>
    </div>
</div>
