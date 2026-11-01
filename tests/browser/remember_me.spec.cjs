const {createHash} = require('node:crypto');
const {test, expect, login, openPage, blockExternal, fixture} = require('./helpers.cjs');
const {requestLink, reset} = require('./recovery-helpers.cjs');

function remembered() { return JSON.parse(fixture('remembered')); }
async function credentials(context) {
    return (await context.cookies()).filter(cookie => ['fcms_username', 'fcms_token'].includes(cookie.name));
}
function token(cookies) { return cookies.find(cookie => cookie.name === 'fcms_token').value; }
async function allowed(page) {
    await openPage(page, '/donate_mmo');
    await expect(page.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
}
async function denied(page) {
    await openPage(page, '/donate_mmo');
    await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
    expect(await credentials(page.context())).toEqual([]);
}
async function device(browser, baseURL) {
    const context = await browser.newContext({baseURL});
    await blockExternal(context, baseURL);
    return context;
}
async function replay(browser, baseURL, cookies, accepted = false) {
    const context = await device(browser, baseURL);
    try {
        await context.addCookies(cookies);
        const page = await context.newPage();
        if (accepted) await allowed(page); else await denied(page);
    } finally { await context.close(); }
}

test('remembered login rotates in place and rejects reuse of the previous cookie', async ({page, context, browser, baseURL}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    const original = await credentials(context);
    const raw = token(original);
    expect(raw).toMatch(/^[a-f0-9]{128}$/);
    expect(original).toHaveLength(2);
    for (const cookie of original) {
        expect(cookie).toMatchObject({httpOnly: true, sameSite: 'Lax'});
        expect(cookie.expires).toBeGreaterThan(Date.now() / 1000 + 86400);
    }
    const before = remembered();
    expect(before).toHaveLength(1);
    expect(before[0].token_hash).toBe(createHash('sha256').update(raw).digest('hex'));
    await context.clearCookies({name: 'fusion_session'});
    await allowed(page);
    const rotated = await credentials(context);
    expect(token(rotated)).not.toBe(raw);
    const after = remembered();
    expect(after).toHaveLength(1);
    expect(after[0].id).toBe(before[0].id);
    expect(after[0].token_hash).toBe(createHash('sha256').update(token(rotated)).digest('hex'));
    await replay(browser, baseURL, original);
    await context.clearCookies({name: 'fusion_session'});
    await allowed(page);
    expect(token(await credentials(context))).not.toBe(token(rotated));
});

test('a remembered token at its server expiry is rejected and cleared', async ({page, context}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    fixture('expire-remember', token(await credentials(context)));
    await context.clearCookies({name: 'fusion_session'});
    await denied(page);
    expect(remembered()).toEqual([]);
});

test('logout revokes every remembered device for that account and preserves other accounts', async ({page, context, browser, baseURL}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    const first = await credentials(context);
    const second = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    try {
        await login(await second.newPage(), 'E2EPLAYER', 'BrowserTest123', true);
        await login(await other.newPage(), 'E2EOTHER', 'BrowserTest123', true);
        const secondCookies = await credentials(second);
        const otherCookies = await credentials(other);
        expect(remembered()).toHaveLength(3);
        await page.goto('/logout');
        expect(await credentials(context)).toEqual([]);
        expect(remembered().map(row => row.account_id)).toEqual(['2']);
        await denied(page);
        await replay(browser, baseURL, first);
        await replay(browser, baseURL, secondCookies);
        await replay(browser, baseURL, otherCookies, true);
    } finally { await second.close(); await other.close(); }
});

test('logout after session loss also revokes the token rotated during restoration', async ({page, context, browser, baseURL}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    const original = await credentials(context);
    await context.clearCookies({name: 'fusion_session'});
    await page.goto('/logout');
    expect(remembered()).toEqual([]);
    expect(await credentials(context)).toEqual([]);
    await denied(page);
    await replay(browser, baseURL, original);
});

test('password recovery revokes all remembered devices without affecting another account', async ({page, context, browser, baseURL}) => {
    await login(page, 'E2EPLAYER', 'BrowserTest123', true);
    const first = await credentials(context);
    const second = await device(browser, baseURL);
    const other = await device(browser, baseURL);
    const recovery = await device(browser, baseURL);
    try {
        await login(await second.newPage(), 'E2EPLAYER', 'BrowserTest123', true);
        await login(await other.newPage(), 'E2EOTHER', 'BrowserTest123', true);
        const secondCookies = await credentials(second);
        const otherCookies = await credentials(other);
        const recoveryPage = await recovery.newPage();
        const link = await requestLink(recoveryPage, baseURL);
        await openPage(recoveryPage, link.href);
        expect((await reset(recoveryPage, 'ChangedTest123')).success).toBeTruthy();
        expect(remembered().map(row => row.account_id)).toEqual(['2']);
        await replay(browser, baseURL, first);
        await replay(browser, baseURL, secondCookies);
        await replay(browser, baseURL, otherCookies, true);
        await login(recoveryPage, 'E2EPLAYER', 'ChangedTest123');
    } finally { await second.close(); await other.close(); await recovery.close(); }
});

test('legacy password-hash cookies and malformed tokens cannot restore a session', async ({page, context, baseURL}) => {
    const hash = createHash('sha1').update('E2EPLAYER:BROWSERTEST123').digest('hex').toUpperCase();
    await context.addCookies([
        {name: 'fcms_username', value: 'E2EPLAYER', url: baseURL},
        {name: 'fcms_password', value: hash, url: baseURL},
    ]);
    await denied(page);
    expect((await context.cookies()).some(cookie => cookie.name === 'fcms_password')).toBe(false);
    for (const invalid of ['short', 'A'.repeat(128), 'b'.repeat(128)]) {
        await context.addCookies([
            {name: 'fcms_username', value: 'E2EPLAYER', url: baseURL},
            {name: 'fcms_token', value: invalid, url: baseURL},
        ]);
        await denied(page);
    }
    expect(remembered()).toEqual([]);
});
