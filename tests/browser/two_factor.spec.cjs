const crypto = require('node:crypto');
const {test, expect, openPage, login, blockExternal, fixture, verify, snapshot} = require('./helpers.cjs');
const {requestLink, reset} = require('./recovery-helpers.cjs');

const fixedKey = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
async function dismissAlert(page, navigation) {
    if (!navigation) {
        // didClose follows delayed focus restoration; a hidden popup alone is too early.
        await page.evaluate(() => {
            window.totpAlertClosed = false;
            Swal.once('didClose', () => { window.totpAlertClosed = true; });
        });
    }
    await page.locator('.swal2-confirm').click();
    if (navigation) await expect(page).toHaveURL(navigation);
    else await page.waitForFunction(() => window.totpAlertClosed === true);
}
function code(secret, step = Math.floor(Date.now() / 30000)) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = [...secret].map(value => alphabet.indexOf(value).toString(2).padStart(5, '0')).join('');
    const bytes = Buffer.from(bits.match(/.{8}/g).map(value => parseInt(value, 2)));
    const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(step));
    const hash = crypto.createHmac('sha1', bytes).update(counter).digest();
    return String((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}
async function previousCode(secret) {
    // Leave enough of the acceptance window to enter six fields in the real UI.
    await expect.poll(() => 30000 - Date.now() % 30000).toBeGreaterThan(5000);
    return code(secret, Math.floor(Date.now() / 30000) - 1);
}
async function device(browser, baseURL) {
    const {viewport, isMobile, hasTouch, deviceScaleFactor, userAgent} = test.info().project.use;
    const context = await browser.newContext({baseURL, viewport, isMobile, hasTouch, deviceScaleFactor, userAgent});
    await blockExternal(context, baseURL);
    return context;
}
async function firstFactor(page, remember = false, password = 'BrowserTest123', username = 'E2EPLAYER') {
    await openPage(page, '/login');
    await page.locator('#floatingUser').fill(username);
    await page.locator('#floatingPassword').fill(password);
    if (remember) await page.locator('label[for="checkRemember"]').click();
    await page.locator('#floatingPassword').press('Enter');
    await expect(page).toHaveURL(/\/auth\/security$/);
}
async function challenge(page, digit) {
    const fields = page.locator('#otp input');
    for (let index = 0; index < 6; ++index) await fields.nth(index).fill(digit[index]);
    const sent = page.waitForResponse(response => response.url().endsWith('/auth/checkTotp'));
    await page.locator('form input[type="submit"]').click();
    const response = await sent;
    const result = await response.json();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await dismissAlert(page, result.status ? /\/ucp$/ : null);
    return result;
}
async function save(page, enabled, password, digit) {
    if (await page.locator('#security_enabled').inputValue() !== String(enabled)) {
        await page.locator('#security-settings .sbSelector').click();
        await page.locator('#security-settings .sbOptions a').filter({hasText: enabled ? 'Google Authenticator' : 'Disabled'}).click();
        await expect(page.locator('#security_enabled')).toHaveValue(String(enabled));
    }
    await page.locator('#current_password').fill(password);
    await page.locator('#auth_code').fill(digit);
    await expect(page.locator('#current_password')).toHaveValue(password);
    await expect(page.locator('#auth_code')).toHaveValue(digit);
    const sent = page.waitForResponse(response => response.url().endsWith('/ucp/security/submit'));
    await page.locator('#security-settings input[type="submit"]').click();
    const result = await (await sent).json();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    await dismissAlert(page, result.status ? /\/login$/ : null);
    return result;
}
function state() { return JSON.parse(fixture('totp-state')); }

test('enrollment requires a fresh password, server-owned unexpired setup and a valid code, then revokes old devices', async ({page, browser, baseURL}) => {
    const active = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    try {
        await login(page, 'E2EPLAYER', 'BrowserTest123', true);
        const activePage = await active.newPage(); await login(activePage, 'E2EPLAYER', 'BrowserTest123', true);
        const otherPage = await other.newPage(); await login(otherPage, 'E2EOTHER', 'BrowserTest123', true);
        await page.goto('/ucp/security');
        // Keep rejected alerts closing long enough to exercise consecutive form fills.
        await page.addStyleTag({content: '.swal2-popup.swal2-hide { animation-duration: 1s !important; }'});
        const key = await page.locator('#setup-key').textContent();
        expect((await page.request.get('/ucp/security/submit')).status()).toBe(405);
        expect((await page.request.post('/ucp/security/submit', {form: {security_enabled: 'true'}})).status()).toBe(403);
        expect((await save(page, true, 'WrongPassword123', code(key))).status).toBe(false);
        const substitute = code(fixedKey) === code(key) ? code(fixedKey, Math.floor(Date.now() / 30000) - 3) : code(fixedKey);
        expect((await (await verify(page, '/ucp/security/submit', {security_enabled: 'true', current_password: 'BrowserTest123', auth_code: substitute, secret: fixedKey})).json()).status).toBe(false);
        expect(state().binary_secret).toBeNull();
        const sid = (await page.context().cookies()).find(cookie => cookie.name === 'fusion_session').value;
        fixture('expire-totp-setup', sid);
        expect((await save(page, true, 'BrowserTest123', code(key))).message).toContain('expired');
        await page.reload();
        const fresh = await page.locator('#setup-key').textContent();
        expect(fresh).not.toBe(key);
        expect((await save(page, true, 'BrowserTest123', code(fresh))).status).toBe(true);
        expect(state().binary_secret).toHaveLength(40);
        expect(state().claims).toHaveLength(1);
        expect(JSON.parse(fixture('remembered')).map(row => row.account_id)).toEqual(['2']);
        await activePage.goto('/donate_mmo');
        await expect(activePage.getByText('You must be signed in to view this page!')).toBeVisible();
        await otherPage.goto('/donate_mmo');
        await expect(otherPage.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
    } finally { await active.close(); await other.close(); }
});

test('2FA gates protected reads and writes, rejects expired and reused codes across browsers and accepts the next code', async ({page, browser, baseURL}) => {
    fixture('seed-totp', 'binary');
    const pending = await device(browser, baseURL);
    try {
        await openPage(page, '/auth/security');
        await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
        expect(await (await verify(page, '/auth/checkTotp', {digit: code(fixedKey)})).text()).toContain('You must be signed in to view this page!');
        expect(state().claims).toEqual([]);
        await firstFactor(page);
        await page.goto('/donate_mmo'); await expect(page).toHaveURL(/\/auth\/security$/);
        const blocked = await verify(page, '/donate_mmo/checkout', {group: '1'});
        expect(await blocked.text()).not.toContain('Solana Pay payment QR code');
        expect(snapshot().invoices).toEqual([]);
        await page.goto('/auth/security');
        expect((await page.request.get('/auth/checkTotp')).status()).toBe(405);
        expect((await (await verify(page, '/auth/checkTotp', {digit: '12345'})).json()).status).toBe(false);
        expect((await challenge(page, code(fixedKey, Math.floor(Date.now() / 30000) - 3))).status).toBe(false);
        const usedStep = Math.floor(Date.now() / 30000);
        const used = code(fixedKey, usedStep);
        const oldId = (await page.context().cookies()).find(cookie => cookie.name === 'fusion_session').value;
        expect((await challenge(page, used)).status).toBe(true);
        expect((await page.context().cookies()).find(cookie => cookie.name === 'fusion_session').value).not.toBe(oldId);
        const second = await pending.newPage(); await firstFactor(second);
        expect((await challenge(second, used)).status).toBe(false);
        await second.goto('/ucp'); await expect(second).toHaveURL(/\/auth\/security$/);
        expect((await challenge(second, code(fixedKey, usedStep + 1))).status).toBe(true);
    } finally { await pending.close(); }
});

test('disabling 2FA requires a fresh password and unused code and revokes other active and remembered devices', async ({page, browser, baseURL}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    const active = await device(browser, baseURL);
    try {
        const activePage = await active.newPage(); await login(activePage, 'E2EPLAYER', 'BrowserTest123', true);
        fixture('seed-totp', 'binary');
        await page.goto('/ucp/security'); await expect(page).toHaveURL(/\/auth\/security$/);
        const previous = await previousCode(fixedKey);
        expect((await challenge(page, previous)).status).toBe(true);
        await page.goto('/ucp/security');
        await expect(page.locator('#setup-key')).toHaveCount(0);
        const before = state().binary_secret;
        expect((await save(page, false, 'WrongPassword123', code(fixedKey))).status).toBe(false);
        expect((await (await verify(page, '/ucp/security/submit', {security_enabled: 'false', current_password: 'BrowserTest123'})).json()).status).toBe(false);
        expect((await save(page, false, 'BrowserTest123', previous)).status).toBe(false);
        expect(state().binary_secret).toBe(before);
        expect((await save(page, false, 'BrowserTest123', code(fixedKey))).status).toBe(true);
        expect(state().binary_secret).toBeNull();
        expect(JSON.parse(fixture('remembered'))).toEqual([]);
        await activePage.goto('/ucp');
        await expect(activePage.getByText('You must be signed in to view this page!')).toBeVisible();
        await login(page);
    } finally { await active.close(); }
});

test('unverified sessions cannot change 2FA, replacement cannot bypass disable, and rate limits are account-scoped', async ({page, browser, baseURL}) => {
    fixture('seed-totp', 'binary');
    await firstFactor(page);
    const blocked = await page.request.post('/ucp/security/submit', {headers: {'X-Requested-With': 'XMLHttpRequest'}, form: {security_enabled: 'false', current_password: 'BrowserTest123', auth_code: code(fixedKey), csrf_token_name: (await page.context().cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value}});
    expect(blocked.status()).toBe(403);
    expect((await challenge(page, code(fixedKey))).status).toBe(true);
    await page.goto('/ucp/security');
    const original = state().binary_secret;
    expect((await (await verify(page, '/ucp/security/submit', {security_enabled: 'true', current_password: 'BrowserTest123', auth_code: code(fixedKey), secret: fixedKey})).json()).status).toBe(false);
    for (let attempt = 0; attempt < 3; ++attempt) expect((await (await verify(page, '/auth/checkTotp', {digit: 'invalid'})).json()).status).toBe(false);
    expect((await verify(page, '/ucp/security/submit', {security_enabled: 'false', current_password: 'BrowserTest123', auth_code: code(fixedKey)})).status()).toBe(429);
    expect(state().binary_secret).toBe(original);
    const other = await device(browser, baseURL);
    try {
        fixture('seed-totp', 'binary', '2');
        const otherPage = await other.newPage();
        await firstFactor(otherPage, false, 'BrowserTest123', 'E2EOTHER');
        expect((await challenge(otherPage, code(fixedKey))).status).toBe(true);
    } finally { await other.close(); }
});

test('a new login cannot inherit another account authenticator approval even with the same secret', async ({page}) => {
    fixture('seed-totp', 'binary'); fixture('seed-totp', 'binary', '2');
    await firstFactor(page);
    expect((await challenge(page, code(fixedKey))).status).toBe(true);
    const switched = await page.request.post('/auth/checkLogin', {
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        form: {username: 'E2EOTHER', password: 'BrowserTest123', csrf_token_name: (await page.context().cookies()).find(cookie => cookie.name === 'csrf_cookie_name').value},
    });
    expect((await switched.json()).redirect).toBe(true);
    await page.goto('/ucp'); await expect(page).toHaveURL(/\/auth\/security$/);
    expect((await challenge(page, code(fixedKey))).status).toBe(true);
});

for (const mode of ['base32', 'encrypted']) {
    test(`enrollment and authenticator login work with ${mode} account storage`, async ({page}) => {
        fixture('totp-mode', mode);
        await login(page);
        await page.goto('/ucp/security');
        const key = await page.locator('#setup-key').textContent();
        const step = Math.floor(Date.now() / 30000);
        expect((await save(page, true, 'BrowserTest123', code(key, step))).status).toBe(true);
        if (mode === 'encrypted') expect(state().binary_secret).toHaveLength(88);
        else expect(state().token_key).toBe(key);
        await firstFactor(page);
        expect((await challenge(page, code(key, step))).status).toBe(false);
        expect((await challenge(page, code(key, step + 1))).status).toBe(true);
        await page.goto('/donate_mmo');
        await expect(page.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
    });
}

test('password recovery revokes a verified 2FA session and fresh login still requires the existing authenticator', async ({page, browser, baseURL}) => {
    fixture('seed-totp', 'binary');
    await firstFactor(page);
    expect((await challenge(page, await previousCode(fixedKey))).status).toBe(true);
    const recover = await device(browser, baseURL);
    try {
        const recoveryPage = await recover.newPage();
        const link = await requestLink(recoveryPage, baseURL);
        await recoveryPage.goto(link.href);
        expect((await reset(recoveryPage, 'ChangedTest123')).success).toBeTruthy();
        await page.goto('/donate_mmo');
        await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
        await firstFactor(page, false, 'ChangedTest123');
        expect((await challenge(page, code(fixedKey))).status).toBe(true);
    } finally { await recover.close(); }
});
