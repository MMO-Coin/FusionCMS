<div class="container"><div class="row">
    {$link_active = 'store'}
    {include file="../../ucp/views/ucp_navigation.tpl"}
    <div class="col-lg-8 py-lg-5 pb-5 pb-lg-0">
        <div id="store_wrapper">
            <script>
                $(document).ready(function() {
                    function initializeStore() {
                        if (typeof Store === 'undefined') { setTimeout(initializeStore, 50); return; }
                        Store.Customer.initialize({$vp|intval}, {$dp|intval});
                    }
                    initializeStore();
                });
            </script>
            <div id="checkout"></div>
            <div id="store">
                <form id="catalogue-filter" onsubmit="Store.Catalogue.reload(); return false;" class="catalogue-filter">
                    <div><label for="catalogue-realm">{lang('realm', 'store')}</label>
                        <select id="catalogue-realm" name="realm" sb="native" class="form-select" onchange="Store.Catalogue.realmChanged();" {if !$realms}disabled{/if}>
                            {foreach from=$realms item=realm}<option value="{$realm.id}">{$realm.name|escape}</option>{/foreach}
                        </select>
                    </div>
                    <div><label for="catalogue-group">{lang('group', 'store')}</label>
                        <select id="catalogue-group" name="group" sb="native" class="form-select" onchange="Store.Catalogue.reload();">
                            <option value="all">{lang('catalogue_all_groups', 'store')}</option>
                            {foreach from=$groups item=group}<option value="{$group.id}">{$group.title|escape}</option>{/foreach}
                        </select>
                    </div>
                    <div><label for="catalogue-sort">{lang('sort_by', 'store')}</label>
                        <select id="catalogue-sort" name="sort" sb="native" class="form-select" onchange="Store.Catalogue.reload();">
                            <option value="standard">{lang('default', 'store')}</option><option value="name">{lang('name', 'store')}</option>
                            <option value="priceVp">{lang('price', 'store')} ({lang('vp', 'store')})</option><option value="priceDp">{lang('price', 'store')} ({lang('dp', 'store')})</option>
                            <option value="quality">{lang('item_quality', 'store')}</option>
                        </select>
                    </div>
                    <div><label for="catalogue-quality">{lang('item_quality', 'store')}</label>
                        <select id="catalogue-quality" name="quality" sb="native" class="form-select" onchange="Store.Catalogue.reload();">
                            <option value="ALL">{lang('all_items', 'store')}</option>
                            {foreach from=['poor','common','uncommon','rare','epic','legendary','artifact','heirloom'] item=quality key=id}<option value="{$id}">{lang($quality, 'store')}</option>{/foreach}
                        </select>
                    </div>
                    <div><label for="catalogue-currency">{lang('catalogue_currency', 'store')}</label>
                        <select id="catalogue-currency" name="currency" sb="native" class="form-select" onchange="Store.Catalogue.reload();"><option value="all">{lang('all_items', 'store')}</option><option value="vp">{lang('vp', 'store')}</option><option value="dp">{lang('dp', 'store')}</option></select>
                    </div>
                    <div><label for="catalogue-name">{lang('filter', 'store')}</label><input id="catalogue-name" name="name" class="form-control" type="search" maxlength="100"></div>
                    <div class="catalogue-submit"><button class="nice_button" type="submit" {if !$realms}disabled{/if}>{lang('search', 'store')}</button></div>
                </form>
                <p id="catalogue-status" role="status" aria-live="polite">{lang('catalogue_progress', 'store', [$loaded, $total])}</p>
                <p id="catalogue-error" role="alert" hidden></p>
                <div id="store_realms" data-next="{if $next !== null}{$next}{/if}" data-total="{$total}">{$items_html}</div>
                <p id="catalogue-empty" {if $total}hidden{/if}>{lang('catalogue_empty', 'store')}</p>
                <button id="catalogue-more" type="button" class="nice_button mb-4" onclick="Store.Catalogue.loadMore();" {if $next === null}hidden{/if}>{lang('catalogue_more', 'store')}</button>
                <div class="card" id="cart">
                    <div class="card-header"><span class="fa-duotone fa-shopping-cart" aria-hidden="true"></span> {lang('cart', 'store')} (<span id="cart_item_count">0</span> {lang('items', 'store')})</div>
                    <div class="card-body"><div id="empty_cart">{lang('empty_cart', 'store')}</div><div id="cart_items"></div></div>
                    <div class="card-footer"><div id="cart_price" class="d-flex flex-wrap align-items-center">
                        <div id="vp_price_full" class="p-2"><img src="{$url}application/images/icons/lightning.png" alt=""> <span id="vp_price">0</span> {lang('vp', 'store')}</div>
                        <div id="dp_price_full" class="p-2"><img src="{$url}application/images/icons/coins.png" alt=""> <span id="dp_price">0</span> {lang('dp', 'store')}</div>
                        <div class="ms-auto p-1"><a href="javascript:void(0)" onclick="Store.Cart.checkout(this)" class="nice_button">{lang('checkout', 'store')}</a></div>
                    </div></div>
                </div>
            </div>
        </div>
    </div>
</div></div>
