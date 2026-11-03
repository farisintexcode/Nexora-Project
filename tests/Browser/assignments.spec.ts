import { expect, test } from './fixtures';

test('assignment date filter picks one day by double-click or a range by two dates', async ({ page }) => {
    const email = process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Use isolated synthetic assignment fixtures.');
    test.setTimeout(90000);
    const now = new Date();
    const dayLabel = (day: number) => new Date(now.getFullYear(), now.getMonth(), day).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
    const display = (day: number) => `${String(day).padStart(2, '0')}/${String(now.getMonth() + 1).padStart(2, '0')}/${now.getFullYear()}`;
    const iso = (day: number) => `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/, { timeout: 90000 });
    await page.goto('/admin/assignments');
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    const field = page.locator('#filter-date');
    const calendar = page.getByRole('dialog', { name: 'Choose Assignment Date', exact: true });
    await expect(page.getByLabel('Assignment date from', { exact: true })).toHaveCount(0);
    await field.click();
    await calendar.getByRole('button', { name: dayLabel(12), exact: true }).dblclick();
    await expect(calendar).toBeHidden();
    await expect(field).toContainText(display(12));
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`from=${iso(12)}&until=${iso(12)}`));
    await field.click();
    await calendar.getByRole('button', { name: dayLabel(14), exact: true }).click();
    await expect(calendar).toContainText('Now choose the end date');
    await calendar.getByRole('button', { name: dayLabel(10), exact: true }).click();
    await expect(calendar).toBeHidden();
    await expect(page.locator(`button[aria-label="${dayLabel(12)}"]`)).toHaveAttribute('data-state', 'range');
    await expect(field).toContainText(`${display(10)} – ${display(14)}`);
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`from=${iso(10)}&until=${iso(14)}`));
    await field.click();
    await calendar.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(field).toContainText('Select a date or range');
});

test('assignment metadata editing and confirmed archival preserve usable desktop and mobile flows', async ({ page }) => {
    const email = process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Use isolated synthetic assignment fixtures.');
    test.setTimeout(90000);
    const name = `Recovery ${test.info().project.name}`;
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/, { timeout: 90000 });
    await page.goto('/admin/assignments');
    await page.getByRole('link', { name: 'New assignment', exact: true }).click();
    await page.getByLabel('Assignment type', { exact: true }).selectOption('domestic_dr');
    await page.getByLabel('Assignment name', { exact: true }).fill(name);
    await page.getByLabel('Assignment due date', { exact: true }).fill(new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10));
    await page.getByLabel('DCA code', { exact: true }).fill('0001');
    await page.getByLabel('State', { exact: true }).selectOption('selangor');
    if (test.info().project.name === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 850 });
            await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
    }
    await page.getByRole('button', { name: 'Save assignment', exact: true }).click();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    await page.getByLabel('Assignment name', { exact: true }).fill(`Updated ${name}`);
    await page.getByRole('button', { name: 'Update assignment', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Updated ${name}`, exact: true })).toBeVisible();
    await page.getByLabel('Status change reason', { exact: true }).fill('Synthetic work package finished');
    await page.getByRole('button', { name: 'Archive assignment', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Confirm assignment status change', exact: true });
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Update assignment', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Archive assignment', exact: true }).click();
    await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Archived assignment: metadata is read-only. History is retained.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Assignment name', { exact: true })).toBeDisabled();
    await expect(page.getByText('Reopening requires the separately authorized assignments.reopen permission.', { exact: true })).toBeVisible();
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: test.info().outputPath('archived-assignment.png'), fullPage: true });
    await page.goto('/admin/assignments?status=archived');
    await page.getByLabel('Search assignments', { exact: true }).fill(`Updated ${name}`);
    await expect(page.getByText('Showing 1 to 1 of 1 entries', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: `Updated ${name}`, exact: true }).filter({ visible: true })).toBeVisible();
    await page.screenshot({ path: test.info().outputPath('assignment-register.png'), fullPage: true });
});
