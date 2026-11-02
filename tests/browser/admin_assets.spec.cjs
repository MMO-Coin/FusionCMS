const {test, expect, login, verify} = require('./helpers.cjs');

test('admin pages load their own plugins and keep basic pages lean', async ({page}) => {
    const errors = [], missing = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 400 && /\.(js|css)(\?|$)/.test(response.url())) missing.push(response.url()); });
    await login(page, 'E2EOWNER');
    expect((await (await verify(page, '/admin', {send: '1', security_code: 'BrowserFixtureOnly'})).text()).trim()).toBe('welcome');
    await page.goto('/admin/emaillogs');
    const basic = await page.locator('script[src]').evaluateAll(nodes => nodes.map(node => node.src));
    expect(basic.join('\n')).not.toMatch(/apexcharts|codemirror|dropzone|imagesloader|datatables|jquery-ui|iconpicker|owl\.carousel/);
    expect(basic.length).toBeLessThan(22);
    expect(await page.evaluate(() => document.documentElement.classList.contains('dark'))).toBe(true);
    const json = await page.request.get('/admin/emaillogs?is_json_ajax=1', {headers: {'X-Requested-With': 'XMLHttpRequest'}});
    expect(json.headers()['content-type']).toContain('application/json');
    expect((await json.json()).admin_assets).toEqual({css: [], js: [], groups: []});
    await page.goto('/admin/accounts');
    await expect(page.locator('#acclist_wrapper')).toBeVisible();
    expect(await page.evaluate(() => typeof $.fn.DataTable)).toBe('function');
    expect(await page.evaluate(() => typeof ApexCharts)).toBe('undefined');
    await page.goto('/admin');
    await expect(page.locator('.apexcharts-canvas')).toHaveCount(2);
    expect(await page.evaluate(() => typeof $.fn.multiselect)).toBe('function');
    expect(await page.evaluate(() => typeof CodeMirror)).toBe('undefined');
    await page.goto('/admin/email_template/edit/1');
    await expect(page.locator('.CodeMirror')).toBeVisible();
    expect(await page.evaluate(() => !!CodeMirror.modes.htmlmixed)).toBe(true);
    expect(await page.evaluate(() => typeof ApexCharts)).toBe('undefined');
    expect(missing).toEqual([]);
    expect(errors).toEqual([]);
});

test('admin upload, carousel and icon controls receive their dependencies', async ({page}) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, 'E2EOWNER');
    await verify(page, '/admin', {send: '1', security_code: 'BrowserFixtureOnly'});
    await page.goto('/news/admin/new');
    await expect.poll(() => page.evaluate(() => typeof News !== 'undefined' && !!News.imagesloader)).toBe(true);
    expect(await page.evaluate(() => typeof CodeMirror)).toBe('undefined');
    await page.goto('/admin/modules');
    expect(await page.evaluate(() => typeof Dropzone)).toBe('function');
    await page.route('**/application/js/jquery.transit.min.js', async route => {
        await new Promise(resolve => setTimeout(resolve, 500));
        await route.continue();
    });
    await page.goto('/admin/theme');
    await expect(page.locator('.owl-loaded')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => typeof Theme.scroll)).toBe('function');
    await page.evaluate(() => AdminAppearance.Light());
    expect(await page.evaluate(() => document.documentElement.classList.contains('light'))).toBe(true);
    await page.evaluate(() => AdminAppearance.Dark());
    await page.goto('/store/admin_items/add_group');
    await expect(page.locator('.iconpicker-container')).toHaveCount(1);
    expect(await page.evaluate(() => typeof $.fn.spinner)).toBe('function');
    expect(errors).toEqual([]);
});
