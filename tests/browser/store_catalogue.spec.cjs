const {test, expect, login, fixture} = require('./helpers.cjs');

async function catalogue(page, data = {}) {
    const csrf = (await page.context().cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value;
    return page.request.post('/store/catalogue', {headers:{'X-Requested-With':'XMLHttpRequest'}, form:{
        csrf_token_name:csrf, realm:'1', offset:'0', limit:'25', ...data,
    }});
}

test('catalogue loads bounded groups progressively and keeps the cart while filters change', async ({page}) => {
    fixture('seed-store-catalogue'); await login(page); await page.goto('/store');
    const errors=[]; page.on('pageerror', error=>errors.push(error.message));
    const items=page.locator('#store_realms [data-catalogue-id]');
    await expect(items).toHaveCount(25);
    await page.locator('#catalogue-group').selectOption('31');
    await expect(page.locator('#catalogue-status')).toHaveText('Showing 25 of 65 items');
    expect(await items.first().getAttribute('data-catalogue-id')).toBe('1001');
    await items.first().locator('.vp_button').click();
    await expect(page.locator('#cart_item_count')).toHaveText('1');
    await expect(page.locator('#cart_items')).toContainText('Catalogue item 001');
    await page.getByRole('button',{name:'Load more items',exact:true}).click();
    await expect(items).toHaveCount(50);
    await page.getByRole('button',{name:'Load more items',exact:true}).click();
    await expect(items).toHaveCount(65);
    const ids=await items.evaluateAll(nodes=>nodes.map(node=>node.getAttribute('data-catalogue-id')));
    expect(new Set(ids).size).toBe(65);
    await expect(page.getByRole('button',{name:'Load more items',exact:true})).toBeHidden();
    await page.locator('#catalogue-group').selectOption('0');
    await expect(items).toHaveCount(3); await expect(page.locator('#catalogue-status')).toHaveText('Showing 3 of 3 items');
    await expect(page.locator('#cart_item_count')).toHaveText('1');
    await page.locator('#catalogue-name').fill('Literal%');
    await page.locator('#catalogue-filter button[type=submit]').click();
    await expect(items).toHaveCount(1); expect(await items.first().getAttribute('data-catalogue-id')).toBe('2002');
    await page.locator('#catalogue-name').fill(''); await page.locator('#catalogue-group').selectOption('1');
    await expect(items).toHaveCount(1); await expect(items.first()).toContainText("O'Name <b>Sword</b>");
    expect(await items.first().locator('b').count()).toBe(0);
    await items.first().locator('.dp_button').click();
    await expect(page.locator('#cart_item_count')).toHaveText('2');
    await expect(page.locator('#cart_items')).toContainText("O'Name <b>Sword</b>");
    expect(await page.locator('#cart_items b').count()).toBe(0);
    let submitted;
    await page.route('**/store/checkout', route=>{
        submitted=JSON.parse(new URLSearchParams(route.request().postData()).get('cart'));
        return route.fulfill({status:200,body:'<div id="catalogue-checkout-fixture"><select data-id="1001" data-available="1"><option value="7">Checkout character</option></select><p>Account-wide query has no character selector.</p><button type="button" onclick="Store.Cart.pay()">Pay for cart</button></div>'});
    });
    await page.locator('#cart').getByRole('link',{name:'Checkout',exact:true}).click();
    await expect(page.locator('#catalogue-checkout-fixture')).toBeVisible();
    expect(submitted).toEqual([{id:1001,type:'vp'},{id:2004,type:'dp'}]);
    let paid;
    await page.route('**/store/pay', route=>{
        paid=JSON.parse(new URLSearchParams(route.request().postData()).get('data'));
        return route.fulfill({status:200,body:'<p id="catalogue-payment-fixture">Payment cart recorded</p>'});
    });
    await page.getByRole('button',{name:'Pay for cart',exact:true}).click();
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#catalogue-payment-fixture')).toBeVisible();
    expect(paid).toEqual([{id:1001,type:'vp',character:'7'},{id:2004,type:'dp'}]);
    expect(Object.hasOwn(paid[1],'character')).toBe(false);
    expect(errors).toEqual([]);
});

test('catalogue validates literal filters and recovers from failed or superseded requests', async ({page}) => {
    fixture('seed-store-catalogue'); await login(page); await page.goto('/store');
    const errors=[]; page.on('pageerror', error=>errors.push(error.message));
    expect((await (await catalogue(page,{realm:'2'})).json()).total).toBe(3);
    for(const name of ['Literal%','Literal_',"O'Name"]){
        const result=await (await catalogue(page,{name})).json(); expect(result.total).toBe(1);
        expect((result.html.match(/data-catalogue-id=/g)||[]).length).toBe(1);
    }
    const final=await (await catalogue(page,{group:'31',offset:'50'})).json();
    expect(final.total).toBe(65);expect(final.next).toBe(null);expect((final.html.match(/data-catalogue-id=/g)||[]).length).toBe(15);
    for(const invalid of [{offset:'-1'},{limit:'0'},{limit:'-1'},{realm:'999'},{quality:'8'},{sort:'invalid'},{'group[]':'31'}]){
        expect((await catalogue(page,invalid)).status()).toBe(400);
    }
    const items=page.locator('#store_realms [data-catalogue-id]');
    await page.locator('#catalogue-group').selectOption('31');
    await expect(page.locator('#catalogue-status')).toHaveText('Showing 25 of 65 items');
    await page.route('**/store/catalogue', route=>route.fulfill({status:503,body:'Unavailable'}));
    await page.locator('#catalogue-more').click();
    await expect(page.locator('#catalogue-error')).toContainText('Unable to load more');
    await expect(items).toHaveCount(25); await expect(page.locator('#catalogue-more')).toBeEnabled();
    await page.unroute('**/store/catalogue'); await page.locator('#catalogue-more').click(); await expect(items).toHaveCount(50);
    let release, received, finished;
    const waiting=new Promise(resolve=>{received=resolve;}); const gate=new Promise(resolve=>{release=resolve;}); const done=new Promise(resolve=>{finished=resolve;});
    await page.route('**/store/catalogue', async route=>{
        if(new URLSearchParams(route.request().postData()).get('group')!=='31')return route.continue();
        received(); await gate;
        try { await route.fulfill({json:{html:'<p>Stale catalogue response</p>',groups:[],total:999,next:null}}); } catch {} finally { finished(); }
    });
    // Trigger a new request for the current group, then change groups before it completes.
    await page.locator('#catalogue-filter button[type=submit]').click(); await waiting;
    await page.locator('#catalogue-group').selectOption('0');
    await expect(items).toHaveCount(3);await expect(page.locator('#catalogue-status')).toHaveText('Showing 3 of 3 items');
    release();await done;
    await expect(page.locator('#store_realms')).not.toContainText('Stale catalogue response');
    await expect(page.locator('#catalogue-group')).toHaveValue('0');
    await expect(page.locator('#catalogue-filter button[type=submit]')).toBeEnabled();
    expect(errors).toEqual([]);
});
