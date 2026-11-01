const {test, expect, login, openPage, blockExternal, fixture, snapshot} = require('./helpers.cjs');

async function device(browser, baseURL) {
    const {viewport, isMobile, hasTouch, deviceScaleFactor, userAgent} = test.info().project.use;
    const context = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent});
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
    expect((await page.context().cookies()).filter(cookie => ['fcms_username', 'fcms_token'].includes(cookie.name))).toEqual([]);
}
async function change(page, oldPassword, newPassword) {
    // Reload the actual form for each attempt, including its current CSRF token.
    await openPage(page, '/ucp/settings');
    await page.locator('#old_password').fill(oldPassword);
    await page.locator('#new_password').fill(newPassword);
    await page.locator('#new_password_confirm').fill(newPassword);
    const sent = page.waitForResponse(response => response.url().endsWith('/ucp/settings/submit') && response.request().method() === 'POST');
    await page.locator('#settings input[type="submit"]').click();
    const response = await sent;
    expect(response.status()).toBe(200);
    const result = await response.json();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await page.locator('.swal2-confirm').click();
    if (result.status === 'success') await expect(page).toHaveURL(/\/login$/);
    return result;
}
async function rejectOldPassword(page) {
    await openPage(page, '/login');
    await page.locator('#floatingUser').fill('E2EPLAYER');
    await page.locator('#floatingPassword').fill('BrowserTest123');
    const sent = page.waitForResponse(response => response.url().endsWith('/auth/checkLogin'));
    await page.locator('#floatingPassword').press('Enter');
    expect((await (await sent).json()).redirect).toBe(false);
    await expect(page.locator('.error-feedback')).toBeVisible();
}

test('settings password change revokes active and remembered devices, rejects stale writes and preserves other accounts', async ({page, context, browser, baseURL}) => {
    const active = await device(browser, baseURL);
    const remembered = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    const replay = await device(browser, baseURL);
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        const activePage = await active.newPage();
        await login(activePage);
        const rememberedPage = await remembered.newPage();
        await login(rememberedPage, 'E2EPLAYER', 'BrowserTest123', true);
        const otherPage = await other.newPage();
        await login(otherPage, 'E2EOTHER', 'BrowserTest123', true);
        const original = (await context.cookies()).filter(cookie => ['fusion_session', 'fcms_username', 'fcms_token'].includes(cookie.name));
        const originalRemembered = (await remembered.cookies()).filter(cookie => ['fcms_username', 'fcms_token'].includes(cookie.name));
        const before = JSON.parse(fixture('remembered'));
        expect((await change(page, 'WrongPassword123', 'ChangedTest123')).status).toBe('error');
        expect((await change(page, 'BrowserTest123', 'short')).status).toBe('error');
        expect(JSON.parse(fixture('remembered'))).toEqual(before);
        await allowed(activePage);
        await allowed(rememberedPage);
        await activePage.locator('label').filter({hasText: '1.250000000 MMOCoin'}).click();

        expect((await change(page, 'BrowserTest123', 'ChangedTest123')).status).toBe('success');
        expect((await context.cookies()).some(cookie => ['fcms_username', 'fcms_token'].includes(cookie.name))).toBe(false);
        expect(JSON.parse(fixture('remembered')).map(row => row.account_id)).toEqual(['2']);
        const stalePost = activePage.waitForResponse(response => response.url().endsWith('/donate_mmo/checkout') && response.request().method() === 'POST');
        await activePage.getByRole('button', {name: 'Check Out with Solana Pay'}).click();
        expect(await (await stalePost).text()).toContain('You must be signed in to view this page!');
        await expect(activePage.getByText('You must be signed in to view this page!')).toBeVisible();
        expect(snapshot().invoices).toEqual([]);
        await denied(page);
        await denied(rememberedPage);
        await allowed(otherPage);
        // No session cookie is carried into this context, only the revoked token.
        await replay.addCookies(originalRemembered);
        const replayPage = await replay.newPage();
        await denied(replayPage);
        await rejectOldPassword(page);
        await login(page, 'E2EPLAYER', 'ChangedTest123', true);
        await context.clearCookies({name: 'fusion_session'});
        await allowed(page);
        await replay.clearCookies();
        await replay.addCookies(original);
        await denied(replayPage);
        await allowed(page);
    } finally { await active.close(); await remembered.close(); await other.close(); await replay.close(); }
});

test('changing to the same password still revokes other active sessions and old session IDs', async ({page, context, browser, baseURL}) => {
    const active = await device(browser, baseURL);
    const replay = await device(browser, baseURL);
    try {
        await login(page);
        const activePage = await active.newPage();
        await login(activePage);
        const original = (await active.cookies()).filter(cookie => cookie.name === 'fusion_session');
        expect((await change(page, 'BrowserTest123', 'BrowserTest123')).status).toBe('success');
        await denied(page);
        await denied(activePage);
        expect((await active.cookies()).find(cookie => cookie.name === 'fusion_session').value).not.toBe(original[0].value);
        await login(page);
        await replay.addCookies(original);
        await denied(await replay.newPage());
        await allowed(page);
        expect((await context.cookies()).some(cookie => cookie.name === 'fcms_token')).toBe(false);
    } finally { await active.close(); await replay.close(); }
});
