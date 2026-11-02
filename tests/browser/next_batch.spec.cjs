const {test, expect, login, openPage, fixture, verify} = require('./helpers.cjs');
function seed() { fixture('seed-armory'); fixture('seed-next-lists'); }
async function owner(page) {
    await login(page, 'E2EOWNER');
    const response = await verify(page, '/admin', {send:'1', security_code:'BrowserFixtureOnly'});
    expect(await response.text()).toBe('welcome');
}

test('store orders and GM history have real server pages, matching filters and escaped details', async ({page}) => {
    seed(); await owner(page);
    const errors=[]; page.on('pageerror', error=>errors.push(error.message));
    await page.goto('/store/admin_orders');
    const refundScript = page.locator('script[src*="modules/store/js/admin_orders.js"]');
    const oldRefundUrl = await refundScript.getAttribute('src');
    expect(new URL(oldRefundUrl).searchParams.get('v')).toMatch(/^[a-f0-9]{16}$/);
    fixture('versioned-asset','admin'); await page.reload();
    expect(await refundScript.getAttribute('src')).not.toBe(oldRefundUrl);
    const orders=page.locator('#orders-table tbody tr[data-order]');
    await expect(orders).toHaveCount(25); expect(await orders.first().getAttribute('data-order')).toBe('65');
    await expect(page.locator('#orders-total')).toHaveText('65');
    await page.getByRole('link',{name:'Older orders',exact:true}).click();
    expect(await orders.first().getAttribute('data-order')).toBe('40');
    await page.goto('/store/admin_orders?status=successful&offset=50');
    await expect(orders).toHaveCount(15); expect(await orders.first().getAttribute('data-order')).toBe('115');
    await page.goto('/store/admin_orders?account=E2EPLAYER');
    await expect(page.locator('#orders-total')).toHaveText('33');
    await orders.first().locator('summary').click(); await expect(orders.first()).toContainText('Test Sword');
    await expect(orders.first()).toContainText('Unknown');
    await page.goto('/gm/history');
    const logs=page.locator('tr[data-history]');
    await expect(logs).toHaveCount(25); expect(await logs.first().getAttribute('data-history')).toBe('85');
    await expect(logs.first()).toContainText('<b>GM log 85</b>'); expect(await logs.first().locator('b').count()).toBe(0);
    await page.getByRole('link',{name:'Older records',exact:true}).click();
    expect(await logs.first().getAttribute('data-history')).toBe('60');
    await page.goto('/gm/history?type=characters&account=E2EOWNER');
    await expect(page.locator('#history-total')).toHaveText('29');
    expect(await page.evaluate(()=>typeof $.fn.DataTable)).toBe('undefined');
    await page.goto('/gm/history?account=UNKNOWNPLAYER'); await expect(logs).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('refund failures keep the order visible and successful refunds credit only once', async ({page}) => {
    seed(); await owner(page); await page.goto('/store/admin_orders');
    const row=page.locator('[data-order="65"]'), button=row.getByRole('button',{name:'Refund',exact:true});
    await page.route('**/store/admin_orders/refund/65', route=>route.fulfill({status:503,json:{ok:false}}));
    await button.click(); await page.locator('.swal2-confirm').click();
    await expect(page.locator('#orders-feedback')).toContainText('Unable to confirm');
    await expect(row).toBeVisible(); await expect(button).toBeEnabled();
    await page.unroute('**/store/admin_orders/refund/65');
    await button.click(); await page.locator('.swal2-confirm').click();
    await expect(row).toHaveCount(0); await expect(page.locator('#orders-total')).toHaveText('64');
    await expect(page.locator('#orders-feedback')).toContainText('Refund completed');
    expect((await verify(page,'/store/admin_orders/refund/65')).status()).toBe(409);
    expect((await verify(page,'/store/admin_orders/refund/165')).status()).toBe(409);
    expect((await page.request.get('/store/admin_orders/refund/64')).status()).toBe(405);
    expect((await page.request.post('/store/admin_orders/refund/64',{form:{}})).status()).toBe(403);
    const state=JSON.parse(fixture('next-state'));
    expect(state.balances.find(row=>row.id==='1')).toMatchObject({vp:'1',dp:'2'});
    expect(state.orders.find(row=>row.id==='65')).toMatchObject({completed:'-1'});
    expect(state.orders.some(row=>row.id==='64')).toBe(true);
});

test('cached guild rosters have bounded stable pages and consistent member totals', async ({page}) => {
    seed(); const errors=[];page.on('pageerror', error=>errors.push(error.message));
    await openPage(page,'/guild/1/1');
    const members=page.locator('tr[data-member]');
    await expect(members).toHaveCount(25); expect(await members.first().getAttribute('data-member')).toBe('1');
    await expect(page.locator('.motd .fw-bold')).toHaveText('125');
    await page.getByRole('link',{name:'Next members',exact:true}).click();
    expect(await members.first().getAttribute('data-member')).toBe('26');
    await page.reload(); expect(await members.first().getAttribute('data-member')).toBe('26');
    await page.goto('/guild/1/1?offset=100'); await expect(members).toHaveCount(25);
    expect(await members.first().getAttribute('data-member')).toBe('101');
    await expect(page.getByRole('link',{name:'Next members',exact:true})).toHaveCount(0);
    await page.goto('/guild/1/2'); await expect(members).toHaveCount(0); await expect(page.locator('#content_ajax')).toContainText('No members to display');
    expect((await page.request.get('/guild/999/1')).status()).toBe(400);
    expect((await page.request.get('/guild/1/1?offset[]=0')).status()).toBe(400);
    expect(errors).toEqual([]);
});

for(const theme of ['default','heaven']) test(`${theme} public assets stay isolated with minification on and off`, async ({page})=>{
    fixture('seed-armory'); const errors=[],missing=[];
    page.on('pageerror', error=>errors.push(error.message));
    page.on('response', response=>{if(response.status()>=400 && /\.(js|css)(\?|$)/.test(response.url()))missing.push(response.url());});
    for(const mode of ['minified','files']){
        fixture('public-theme',theme,mode); fixture('public-media','no-slider');
        await openPage(page,'/login');
        expect(await page.evaluate(()=>typeof $.fn.DataTable)).toBe('undefined');
        expect(await page.evaluate(()=>typeof $.fn.owlCarousel)).toBe('undefined');
        expect(await page.evaluate(()=>typeof flux)).toBe('undefined');
        if(mode==='files') {
            const appUrl = () => page.locator('script[src]').evaluateAll((nodes, theme) => nodes.find(node => node.src.includes(`/themes/${theme}/assets/js/app.js`))?.src, theme);
            const oldAppUrl = await appUrl();
            expect(new URL(oldAppUrl).searchParams.get('asset')).toMatch(/^[a-f0-9]{16}$/);
            fixture('versioned-asset','core-'+theme); await page.reload();
            expect(await appUrl()).not.toBe(oldAppUrl);
        }
        const base=mode==='minified' ? await page.locator('script[src*="public-"]').getAttribute('src') : '';
        await page.goto('/armory'); expect(await page.evaluate(()=>typeof $.fn.DataTable)).toBe('function');
        if(mode==='minified') expect(await page.locator('script[src*="public-"]').getAttribute('src')).not.toBe(base);
        await page.goto(theme==='heaven' ? '/news/view/1' : '/news');
        await expect(page.locator(theme==='heaven' ? '[owl].owl-loaded' : '.news-carousel.owl-loaded')).toHaveCount(1);
        const newsUrl = () => page.locator('script[src]').evaluateAll((nodes, theme) => nodes.find(node => node.src.includes(`/themes/${theme}/modules/news/js/news.js`))?.src, theme);
        const oldNewsUrl = await newsUrl();
        expect(oldNewsUrl).toBeTruthy();
        expect(new URL(oldNewsUrl).searchParams.get('v')).toMatch(/^[a-f0-9]{16}$/);
        fixture('versioned-asset',theme); await page.reload();
        expect(await newsUrl()).not.toBe(oldNewsUrl);
        fixture('public-media','slider'); await page.goto('/news');
        if(theme==='default') expect(await page.evaluate(()=>typeof flux.slider)).toBe('function');
        else await expect(page.locator('[owl-carousel="main"].owl-loaded')).toHaveCount(1);
    }
    expect(missing).toEqual([]); expect(errors).toEqual([]);
});
