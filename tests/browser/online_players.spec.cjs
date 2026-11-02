const {test, expect, openPage, fixture} = require('./helpers.cjs');

async function query(page, realm = 1, values = {}) {
    return page.request.get('/online/online_refresh/' + realm, {headers: {'X-Requested-With': 'XMLHttpRequest'}, params: {
        draw: '4', start: '0', length: '10', search: '', ...values,
    }});
}

test('online players have matching bounded pages, realm-scoped staff filtering and literal name searches', async ({page}) => {
    fixture('seed-online');
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await openPage(page, '/online');
    const rows = page.locator('#playersTable tbody tr');
    await expect(rows).toHaveCount(10); await expect(rows.first()).toContainText('Search001');
    await page.locator('#playersTable_next').click(); await expect(rows.first()).toContainText('Search011');
    const first = await (await query(page)).json();
    expect(first.draw).toBe(4); expect(first.recordsTotal).toBe(120); expect(first.recordsFiltered).toBe(120); expect(first.data).toHaveLength(10);
    const large = await (await query(page, 1, {length: '99999'})).json(); expect(large.data).toHaveLength(100);
    const last = await (await query(page, 1, {start: '110'})).json();
    expect(last.data.map(row => row.name)).toEqual(['Search111','Search112','Search113','Search114','Search115','Search121','Search122','Search123','Search124','Search125']);
    expect((await (await query(page, 2)).json()).recordsTotal).toBe(115);
    expect((await (await query(page, 1, {search:'Search116'})).json()).recordsFiltered).toBe(0);
    expect((await (await query(page, 1, {search:'Search121'})).json()).recordsFiltered).toBe(1);
    expect((await (await query(page, 2, {search:'Search121'})).json()).recordsFiltered).toBe(0);
    for (const values of [{length:'0'}, {length:'-1'}, {start:'-1'}, {'start[]':'0'}, {'search[]':'Search'}, {search:'x'.repeat(101)}]) {
        const response = await query(page, 1, values); expect(response.status(), JSON.stringify(values)).toBe(400); expect((await response.json()).data).toEqual([]);
    }
    expect((await query(page, 999)).status()).toBe(400);
    await page.getByLabel('Character name', {exact:true}).fill('Search12');
    await page.getByRole('button', {name:'Search', exact:true}).click(); await expect(rows).toHaveCount(5);
    await page.getByLabel('Character name', {exact:true}).fill('%');
    await page.getByRole('button', {name:'Search', exact:true}).click();
    await expect(page.locator('#online-status')).toContainText('No online players match');
    expect(await page.evaluate(() => $.fn.dataTable.tables().length)).toBe(1);
    expect(errors).toEqual([]);
});

test('online player refresh bypasses stale page cache and recovers from a failed request', async ({page}) => {
    fixture('seed-online');
    await page.route('**/online/online_refresh/*', route => route.fulfill({status:503, json:{status:'error'}}));
    await openPage(page, '/online');
    await expect(page.locator('#online-status')).toContainText('Unable to load');
    await expect(page.getByRole('button', {name:'Try again', exact:true})).toBeEnabled();
    await page.unroute('**/online/online_refresh/*');
    await page.getByRole('button', {name:'Try again', exact:true}).click();
    await expect(page.locator('#playersTable tbody tr')).toHaveCount(10);
    await expect(page.locator('#online-updated')).toBeVisible();
    expect(await page.locator('#online-updated-time').getAttribute('datetime')).toMatch(/^\d{4}-/);
    fixture('online-player-offline');
    expect((await (await query(page)).json()).recordsTotal).toBe(120);
    await page.getByRole('button', {name:'Refresh', exact:true}).click();
    await expect(page.locator('#playersTable tbody tr').first()).toContainText('Search002');
    expect((await (await query(page)).json()).recordsTotal).toBe(119);
    fixture('online-status-stale'); await page.reload();
    await expect(page.locator('#online-status')).toContainText(/offline/i);
    await page.getByRole('button', {name:'Refresh', exact:true}).click();
    await expect(page.locator('#playersTable tbody tr').first()).toContainText('Search002');
    expect((await (await query(page)).json()).recordsTotal).toBe(119);
    // Names and zone labels are text, even if a custom realm supplies markup-like values.
    await page.route('**/online/online_refresh/*', route => route.fulfill({json:{draw:Number(new URL(route.request().url()).searchParams.get('draw')),status:'ok',recordsTotal:1,recordsFiltered:1,
        updated_at:Math.floor(Date.now()/1000),data:[{guid:1,name:'<b>Hero</b>',level:60,race:1,gender:0,class:1,zone:'<i>Zone</i>'}]}}));
    await page.getByRole('button', {name:'Refresh', exact:true}).click();
    await expect(page.locator('#playersTable tbody')).toContainText('<b>Hero</b>');
    expect(await page.locator('#playersTable tbody b, #playersTable tbody i').count()).toBe(0);
    await expect(page.getByRole('button', {name:'Refresh', exact:true})).toBeEnabled();
});

test('switching realms cancels old online requests without rebuilding the table', async ({page}) => {
    fixture('seed-online'); await openPage(page, '/online');
    await expect(page.locator('#playersTable tbody tr')).toHaveCount(10);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    let received, release, completed;
    const waiting = new Promise(resolve => { received = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const finished = new Promise(resolve => { completed = resolve; });
    await page.route('**/online/online_refresh/*', async route => {
        if (!new URL(route.request().url()).pathname.endsWith('/1')) return route.continue();
        received(); await gate;
        try { await route.fulfill({json:{draw:999,status:'empty',recordsTotal:0,recordsFiltered:0,data:[],updated_at:1}}); }
        catch {} finally { completed(); }
    });
    await page.getByRole('button', {name:'Refresh', exact:true}).click(); await waiting;
    try {
        await page.getByLabel('Realm', {exact:true}).selectOption('2');
        await expect(page.locator('#playersTable tbody tr').first().getByRole('link')).toHaveAttribute('href', /\/character\/2\/1$/);
    } finally { release(); }
    await finished;
    expect(await page.evaluate(() => OnlinePlayers.table.page.info().recordsDisplay)).toBe(115);
    expect(await page.evaluate(() => $.fn.dataTable.tables().length)).toBe(1);
    await expect(page.getByRole('button', {name:'Refresh', exact:true})).toBeEnabled();
    expect(errors).toEqual([]);
});
