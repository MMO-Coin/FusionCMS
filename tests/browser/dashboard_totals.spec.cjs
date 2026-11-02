const {test, expect, login, fixture, verify} = require('./helpers.cjs');

test('dashboard totals use calendar aggregates and refresh their short cache', async ({page}) => {
    const expected=JSON.parse(fixture('seed-dashboard'));
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await login(page,'E2EOWNER');
    expect((await (await verify(page,'/admin',{send:'1',security_code:'BrowserFixtureOnly'})).text()).trim()).toBe('welcome');
    await page.goto('/admin');
    const value=async key=>Number(await page.locator(`[data-metric="${key}"]`).textContent());
    expect(await value('signups-month')).toBe(expected.signups);
    expect(await value('income-this')).toBe(12);expect(await value('votes-this')).toBe(10);
    expect(await value('unique-month')).toBe(expected.unique);
    expect(await value('visits-today')).toBe(3);expect(await value('visits-month')).toBe(expected.visits);
    await expect(page.locator('#visitor-count-note')).toContainText('once per day');
    await expect(page.locator('.apexcharts-canvas')).toHaveCount(2);
    const yearlySeries = () => page.evaluate(() => [thisYearMonthlyData, lastYearMonthData]);
    const before = await yearlySeries();
    expect(before.map(series => series.length)).toEqual([12,12]);
    fixture('expire-current-dashboard-graph'); await page.reload();
    expect(await yearlySeries()).toEqual(before);
    fixture('change-dashboard-data');await page.reload();
    expect(await value('signups-month')).toBe(expected.signups);expect(await value('income-this')).toBe(12);
    fixture('expire-dashboard-cache');await page.reload();
    expect(await value('signups-month')).toBe(expected.signups+10);expect(await value('income-this')).toBe(17);
    expect(errors).toEqual([]);
});
