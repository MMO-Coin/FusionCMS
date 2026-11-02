const {test, expect, login, openPage, fixture, verify} = require('./helpers.cjs');

test('settings keep drafts after failures, reject invalid values and prevent duplicate writes', async ({page}) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page); await page.goto('/ucp/settings');
    const profile = page.locator('#settings_info'), save = profile.locator('[type="submit"]');
    await page.locator('#nickname_field').fill('TestPlayer');
    await page.locator('#location_field').fill('Lahore');
    let release, received, calls = 0;
    const waiting = new Promise(resolve => { received = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/ucp/settings/submitInfo', async route => { calls++; received(); await gate; await route.continue(); });
    const saved = page.waitForResponse(response => response.url().endsWith('/ucp/settings/submitInfo'));
    await save.click(); await waiting;
    await expect(profile).toHaveAttribute('aria-busy', 'true'); await expect(save).toBeDisabled();
    await expect(page.locator('#nickname_field')).toBeDisabled();
    await page.evaluate(() => { Settings.submitInfo(); Settings.submitInfo(); });
    expect(calls).toBe(1); release();
    expect((await (await saved).json()).status).toBe('success');
    await expect(profile).toHaveAttribute('aria-busy', 'false'); await expect(save).toBeEnabled();
    await expect(page.locator('#settings_info_ajax')).toContainText('Changes have been saved');
    await page.unroute('**/ucp/settings/submitInfo'); await page.reload();
    await expect(page.locator('#nickname_field')).toHaveValue('TestPlayer');
    await expect(page.locator('#location_field')).toHaveValue('Lahore');

    await page.route('**/ucp/settings/submitInfo', route => route.fulfill({status:503, body:'Unavailable'}));
    await page.locator('#nickname_field').fill('DraftPlayer'); await page.locator('#location_field').fill('Draft & location');
    await save.click(); await expect(page.locator('#settings_info_ajax')).toContainText('Your entries are still here');
    await expect(page.locator('#nickname_field')).toHaveValue('DraftPlayer'); await expect(save).toBeEnabled();
    await page.unroute('**/ucp/settings/submitInfo');
    await save.click(); await expect(page.locator('#settings_info_ajax')).toContainText('Changes have been saved');
    await page.locator('#nickname_field').fill('E2EOTHER'); await save.click();
    await expect(page.locator('#nickname_field')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#nickname_field_error')).toContainText('already taken');
    await expect(page.locator('#nickname_field')).toBeFocused();
    const invalidLanguage = await verify(page, '/ucp/settings/submitInfo', {nickname:'AnotherName', location:'Changed', language:'../english'});
    expect((await invalidLanguage.json()).errors).toHaveProperty('language_field');
    const invalidLocation = await verify(page, '/ucp/settings/submitInfo', {nickname:'AnotherName', location:'x'.repeat(33), language:'english'});
    expect((await invalidLocation.json()).errors).toHaveProperty('location_field');
    await page.reload(); await expect(page.locator('#nickname_field')).toHaveValue('DraftPlayer');
    await expect(page.locator('#location_field')).toHaveValue('Draft & location');

    let passwordWrites = 0;
    page.on('request', request => { if (request.url().endsWith('/ucp/settings/submit')) passwordWrites++; });
    await page.locator('#old_password').fill('WrongPassword123');
    await page.locator('#new_password').fill('Different123'); await page.locator('#new_password_confirm').fill('Different456');
    await page.locator('#settings [type="submit"]').click();
    await expect(page.locator('#new_password_confirm')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#new_password_confirm')).toBeFocused(); expect(passwordWrites).toBe(0);
    await page.locator('#new_password_confirm').fill('Different123');
    await page.locator('#settings [type="submit"]').click();
    await expect(page.locator('#old_password')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#old_password_error')).toContainText('Incorrect password');
    await expect(page.locator('#old_password')).toHaveValue('WrongPassword123');
    await expect(page.locator('#old_password')).toBeFocused(); expect(passwordWrites).toBe(1);
    await page.locator('#new_password').fill('short'); await page.locator('#new_password_confirm').fill('short');
    await page.locator('#settings [type="submit"]').click();
    await expect(page.locator('#new_password')).toBeFocused(); expect(passwordWrites).toBe(1);
    expect(errors).toEqual([]);
});

test('shared modals trap focus, cancel with Escape and run callbacks once in both themes', async ({page}) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    for (const theme of ['default', 'heaven']) {
        fixture('public-theme', theme, 'minified'); await openPage(page, '/login');
        await page.evaluate(() => {
            window.confirmed = 0; window.cancelled = 0; window.keyProbe = 0;
            $(document).on('keypress.modalProbe', () => { window.keyProbe++; });
            const trigger = document.createElement('button'); trigger.id = 'popup-trigger'; trigger.textContent = 'Open confirmation';
            trigger.onclick = () => UI.confirm('<label for="popup-note">Note</label><input id="popup-note">', 'Proceed', () => { window.confirmed++; UI.alert('Finished'); }, () => { window.cancelled++; });
            document.body.appendChild(trigger);
        });
        const trigger = page.locator('#popup-trigger'), confirm = page.locator('#confirm'), note = page.locator('#popup-note');
        await trigger.click(); await expect(confirm).toHaveAttribute('role', 'dialog'); await expect(confirm).toHaveAttribute('aria-modal', 'true');
        await expect(note).toBeFocused(); await note.press('Enter'); expect(await page.evaluate(() => window.confirmed)).toBe(0);
        await page.keyboard.press('Tab'); await expect(page.locator('#confirm_button')).toBeFocused();
        await page.keyboard.press('Tab'); await expect(page.locator('#confirm_hide')).toBeFocused();
        await page.keyboard.press('Tab'); await expect(note).toBeFocused();
        await page.keyboard.press('Shift+Tab'); await expect(page.locator('#confirm_hide')).toBeFocused();
        await page.keyboard.press('Escape'); await expect(confirm).toBeHidden(); await expect(trigger).toBeFocused();
        expect(await page.evaluate(() => window.cancelled)).toBe(1);
        await trigger.click(); await page.locator('#confirm_hide').click(); await page.evaluate(() => { UI.hidePopup(); UI.hidePopup(); });
        expect(await page.evaluate(() => window.cancelled)).toBe(2);
        await trigger.click(); await page.locator('#confirm_button').click();
        await expect(page.getByRole('dialog', {name:'Finished', exact:true})).toBeVisible();
        expect(await page.evaluate(() => window.confirmed)).toBe(1); expect(await page.evaluate(() => window.cancelled)).toBe(2);
        await page.locator('#alert_button').click(); await expect(trigger).toBeFocused();
        await page.locator('#floatingUser').focus(); await page.keyboard.press('x');
        expect(await page.evaluate(() => window.keyProbe)).toBeGreaterThan(0);
        await page.evaluate(() => {
            window.timerChecked = false;
            UI.alert('First', 40); UI.confirm('Keep open', 'Proceed', () => { window.confirmed++; }, () => { window.cancelled++; });
            setTimeout(() => { window.timerChecked = true; }, 100);
        });
        await expect.poll(() => page.evaluate(() => window.timerChecked)).toBe(true);
        await expect(confirm).toBeVisible(); await page.keyboard.press('Escape');
        expect(await page.evaluate(() => window.cancelled)).toBe(3);
        expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
    }
    expect(errors).toEqual([]);
});
