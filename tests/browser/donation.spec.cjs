const {test, expect, login, blockExternal, checkout, verify, scenario, snapshot, fixture} = require('./helpers.cjs');

test('checkout renders its real QR, polls with renewed CSRF and credits a verified payment once', async ({page, browser, baseURL}, testInfo) => {
    await login(page);
    await page.setExtraHTTPHeaders({'Authorization': 'Bearer browser-test-only', 'X-Request-Only': 'do-not-reflect'});
    const {response, url, reference} = await checkout(page);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/html');
    const html = await response.text();
    expect(html.length).toBeGreaterThan(4000);
    expect(html).toMatch(/<\/html>/i);
    for (const name of ['cookie', 'authorization', 'x-request-only']) expect(response.headers()[name]).toBeUndefined();

    const qrFile = testInfo.outputPath('checkout-qr.png');
    await page.getByRole('img', {name: 'Solana Pay payment QR code'}).screenshot({path: qrFile});
    const qr = new URL(fixture('decode', qrFile));
    const invoice = snapshot().invoices[0];
    expect(qr.protocol).toBe('solana:');
    expect(qr.pathname).toBe(invoice.merchant_wallet);
    expect(qr.searchParams.get('spl-token')).toBe(invoice.token_mint);
    expect(qr.searchParams.get('reference')).toBe(reference);
    expect(qr.searchParams.get('amount')).toBe('1.25');
    expect(invoice).toMatchObject({user_id: '1', status: 'pending', dp_amount: '25'});

    const first = await page.waitForResponse(result => result.url() === url && result.request().method() === 'POST');
    const firstResult = await first.json();
    expect(firstResult.status).toBe('pending');
    const originalToken = new URLSearchParams(first.request().postData()).get('csrf_token_name');
    expect(firstResult.csrf_hash).not.toBe(originalToken);
    const second = await page.waitForResponse(result => result.url() === url && result.request().method() === 'POST');
    expect(new URLSearchParams(second.request().postData()).get('csrf_token_name')).toBe(firstResult.csrf_hash);
    expect((await second.json()).status).toBe('pending');
    expect(snapshot().accounts[0].dp).toBe('0');

    scenario(reference, 'valid');
    await expect(page).toHaveURL(/\/donate_mmo\/success$/, {timeout: 15000});
    await expect(page.getByText('Your MMOCoin payment has been verified and your donation points have been credited.')).toBeVisible();
    const paid = snapshot();
    expect(paid.invoices[0].status).toBe('completed');
    expect(paid.invoices[0].transaction_signature).toBeTruthy();
    expect(paid.accounts[0].dp).toBe('25');
    expect(paid.income).toHaveLength(1);
    expect(paid.income[0].amount).toBe('1');
    for (let attempt = 0; attempt < 3; attempt++) {
        const repeated = await verify(page, url);
        expect(repeated.status()).toBe(200);
        expect((await repeated.json()).status).toBe('success');
    }
    expect(snapshot()).toEqual(paid);

    const other = await browser.newContext({baseURL});
    try {
        await blockExternal(other, baseURL);
        const otherPage = await other.newPage();
        await login(otherPage, 'E2EOTHER');
        expect((await verify(otherPage, url)).status()).toBe(404);
        expect(snapshot()).toEqual(paid);
    } finally {await other.close();}
});

test('RPC outages and rejected payments keep the invoice pending without granting points', async ({page}) => {
    await login(page);
    const {url, reference} = await checkout(page);
    // Stop only this page's automatic requests so each rejection is observed independently.
    await page.goto('/ucp');
    for (const mode of ['outage', 'underpay', 'wrong-mint', 'wrong-recipient', 'failed']) {
        scenario(reference, mode);
        const response = await verify(page, url);
        expect(response.status()).toBe(200);
        expect((await response.json()).status).toBe('pending');
        const state = snapshot();
        expect(state.invoices[0].status).toBe('pending');
        expect(state.accounts[0].dp).toBe('0');
        expect(state.income).toEqual([]);
    }
});

test('checkout and verification enforce POST, valid packages and CSRF', async ({page}) => {
    await login(page);
    expect((await page.request.get('/donate_mmo/checkout')).status()).toBe(405);
    await page.goto('/donate_mmo');
    const invalid = await verify(page, '/donate_mmo/checkout', {package_id: '999999'});
    expect(invalid.status()).toBe(404);
    expect(snapshot().invoices).toEqual([]);
    const {url} = await checkout(page);
    await page.goto('/ucp');
    expect((await page.request.get(url)).status()).toBe(405);
    expect((await page.request.post(url, {form: {}})).status()).toBe(403);
    expect(snapshot().accounts[0].dp).toBe('0');
    expect(snapshot().invoices[0].status).toBe('pending');
});
