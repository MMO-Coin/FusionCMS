const {test, expect, login, openPage, blockExternal, fixture, verify, snapshot} = require('./helpers.cjs');

async function device(browser, baseURL, userAgent) {
    const {viewport, isMobile, hasTouch, deviceScaleFactor, userAgent: defaultAgent} = test.info().project.use;
    const context = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent: userAgent || defaultAgent});
    await blockExternal(context, baseURL);
    return context;
}
async function allowed(page) {
    await openPage(page, '/donate_mmo');
    await expect(page.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
}
async function denied(page) {
    await openPage(page, '/donate_mmo');
    await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
}
function inventory(account = 1) { return JSON.parse(fixture('device-inventory')).filter(row => Number(row.account_id) === account); }
function remembered(account = 1) { return JSON.parse(fixture('remembered')).filter(row => Number(row.account_id) === account); }
async function signOut(page, confirm = true) {
    await page.locator('#sign-out-everywhere button').click();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    if (!confirm) { await page.getByRole('button', {name: 'Cancel', exact: true}).click(); return; }
    const sent = page.waitForResponse(response => response.url().endsWith('/ucp/security/signOutEverywhere') && response.request().method() === 'POST');
    await page.locator('.swal2-confirm').click();
    expect((await sent).status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
}

test('security inventory shows only this account, marks the current browser, escapes metadata and hides credentials', async ({page, browser, baseURL}) => {
    const second = await device(browser, baseURL, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/123.0');
    const maliciousAgent = '<img src=x onerror="window.deviceXss=1">';
    const hostile = await device(browser, baseURL, 'TestInjectionBrowser/1.0');
    const other = await device(browser, baseURL, 'PrivateOtherAccount/1.0');
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        await login(await second.newPage(), 'E2EPLAYER', 'BrowserTest123', true);
        await login(await hostile.newPage(), 'E2EPLAYER', 'BrowserTest123', true);
        await login(await other.newPage(), 'E2EOTHER', 'BrowserTest123', true);
        fixture('hostile-device-metadata');
        await page.goto('/ucp/security');
        await expect(page.locator('#active-sessions .security-device')).toHaveCount(3);
        await expect(page.locator('#remembered-devices .security-device')).toHaveCount(3);
        await expect(page.getByText('Current browser', {exact: true})).toHaveCount(1);
        await expect(page.getByText('Saved on this browser', {exact: true})).toHaveCount(1);
        await expect(page.locator('#active-sessions')).toContainText('Firefox on Linux');
        await expect(page.locator('#account-security')).not.toContainText('PrivateOtherAccount');
        expect(await page.locator('.security-agent').filter({hasText: 'onerror'}).first().textContent()).toBe(maliciousAgent);
        expect(await page.evaluate(() => window.deviceXss)).toBeUndefined();
        await expect(page.locator('#account-security img[src="x"]')).toHaveCount(0);
        const html = await page.content();
        for (const cookie of await page.context().cookies()) {
            if (['fusion_session', 'fcms_token'].includes(cookie.name)) expect(html).not.toContain(cookie.value);
        }
        for (const row of JSON.parse(fixture('device-inventory'))) expect(html).not.toContain(row.session_hash);
        for (const row of JSON.parse(fixture('remembered'))) expect(html).not.toContain(row.token_hash);
        const before = inventory().map(row => [row.session_hash, row.created_at]);
        await page.reload(); await page.reload();
        expect(inventory().map(row => [row.session_hash, row.created_at])).toEqual(before);
        expect(remembered().every(row => row.user_agent && row.ip_address === '127.0.0.1')).toBe(true);
    } finally { await second.close(); await hostile.close(); await other.close(); }
});

test('restoration updates remembered metadata, expired entries disappear and logout removes the current session', async ({page, context, browser, baseURL}) => {
    const other = await device(browser, baseURL);
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        await login(await other.newPage(), 'E2EOTHER', 'BrowserTest123', true);
        expect(remembered()[0].last_used_at).toBeNull();
        const original = remembered()[0];
        await context.clearCookies({name: 'fusion_session'});
        await allowed(page);
        expect(remembered()).toHaveLength(1);
        expect(remembered()[0].id).toBe(original.id);
        expect(remembered()[0].token_hash).not.toBe(original.token_hash);
        expect(remembered()[0].last_used_at).not.toBeNull();
        fixture('expire-device-session');
        await page.goto('/ucp/security');
        await expect(page.locator('#active-sessions .security-device')).toHaveCount(1);
        expect(inventory()).toHaveLength(1);
        const token = (await context.cookies()).find(cookie => cookie.name === 'fcms_token').value;
        fixture('expire-remember', token);
        await page.reload();
        await expect(page.locator('#remembered-devices')).toContainText('No remembered devices.');
        await page.goto('/logout');
        expect(inventory()).toEqual([]);
        expect(inventory(2)).toHaveLength(1);
        expect(remembered()).toEqual([]);
        expect(remembered(2)).toHaveLength(1);
    } finally { await other.close(); }
});

test('a fresh login replaces this browser inventory and rejects replay of its previous session ID', async ({page, context, browser, baseURL}) => {
    const replay = await device(browser, baseURL);
    try {
        await login(page);
        const oldSession = (await context.cookies()).find(cookie => cookie.name === 'fusion_session');
        expect(inventory()).toHaveLength(1);
        const nonce = (await context.cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value;
        const response = await page.request.post('/auth/checkLogin', {
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            form: {username: 'E2EPLAYER', password: 'BrowserTest123', csrf_token_name: nonce},
        });
        expect((await response.json()).redirect).toBe(true);
        expect((await context.cookies()).find(cookie => cookie.name === 'fusion_session').value).not.toBe(oldSession.value);
        await page.goto('/ucp/security');
        await expect(page.locator('#active-sessions .security-device')).toHaveCount(1);
        expect(inventory()).toHaveLength(1);
        await replay.addCookies([oldSession]);
        await denied(await replay.newPage());
        await allowed(page);
    } finally { await replay.close(); }
});

test('sign out everywhere revokes active sessions, stale writes, session IDs and remembered tokens while preserving another account', async ({page, context, browser, baseURL}) => {
    const active = await device(browser, baseURL);
    const saved = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    const replay = await device(browser, baseURL);
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        const activePage = await active.newPage(); await login(activePage);
        const oldSession = (await active.cookies()).filter(cookie => cookie.name === 'fusion_session');
        const savedPage = await saved.newPage(); await login(savedPage, 'E2EPLAYER', 'BrowserTest123', true);
        const oldRemembered = (await saved.cookies()).filter(cookie => ['fcms_username', 'fcms_token'].includes(cookie.name));
        await saved.clearCookies({name: 'fusion_session'});
        const otherPage = await other.newPage(); await login(otherPage, 'E2EOTHER', 'BrowserTest123', true);
        await page.goto('/ucp/security');
        await signOut(page, false);
        expect(remembered()).toHaveLength(2);
        await allowed(activePage);
        await activePage.locator('label').filter({hasText: '1.250000000 MMOCoin'}).click();
        await signOut(page);
        expect((await context.cookies()).some(cookie => ['fcms_token', 'fcms_username'].includes(cookie.name))).toBe(false);
        expect(remembered()).toEqual([]);
        expect(remembered(2)).toHaveLength(1);
        const stalePost = activePage.waitForResponse(response => response.url().endsWith('/donate_mmo/checkout') && response.request().method() === 'POST');
        await activePage.getByRole('button', {name: 'Check Out with Solana Pay'}).click();
        expect(await (await stalePost).text()).toContain('You must be signed in to view this page!');
        expect(snapshot().invoices).toEqual([]);
        await denied(savedPage);
        await allowed(otherPage);
        await replay.addCookies(oldRemembered); const replayPage = await replay.newPage(); await denied(replayPage);
        await replay.clearCookies(); await replay.addCookies(oldSession); await denied(replayPage);
        await login(page);
        await page.goto('/ucp/security');
        await expect(page.locator('#active-sessions .security-device')).toHaveCount(1);
        await expect(page.locator('#remembered-devices')).toContainText('No remembered devices.');
    } finally { await active.close(); await saved.close(); await other.close(); await replay.close(); }
});

test('guests, GET, missing CSRF and sessions awaiting 2FA cannot sign out an account', async ({page, browser, baseURL}) => {
    await openPage(page, '/ucp/security');
    await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
    expect(await (await verify(page, '/ucp/security/signOutEverywhere')).text()).toContain('You must be signed in to view this page!');
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    await page.goto('/ucp/security');
    expect((await page.request.get('/ucp/security/signOutEverywhere')).status()).toBe(405);
    expect((await page.request.post('/ucp/security/signOutEverywhere', {form: {account_id: 2}})).status()).toBe(403);
    await allowed(page);
    const before = remembered();
    fixture('seed-totp', 'binary');
    const pending = await device(browser, baseURL);
    try {
        const pendingPage = await pending.newPage();
        await openPage(pendingPage, '/login');
        await pendingPage.locator('#floatingUser').fill('E2EPLAYER');
        await pendingPage.locator('#floatingPassword').fill('BrowserTest123');
        await pendingPage.locator('#floatingPassword').press('Enter');
        await expect(pendingPage).toHaveURL(/\/auth\/security$/);
        const nonce = (await pending.cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value;
        const response = await pendingPage.request.post('/ucp/security/signOutEverywhere', {headers: {'X-Requested-With': 'XMLHttpRequest'}, form: {csrf_token_name: nonce}});
        expect(response.status()).toBe(403);
        expect(remembered()).toEqual(before);
    } finally { await pending.close(); }
});

test('security works with 2FA disabled and file sessions, and forged account IDs cannot revoke another user', async ({page, browser, baseURL}) => {
    fixture('security-options');
    const active = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        const activePage = await active.newPage(); await login(activePage);
        const otherPage = await other.newPage(); await login(otherPage, 'E2EOTHER', 'BrowserTest123', true);
        await page.locator('.list-group').getByRole('link', {name: 'Account Security', exact: true}).click();
        await expect(page).toHaveURL(/\/ucp\/security$/);
        await expect(page.locator('#security-settings')).toHaveCount(0);
        await expect(page.locator('#active-sessions .security-device')).toHaveCount(2);
        expect((await verify(page, '/ucp/security/submit', {security_enabled: 'true'})).status()).toBe(403);
        const result = await verify(page, '/ucp/security/signOutEverywhere', {account_id: '2', uid: '2'});
        expect((await result.json()).status).toBe(true);
        await denied(page); await denied(activePage); await allowed(otherPage);
        expect(remembered()).toEqual([]); expect(remembered(2)).toHaveLength(1);
    } finally { await active.close(); await other.close(); }
});
