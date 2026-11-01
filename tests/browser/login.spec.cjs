const {test, expect, login, openPage} = require('./helpers.cjs');

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
