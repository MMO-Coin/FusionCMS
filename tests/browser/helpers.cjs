const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');
const {execFileSync} = require('node:child_process');
const {test: base, expect} = require('@playwright/test');
const manifest = path.resolve(__dirname, '../../output/playwright/runtime.json');

function runtime() { return JSON.parse(fs.readFileSync(manifest)); }
function fixture(action, ...args) {
    const config = runtime();
    return execFileSync(config.php, [path.join(__dirname, 'fixture.php'), action, config.configPath, ...args], {encoding: 'utf8', windowsHide: true});
}
function scenario(reference, mode) {
    const file = runtime().scenarioPath;
    const scenarios = JSON.parse(fs.readFileSync(file));
    scenarios[reference] = mode;
    fs.writeFileSync(file, JSON.stringify(scenarios));
}
function snapshot() { return JSON.parse(fixture('snapshot')); }
async function openPage(page, url) {
    const needsConsent = !(await page.context().cookies()).some(cookie => cookie.name === 'acceptCookies');
    const response = await page.goto(url);
    if (needsConsent) await page.getByRole('button', {name: 'Accept', exact: true}).click();
    return response;
}
async function blockExternal(context, baseURL) {
    await context.route('**/*', route => new URL(route.request().url()).origin === new URL(baseURL).origin ? route.continue() : route.abort());
}
async function login(page, username = 'E2EPLAYER', password = 'BrowserTest123', remember = false) {
    await openPage(page, '/login');
    await page.locator('#floatingUser').fill(username);
    await page.locator('#floatingPassword').fill(password);
    if (remember) await page.locator('label[for="checkRemember"]').click();
    await page.locator('#floatingPassword').press('Enter');
    await expect(page).toHaveURL(/\/ucp$/);
}
async function checkout(page) {
    await page.goto('/donate_mmo');
    await page.locator('label').filter({hasText: '1.250000000 MMOCoin'}).click();
    const result = page.waitForResponse(response => response.url().endsWith('/donate_mmo/checkout') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Check Out with Solana Pay'}).click();
    const response = await result;
    await expect(page.getByRole('img', {name: 'Solana Pay payment QR code'})).toBeVisible();
    const url = await page.locator('#verification-url').inputValue();
    return {response, url, reference: url.split('/').pop()};
}
async function verify(page, url, data = {}) {
    const cookies = await page.context().cookies();
    const csrf = cookies.find(cookie => cookie.name === 'csrf_cookie_name');
    return page.request.post(url, {form: {csrf_token_name: csrf.value, ...data}});
}
async function shutdown() {
    if (!fs.existsSync(manifest)) return;
    const config = runtime();
    await new Promise((resolve, reject) => {
        const request = https.request(config.rpcUrl + '/shutdown', {
            method: 'POST', ca: fs.readFileSync(path.join(config.root, 'rpc-cert.pem')),
            headers: {Authorization: `Bearer ${config.nonce}`}, timeout: 15000,
        }, response => {response.resume(); response.on('end', resolve);});
        request.on('error', reject); request.on('timeout', () => request.destroy(Error('Fixture shutdown timed out'))); request.end();
    });
    await expect.poll(() => fs.existsSync(manifest), {timeout: 15000}).toBe(false);
}
const test = base.extend({
    isolatedFixture: [async ({context, baseURL}, use) => {
        fixture('reset');
        fs.writeFileSync(runtime().scenarioPath, '{}');
        fs.writeFileSync(runtime().mailPath, '[]');
        // Keep browser tests independent of third-party CDNs and services.
        await blockExternal(context, baseURL);
        await use();
    }, {auto: true}],
});
function inbox() { return JSON.parse(fs.readFileSync(runtime().mailPath)); }
module.exports = {test, expect, login, openPage, blockExternal, checkout, verify, scenario, snapshot, fixture, inbox, shutdown};
