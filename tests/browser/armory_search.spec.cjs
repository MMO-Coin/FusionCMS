const {test, expect, openPage, fixture} = require('./helpers.cjs');

async function query(page, data = {}) {
    const token = (await page.context().cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value;
    return page.request.post('/armory/search', {headers: {'X-Requested-With': 'XMLHttpRequest'}, form: {
        csrf_token_name: token, draw: '7', table: 'characters', realm: '1', search: 'Search', start: '0', length: '10', ...data,
    }});
}
async function search(page, value, type = 'characters') {
    await page.locator('#search_field').fill(value);
    // Set the type without the onchange search so the test sends exactly one submission.
    await page.locator('#table').evaluate((node, value) => { node.value = value; }, type);
    await page.locator('#armory-search button').click();
    await expect(page.locator('#armory-search button')).toBeEnabled();
}
test('Armory uses bounded matching pages for characters, guilds and items', async ({page}) => {
    fixture('seed-armory');
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await openPage(page, '/armory');
    for (const type of ['characters', 'guilds', 'items']) {
        const first = await (await query(page, {table: type})).json();
        expect(first.draw).toBe(7); expect(first.recordsFiltered).toBe(125); expect(first.data).toHaveLength(10);
        expect(first.data[0].name).toBe('Search001');
        if (type === 'guilds') { expect(first.data[0].members).toBe(0); expect(first.data[1].ownerName).toBe(''); }
        const last = await (await query(page, {table: type, start: '120'})).json();
        expect(last.data).toHaveLength(5); expect(last.data[0].name).toBe('Search121');
        expect((await (await query(page, {table: type, length: '999999'})).json()).data).toHaveLength(100);
        await search(page, 'Search', type);
        const rows = page.locator('#search_results_' + type + ' tbody tr');
        await expect(rows).toHaveCount(10); await expect(rows.first()).toContainText('Search001');
        await page.locator('#search_results_' + type + '_next').click();
        await expect(rows.first()).toContainText('Search011');
        await page.evaluate(type => $('#search_results_' + type).DataTable().page('last').draw('page'), type);
        await expect(rows).toHaveCount(5); await expect(rows.first()).toContainText('Search121');
    }
    expect(await page.evaluate(() => $.fn.dataTable.tables().length)).toBe(3);
    expect(errors).toEqual([]);
});

test('Armory treats search text literally and rejects malformed pagination', async ({page}) => {
    fixture('seed-armory'); await openPage(page, '/armory');
    for (const value of ['Literal%', 'Literal_', "O'Name", '%41']) {
        const result = await (await query(page, {search: value})).json();
        expect(result.recordsFiltered).toBe(1); expect(result.data).toHaveLength(1);
    }
    for (const invalid of [{length: '0'}, {length: '-1'}, {start: '-1'}, {'start[]': '0', start: undefined}, {realm: '999'}, {table: 'other'}, {search: 'ab'}]) {
        const response = await query(page, Object.fromEntries(Object.entries(invalid).filter(([, value]) => value !== undefined)));
        expect(response.status()).toBe(400); expect((await response.json()).data).toEqual([]);
    }
    await search(page, '<b>Literal</b>');
    const rows = page.locator('#search_results_characters tbody');
    await expect(rows).toContainText('<b>Literal</b>'); expect(await rows.locator('b').count()).toBe(0);
});

test('Armory retries failed requests and reuses tables when filters change', async ({page}) => {
    fixture('seed-armory'); await openPage(page, '/armory');
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const requests = [];
    page.on('request', request => {
        if (new URL(request.url()).pathname === '/armory/search') requests.push(new URLSearchParams(request.postData()));
    });
    await page.route('**/armory/search', route => route.fulfill({status: 503, body: 'Unavailable'}));
    await search(page, 'Search');
    await expect(page.getByRole('alert')).toContainText('Unable to load');
    expect(requests.at(-1).get('refresh')).toBe('1');
    await page.unroute('**/armory/search');
    await search(page, 'Search');
    await expect(page.locator('#search_results_characters tbody tr')).toHaveCount(10);
    expect(requests.at(-1).get('refresh')).toBe('1');
    await search(page, 'Literal%');
    await expect(page.locator('#search_results_characters tbody tr')).toHaveCount(1);
    await expect(page.locator('#search_results_characters tbody')).toContainText('Literal%Name');
    expect(await page.evaluate(() => $.fn.dataTable.tables().length)).toBe(1);
    await expect(page.getByRole('alert')).toBeHidden();
    let release, received;
    const waiting = new Promise(resolve => { received = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/armory/search', async route => {
        if (new URLSearchParams(route.request().postData()).get('search') !== 'Literal_') return route.continue();
        received(); await gate;
        await route.fulfill({json: {draw: 99, recordsTotal: 1, recordsFiltered: 1, data: []}}).catch(() => {});
    });
    await page.locator('#search_field').fill('Literal_');
    await page.locator('#armory-search button').click();
    await waiting;
    await page.locator('#search_field').fill('Search');
    // The theme hides the native select behind its styled dropdown.
    await page.locator('#table').selectOption('guilds', {force: true});
    await expect(page.locator('#search_results_guilds tbody tr').first()).toContainText('Search001');
    release();
    await expect(page.locator('#search_results_characters_wrapper')).toBeHidden();
    await expect(page.locator('#armory-search button')).toBeEnabled();
    expect(errors).toEqual([]);
});

test('Armory reuses counts across pages while rows stay current and search submission refreshes totals', async ({page}) => {
    fixture('seed-armory'); await openPage(page, '/armory');
    const response = await query(page);
    expect(response.headers()['cache-control']).toContain('no-store');
    expect((await response.json()).recordsFiltered).toBe(125);
    const initialCache = JSON.parse(fixture('armory-count-cache'));
    expect(Object.values(initialCache.entries)).toHaveLength(1);

    fixture('armory-change-data');
    const cached = await (await query(page, {start: '10'})).json();
    expect(cached.recordsFiltered).toBe(125);
    expect(cached.data[0].name).toBe('Search011'); expect(Number(cached.data[0].level)).toBe(61);
    expect(JSON.parse(fixture('armory-count-cache')).entries).toEqual(initialCache.entries);
    // A count query here would return 126. Different realms and result types get their own totals.
    expect((await (await query(page, {realm: '2'})).json()).recordsFiltered).toBe(126);
    expect((await (await query(page, {table: 'guilds'})).json()).recordsFiltered).toBe(125);
    expect((await (await query(page, {table: 'items'})).json()).recordsFiltered).toBe(125);
    expect((await (await query(page)).json()).recordsFiltered).toBe(125);

    const submitted = page.waitForResponse(response => new URL(response.url()).pathname === '/armory/search');
    await search(page, 'Search');
    const fresh = await submitted;
    expect(new URLSearchParams(fresh.request().postData()).get('refresh')).toBe('1');
    expect((await fresh.json()).recordsFiltered).toBe(126);
    const nextPage = page.waitForResponse(response => new URL(response.url()).pathname === '/armory/search');
    await page.locator('#search_results_characters_next').click();
    const paged = await nextPage;
    expect(new URLSearchParams(paged.request().postData()).get('refresh')).toBe('0');
    expect((await paged.json()).recordsFiltered).toBe(126);
    await expect(page.locator('#search_results_characters tbody tr').first()).toContainText('Search011');
});

test('Armory count expiry, zero matches and disabled caching observe database changes', async ({page}) => {
    fixture('seed-armory'); await openPage(page, '/armory');
    expect((await (await query(page)).json()).recordsFiltered).toBe(125);
    fixture('armory-change-data');
    expect((await (await query(page)).json()).recordsFiltered).toBe(125);
    fixture('expire-armory-count-cache');
    expect((await (await query(page)).json()).recordsFiltered).toBe(126);

    expect((await (await query(page, {search: 'Arrival'})).json()).recordsFiltered).toBe(0);
    fixture('armory-add-zero-match');
    const missing = await (await query(page, {search: 'Arrival'})).json();
    expect(missing.recordsFiltered).toBe(0); expect(missing.data).toEqual([]);
    const refreshed = await (await query(page, {search: 'Arrival', refresh: '1'})).json();
    expect(refreshed.recordsFiltered).toBe(1); expect(refreshed.data[0].name).toBe('ArrivalHero');

    fixture('armory-delete-added-character');
    expect((await (await query(page)).json()).recordsFiltered).toBe(126);
    const cachedBeforeDisable = JSON.parse(fixture('armory-count-cache'));
    fixture('disable-armory-cache');
    expect((await (await query(page)).json()).recordsFiltered).toBe(125);
    expect(JSON.parse(fixture('armory-count-cache'))).toEqual(cachedBeforeDisable);
});
