{foreach from=$items item=item}
<article class="store_item row p-2 border rounded mb-2 catalogue-item" id="item_{$item.id}" data-catalogue-id="{$item.id}">
    <div class="col-sm-2 col-lg-1 mt-1">
        <img class="item_icon img-responsive rounded" src="{$CI->config->item('api_item_icons')}/medium/{$item.icon|default:'inv_misc_questionmark'|escape:'url'}.jpg" alt="" width="36" height="36" loading="lazy" {if $item.tooltip}data-realm="{$item.realm}" rel="item={$item.itemid|escape}"{/if}>
        {if $item.itemcount > 1 && !preg_match('/,/', $item.itemcount)}<span class="wh-icon-text" data-type="number">{$item.itemcount|escape}</span>{/if}
    </div>
    <div class="col-sm-10 col-lg-5 mt-1">
        <a {if $item.tooltip}href="{$url}item/{$item.realm}/{$item.itemid|escape:'url'}" data-realm="{$item.realm}" rel="item={$item.itemid|escape}"{/if} class="item_name q{$item.quality|intval}">{$item.name|escape}</a>
        <p class="catalogue-description mb-0">{$item.description|escape}</p>
    </div>
    <div class="store_buttons col-lg-6 mt-2 d-flex justify-content-lg-end gap-2 flex-wrap">
        {foreach from=$item.prices item=price key=currency}
            {if $price > 0}
            <button type="button" class="nice_button {$currency}_button" data-catalogue-add data-id="{$item.id}" data-item-id="{$item.itemid|escape}" data-name="{$item.name|escape}"
                data-price="{$price|intval}" data-price-type="{$currency}" data-realm="{$realmId}" data-realm-name="{$realmName|escape}" data-quality="{$item.quality|intval}" data-tooltip="{$item.tooltip|intval}">
                <img src="{$url}application/images/icons/{if $currency == 'vp'}lightning{else}coins{/if}.png" alt="" width="16" height="16">
                <span class="{$currency}_price_value">{$price|intval}</span> {lang($currency, 'store')}
            </button>
            {/if}
        {/foreach}
    </div>
</article>
{/foreach}
