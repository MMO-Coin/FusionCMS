const {test, expect, openPage, login, fixture, inbox} = require('./helpers.cjs');

async function requestLink(page, baseURL) {
    await openPage(page, '/password_recovery');
    await page.locator('.email-input').fill('e2eplayer@example.invalid');
    const requested = page.waitForResponse(response => response.url().endsWith('/password_recovery/create_request'));
    await page.getByRole('button', {name: 'Recover password', exact: true}).click();
    const response = await requested;
    expect(response.status()).toBe(200);
    expect((await response.json()).messages.success).toBeTruthy();
    await expect.poll(() => inbox().length).toBe(1);
    const raw = inbox()[0];
    const decoded = raw.replace(/=\r?\n/g, '').replace(/=([a-f0-9]{2})/gi, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
    const match = decoded.match(/https?:\/\/127\.0\.0\.1:\d+\/password_recovery\/reset_password\?token=[a-f0-9]{128}/);
    expect(match, 'The actual SMTP message must contain a reset link').toBeTruthy();
    const link = new URL(match[0]);
    expect(link.origin).toBe(new URL(baseURL).origin);
    return link;
}
async function reset(page, password) {
    await page.locator('.password-input').fill(password);
    const sent = page.waitForResponse(response => response.url().includes('/password_recovery/reset_password') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Reset your password', exact: true}).click();
    const response = await sent;
    expect(response.status()).toBe(200);
    return (await response.json()).messages;
}
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
