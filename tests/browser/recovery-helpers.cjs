const {expect, openPage, inbox} = require('./helpers.cjs');

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
module.exports = {requestLink, reset};
