const {test, expect, login, fixture, verify} = require('./helpers.cjs');

class DashboardDayChanged extends Error {}

for (const initialDate of ['today', 'yesterday']) {
    test(`dashboard totals use calendar aggregates and refresh their short cache (${initialDate} seed)`, async ({page}) => {
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        await login(page,'E2EOWNER');
        expect((await (await verify(page,'/admin',{send:'1',security_code:'BrowserFixtureOnly'})).text()).trim()).toBe('welcome');
        const firstDate = new Date(fixture('dashboard-calendar-day').trim() + 'T00:00:00Z');
        if (initialDate === 'yesterday') firstDate.setUTCDate(firstDate.getUTCDate()-1);

        let rebaselines=0;
        for (;;) {
            const expected=JSON.parse(rebaselines === 0 ? fixture('seed-dashboard',firstDate.toISOString().slice(0,10)) : fixture('seed-dashboard'));
            const render = async navigate => {
                await navigate();
                const snapshot = await page.evaluate(() => ({
                    metrics:Object.fromEntries([...document.querySelectorAll('[data-metric]')].map(node=>[node.dataset.metric,Number(node.textContent)])),
                    yearly:[thisYearMonthlyData,lastYearMonthData],
                }));
                // A render may cross midnight after its fixture was seeded. Rebaseline only for that calendar change.
                const currentDate=fixture('dashboard-calendar-day').trim();
                if (currentDate !== expected.date) throw new DashboardDayChanged(`Dashboard fixture date changed from ${expected.date} to ${currentDate}`);
                return snapshot;
            };
            try {
                let snapshot=await render(()=>page.goto('/admin'));
                expect(snapshot.metrics['signups-month']).toBe(expected.signups);
                expect(snapshot.metrics['income-this']).toBe(12);expect(snapshot.metrics['votes-this']).toBe(10);
                expect(snapshot.metrics['unique-month']).toBe(expected.unique);
                expect(snapshot.metrics['visits-today']).toBe(3);expect(snapshot.metrics['visits-month']).toBe(expected.visits);
                await expect(page.locator('#visitor-count-note')).toContainText('once per day');
                await expect(page.locator('.apexcharts-canvas')).toHaveCount(2);
                const before=snapshot.yearly;
                expect(before.map(series=>series.length)).toEqual([12,12]);
                fixture('expire-current-dashboard-graph',expected.date);
                snapshot=await render(()=>page.reload());
                expect(snapshot.yearly).toEqual(before);
                fixture('change-dashboard-data',expected.date);
                snapshot=await render(()=>page.reload());
                expect(snapshot.metrics['signups-month']).toBe(expected.signups);expect(snapshot.metrics['income-this']).toBe(12);
                fixture('expire-dashboard-cache');
                snapshot=await render(()=>page.reload());
                expect(snapshot.metrics['signups-month']).toBe(expected.signups+10);expect(snapshot.metrics['income-this']).toBe(17);
                break;
            } catch (error) {
                if (!(error instanceof DashboardDayChanged) || rebaselines === 2) throw error;
                rebaselines++;
            }
        }
        if (initialDate === 'yesterday') expect(rebaselines).toBeGreaterThan(0);
        expect(errors).toEqual([]);
    });
}
