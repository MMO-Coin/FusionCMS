const {test, expect, login, openPage} = require('./helpers.cjs');

test('login has visible labels, password-manager hints and a keyboard password toggle', async ({page}) => {
    await openPage(page, '/login');
    const username = page.getByRole('textbox', {name: 'Username', exact: true});
    const password = page.getByLabel('Password', {exact: true});
    await expect(username).toHaveAttribute('autocomplete', 'username');
    await expect(username).toHaveAttribute('autocapitalize', 'none');
    await expect(password).toHaveAttribute('autocomplete', 'current-password');
    await expect(page.locator('label[for="floatingUser"]')).toBeVisible();
    await expect(page.locator('label[for="floatingPassword"]')).toBeVisible();
    await password.fill('BrowserTest123');
    const toggle = page.getByRole('button', {name: 'Show password', exact: true});
    await toggle.focus();
    await toggle.press('Space');
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(password).toHaveAttribute('type', 'text');
    await expect(password).toHaveValue('BrowserTest123');
    await toggle.press('Enter');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect(password).toHaveAttribute('type', 'password');
    await expect(toggle).toHaveCSS('opacity', '1');
    await expect(page).toHaveURL(/\/login$/);
    const bounds = await toggle.boundingBox();
    expect(bounds.width).toBeGreaterThanOrEqual(44);
    expect(bounds.height).toBeGreaterThanOrEqual(44);
    await page.screenshot({path: test.info().outputPath('login-accessibility.png')});
    await test.info().attach('login-accessibility', {path: test.info().outputPath('login-accessibility.png'), contentType: 'image/png'});
    await login(page);
});

test('guests must sign in before opening donation checkout', async ({page}) => {
    await openPage(page, '/donate_mmo');
    await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Select a donation package'})).toHaveCount(0);
});

test('login rejects a wrong password, persists the session and logout removes access', async ({page, context}) => {
    await openPage(page, '/login');
    await page.locator('#floatingUser').fill('E2EPLAYER');
    await page.locator('#floatingPassword').fill('WrongPassword123');
    const rejected = page.waitForResponse(response => response.url().endsWith('/auth/checkLogin'));
    await page.locator('#floatingPassword').press('Enter');
    expect((await (await rejected).json()).redirect).toBe(false);
    await expect(page.locator('.error-feedback')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);

    await login(page);
    await page.reload();
    await expect(page).toHaveURL(/\/ucp$/);
    const cookies = await context.cookies();
    expect(cookies.find(cookie => cookie.name === 'csrf_cookie_name')).toMatchObject({httpOnly: false, sameSite: 'Strict'});
    expect(cookies.find(cookie => cookie.name === 'fusion_session')).toMatchObject({httpOnly: true});
    await page.goto('/donate_mmo');
    await expect(page.getByRole('heading', {name: 'Select a donation package'})).toBeVisible();
    await page.goto('/logout');
    await page.goto('/donate_mmo');
    await expect(page.getByText('You must be signed in to view this page!')).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Select a donation package'})).toHaveCount(0);
});
