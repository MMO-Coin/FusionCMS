const {test, expect, openPage, login, fixture, blockExternal, snapshot} = require('./helpers.cjs');
const {requestLink, reset} = require('./recovery-helpers.cjs');
async function rejectPassword(page, password) {
    await openPage(page, '/login');
    await page.locator('#floatingUser').fill('E2EPLAYER');
    await page.locator('#floatingPassword').fill(password);
    const submitted = page.waitForResponse(response => response.url().endsWith('/auth/checkLogin'));
    await page.locator('#floatingPassword').press('Enter');
    expect((await (await submitted).json()).redirect).toBe(false);
    await expect(page.locator('.error-feedback')).toBeVisible();
}

test('emailed reset links work once, preserve tokens after validation errors and reject reuse', async ({page, baseURL}) => {
    const messages = [];
    page.on('console', message => messages.push(message.text()));
    const link = await requestLink(page, baseURL);
    await openPage(page, link.href);
    expect((await reset(page, 'short')).error).toBeTruthy();
    expect((await reset(page, 'ChangedTest123')).success).toBeTruthy();
    await expect(page.locator('.error-feedback')).toContainText('The password has been successfully reset');
    await openPage(page, link.href);
    expect((await reset(page, 'ReusedTest123')).error).toContain('Invalid or expired');
    await expect(page.locator('.error-feedback')).toContainText('Please request a new one');
    await rejectPassword(page, 'BrowserTest123');
    await rejectPassword(page, 'ReusedTest123');
    await login(page, 'E2EPLAYER', 'ChangedTest123');
    expect(messages.some(message => message.includes('ChangedTest123') || message.includes(link.searchParams.get('token')))).toBe(false);
});

test('an emailed token at the one-hour expiry boundary cannot change the password', async ({page, baseURL}) => {
    const link = await requestLink(page, baseURL);
    fixture('age-recovery', link.searchParams.get('token'), '3600');
    await openPage(page, link.href);
    expect((await reset(page, 'ExpiredTest123')).error).toContain('Invalid or expired');
    await rejectPassword(page, 'ExpiredTest123');
    await login(page);
});

test('malformed reset tokens stay escaped and cannot change the password', async ({page}) => {
    const token = '" data-reset-injection="yes';
    await openPage(page, '/password_recovery/reset_password?token=' + encodeURIComponent(token));
    await expect(page.locator('.token-input')).toHaveValue(token);
    await expect(page.locator('[data-reset-injection]')).toHaveCount(0);
    expect((await reset(page, 'InvalidTest123')).error).toContain('Invalid or expired');
    await login(page);
});

test('resetting to the same password revokes active sessions and rejects old session IDs after a fresh login', async ({page, context, browser, baseURL}) => {
    const {viewport, isMobile, hasTouch, deviceScaleFactor, userAgent} = test.info().project.use;
    const recovery = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent});
    const other = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent});
    const replay = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent});
    try {
        for (const device of [recovery, other, replay]) await blockExternal(device, baseURL);
        await login(page);
        const original = (await context.cookies()).filter(cookie => cookie.name === 'fusion_session');
        expect(original).toHaveLength(1);
        expect((await context.cookies()).some(cookie => cookie.name === 'fcms_token')).toBe(false);
        await page.goto('/donate_mmo');
        await page.locator('label').filter({hasText: '1.250000000 MMOCoin'}).click();
        const otherPage = await other.newPage();
        await login(otherPage, 'E2EOTHER');
        const recoveryPage = await recovery.newPage();
        const link = await requestLink(recoveryPage, baseURL);
        await openPage(recoveryPage, link.href);
        expect((await reset(recoveryPage, 'BrowserTest123')).success).toBeTruthy();
        const checkout = page.waitForResponse(response => response.url().endsWith('/donate_mmo/checkout') && response.request().method() === 'POST');
        await page.getByRole('button', {name: 'Check Out with Solana Pay'}).click();
        expect(await (await checkout).text()).toContain('You must be signed in to view this page!');
        await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
        expect(snapshot().invoices).toEqual([]);
        expect((await context.cookies()).find(cookie => cookie.name === 'fusion_session').value).not.toBe(original[0].value);
        await otherPage.goto('/donate_mmo');
        await expect(otherPage.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
        await login(page);
        await replay.addCookies(original);
        const replayPage = await replay.newPage();
        await openPage(replayPage, '/donate_mmo');
        await expect(replayPage.getByText('You must be signed in to view this page!')).toBeVisible();
        await page.goto('/donate_mmo');
        await expect(page.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
    } finally { await recovery.close(); await other.close(); await replay.close(); }
});
