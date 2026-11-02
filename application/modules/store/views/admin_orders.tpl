<div class="card"><div class="card-body">
    <form method="get" class="d-flex flex-wrap gap-2 mb-3" action="{$url}store/admin_orders">
        <label for="order-status">{lang('order_status', 'store')}</label>
        <select id="order-status" name="status" class="form-select w-auto">
            <option value="failed" {if $status == 'failed'}selected{/if}>{lang('failed_orders_week', 'store')}</option>
            <option value="successful" {if $status == 'successful'}selected{/if}>{lang('successful_orders', 'store')}</option>
        </select>
        <label for="order-account">{lang('search_by_username', 'store')}</label>
        <input id="order-account" name="account" value="{$search|escape}" maxlength="32" class="form-control w-auto">
        <button class="btn btn-primary" type="submit">{lang('search', 'store')}</button>
    </form>
    <p>{lang('order_count', 'store')}: <span id="orders-total">{$total}</span></p>
    <p id="orders-feedback" role="status" aria-live="polite"></p>
    <div class="table-responsive"><table class="table table-hover" id="orders-table">
        <thead><tr><th>{lang('order_number', 'store')}</th><th>{lang('date', 'store')}</th><th>{lang('account', 'store')}</th><th>VP / DP</th><th>{lang('items', 'store')}</th><th>{lang('actions', 'store')}</th></tr></thead>
        <tbody>{foreach from=$results item=order}
            <tr data-order="{$order.id}"><td>{$order.id}</td><td>{date('Y/m/d H:i', $order.timestamp)}</td>
                <td><a href="{$url}admin/accounts/get/{$order.user_id}">{$order.username|escape}</a></td>
                <td>{$order.vp_cost|escape} / {$order.dp_cost|escape}</td>
                <td><details><summary>{count($order.json)} {lang('items', 'store')}</summary><ul>{foreach from=$order.json item=item}<li>{$item.itemName|escape} {lang('to', 'store')} {$item.characterName|escape}</li>{/foreach}</ul></details></td>
                <td>{if $order.completed == 0 && hasPermission('canRefundOrders')}<button type="button" class="btn btn-outline-danger" onclick="Orders.refund({$order.id}, this)">{lang('refund', 'store')}</button>{/if}</td>
            </tr>
        {foreachelse}<tr><td colspan="6">{lang('no_matches', 'store')}</td></tr>{/foreach}</tbody>
    </table></div>
    <nav class="d-flex gap-3" aria-label="{lang('orders', 'store')}">
        {if $previous}<a rel="prev" href="{$previous|escape}">{lang('newer_orders', 'store')}</a>{/if}
        {if $next}<a rel="next" href="{$next|escape}">{lang('older_orders', 'store')}</a>{/if}
    </nav>
</div></div>
