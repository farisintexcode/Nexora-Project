import { chooseDateRange, expect, test, type Page } from './fixtures';

async function signIn(page: Page, email: string, rider = false) {
    await page.goto(rider ? '/rider/login' : '/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(process.env.DCMS_E2E_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(rider ? /\/rider$/ : /\/admin$/);
}

test('compact Admin filters expand clear and fit mobile and dark mode', async ({ page }) => {
    test.skip(!process.env.DCMS_E2E_PASSWORD, 'Requires isolated synthetic browser database.');
    await signIn(page, 'hr-admin@example.test');
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const [path, advanced] of [['/admin/attendance', 'Rider name or staff number'], ['/admin/leave', 'Rider Name or Employee ID'], ['/admin/staff', 'Personnel']]) {
        await page.goto(path);
        const toggle = page.getByRole('button', { name: /^Filters/ });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(page.getByLabel(advanced, { exact: true })).toBeVisible();
        if (path === '/admin/staff') {
            await expect(page.getByRole('button', { name: 'Apply filters', exact: true })).toHaveCount(0);
        } else {
            await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
        }
        await expect(page.getByRole('button', { name: 'Clear filters', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Clear filters', exact: true }).click();
        for (const width of [320, 768, 1280]) {
            await page.setViewportSize({ width, height: 820 });
            await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBe(width);
        }
        await page.evaluate(() => window['dcmsSetTheme']('dark'));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.evaluate(() => scrollTo(0, 0));
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: test.info().outputPath(path.split('/').pop() + '-filters.png'), fullPage: true });
    }
    expect(errors).toEqual([]);
});

test('tracked leave uses recurring schedule and preserves balance after independent approval', async ({ page, browser }) => {
    test.setTimeout(240000);
    test.skip(!process.env.DCMS_E2E_PASSWORD, 'Requires isolated synthetic browser database.');
    page.on('dialog', dialog => dialog.accept());
    await signIn(page, 'admin@example.test');
    const name = `Tracked ${test.info().project.name} ${Date.now()}`;
    await page.goto('/admin/leave/types');
    await page.getByLabel('Leave Type Name', { exact: true }).fill(name);
    await page.getByLabel('Maximum Calendar Span (days)', { exact: true }).fill('7');
    await page.getByLabel('Deduct from tracked annual balance', { exact: true }).check();
    await page.getByLabel('Annual Entitlement (days)', { exact: true }).fill('3');
    await page.getByRole('button', { name: 'Save Leave Type', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Leave type saved' })).toBeVisible();
    await page.getByRole('button', { name: 'OK', exact: true }).click();
    const future = new Date(Date.now() + (test.info().project.name === 'desktop' ? 14 : 21) * 86400000);
    while ([0, 6].includes(future.getUTCDay())) future.setUTCDate(future.getUTCDate() + 1);
    const date = future.toISOString().slice(0, 10);
    await page.goto('/admin/attendance/configuration');
    const riderOption = page.getByLabel('Rider to schedule', { exact: true }).locator('option').filter({ hasText: 'Field Rider' });
    await page.getByLabel('Rider to schedule', { exact: true }).selectOption(await riderOption.getAttribute('value') as string);
    const policyOption = page.getByLabel('Approved policy version', { exact: true }).locator('option').filter({ hasText: 'browser-attendance' }).first();
    await page.getByLabel('Approved policy version', { exact: true }).selectOption(await policyOption.getAttribute('value') as string);
    await chooseDateRange(page, 'assign-dates', 'Assign dates *', date);
    await page.getByRole('button', { name: 'Assign recurring work schedule', exact: true }).click();
    await page.getByRole('dialog', { name: 'Assign this recurring work schedule?', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Recurring work schedule assigned' })).toBeVisible();
    const context = await browser.newContext({ baseURL: process.env.PLAYWRIGHT_BASE_URL });
    const rider = await context.newPage();
    await signIn(rider, 'rider@example.test', true);
    await rider.goto('/rider/attendance/leave/apply');
    await rider.getByLabel('Leave Type', { exact: true }).selectOption({ label: name });
    await expect(rider.getByRole('region', { name: /^Leave balance/ })).toContainText('3');
    await rider.getByLabel('Start Date', { exact: true }).fill(date);
    await rider.getByLabel('End Date', { exact: true }).fill(date);
    await expect(rider.getByRole('status').filter({ hasText: 'Requested Days: 1 working day' })).toBeVisible();
    await expect(rider.getByText(/Projected Balance .*: 2/)).toBeVisible();
    await rider.getByLabel('Reason for Leave', { exact: true }).fill('Synthetic tracked leave');
    await rider.getByRole('button', { name: 'Review Application', exact: false }).click();
    await rider.getByRole('button', { name: 'Confirm and Submit', exact: true }).click();
    await expect(rider.getByRole('status').filter({ hasText: 'pending approval' })).toBeVisible();
    const requestUrl = rider.url();
    await page.goto('/admin/leave/' + requestUrl.split('/').pop());
    await page.getByLabel('Decision', { exact: true }).selectOption('approved');
    await page.getByLabel('Admin Remarks / Rejection Reason').fill('Independent synthetic approval');
    await page.getByRole('button', { name: 'Confirm Decision', exact: true }).click();
    await page.getByRole('dialog', { name: 'Save this leave decision?', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'decision has been saved' })).toBeVisible();
    await rider.reload();
    await expect(rider.getByText('Approved', { exact: true }).first()).toBeVisible();
    const available = rider.getByRole('region', { name: /^Leave balance/ }).locator('div').filter({ has: rider.getByText('Available Days', { exact: true }) });
    await expect(available).toContainText('2');
    await context.close();
});
