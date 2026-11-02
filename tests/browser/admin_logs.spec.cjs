const {test, expect, login, fixture, verify} = require('./helpers.cjs');

async function admin(page) {
    await login(page, 'E2EOWNER');
    const response = await verify(page, '/admin', {send: '1', security_code: 'BrowserFixtureOnly'});
    expect((await response.text()).trim()).toBe('welcome');
    fixture('seed-admin-logs');
}
async function visibleLogIds(page) {
    return [...(await page.locator('#log_list').innerText()).matchAll(/Optimization log (\d+)/g)].map(match => Number(match[1]));
}

test('admin log search keeps filters across bounded pages and account log pages do not overlap', async ({page}) => {
    await admin(page);
    await page.goto('/admin/logging');
    await expect.poll(async () => (await visibleLogIds(page)).length).toBe(10);
    await page.locator('#module').selectOption('auth');
    await page.locator('#search').fill('E2EPLAYER');
    const searched = page.waitForResponse(response => response.url().endsWith('/admin/logging/search'));
    await page.locator('#search').press('Enter');
    await searched;
    await expect.poll(async () => (await visibleLogIds(page))[0]).toBe(65);
    const first = await visibleLogIds(page);
    expect(first).toEqual([65, 63, 61, 59, 57, 55, 53, 51, 49, 47]);
    const next = page.waitForResponse(response => response.url().endsWith('/admin/logging/search'));
    await page.locator('#button_log_count').click();
    await next;
    await expect.poll(async () => (await visibleLogIds(page)).length).toBe(20);
    const both = await visibleLogIds(page);
    expect(new Set(both).size).toBe(20);
    expect(both.every(id => id <= 65 && id % 2 === 1)).toBe(true);
    const second = await verify(page, '/admin/accounts/loadMoreLogs/1', {offset: '10', count: '999999', show_more: '999999'});
    const third = await verify(page, '/admin/accounts/loadMoreLogs/1', {offset: '20', count: '999999'});
    const ids = text => [...text.matchAll(/Optimization log (\d+)/g)].map(match => Number(match[1]));
    const a = ids(await second.text()), b = ids(await third.text());
    expect(a).toEqual([55, 54, 53, 52, 51, 50, 49, 48, 47, 46]);
    expect(b).toEqual([45, 44, 43, 42, 41, 40, 39, 38, 37, 36]);
    expect(a.filter(id => b.includes(id))).toEqual([]);
});

test('email logs paginate on the server, retain search and show escaped message details', async ({page}) => {
    await admin(page);
    await page.goto('/admin/emaillogs');
    const rows = page.locator('#emaillogs tbody tr');
    await expect(rows).toHaveCount(25);
    await expect(rows.first()).toContainText('Target email 65');
    await page.getByRole('link', {name: 'Next', exact: true}).click();
    await expect(page).toHaveURL(/page=2/);
    await expect(rows).toHaveCount(25);
    await expect(rows.first()).toContainText('Other email 40');
    await page.locator('#email-log-search').fill('Target');
    await page.locator('#email-log-search').press('Enter');
    await expect(rows).toHaveCount(25);
    await expect(rows.first()).toContainText('Target email 65');
    await page.getByRole('link', {name: 'Next', exact: true}).click();
    await expect(page).toHaveURL(/search=Target&page=2/);
    await expect(rows).toHaveCount(8);
    await rows.first().locator('summary').click();
    await expect(rows.first().locator('pre')).toContainText('<script>window.__unsafeEmail = 1</script>');
    expect(await page.evaluate(() => window.__unsafeEmail)).toBeUndefined();
    await expect(page.getByRole('link', {name: 'Next', exact: true})).toHaveCount(0);
    await page.locator('#email-log-search').fill('no-such-email');
    await page.locator('#email-log-search').press('Enter');
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText('No results');
});
