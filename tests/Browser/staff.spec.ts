import { expect, test } from './fixtures';

test('staff directory create edit eligibility and activity stay usable on desktop and mobile', async ({ page }) => {
    const email = process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Use isolated synthetic staff fixtures.');
    test.setTimeout(180000);
    const suffix = test.info().project.name;
    const number = `000-${suffix}-${Date.now()}`;
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/, { timeout: 90000 });
    await page.goto('/admin/staff');
    await expect(page.getByRole('heading', { name: 'Staff Management', exact: true })).toBeVisible();
    await page.locator('main').getByRole('link', { name: 'Add New Staff', exact: true }).click();
    await page.getByLabel('Staff ID / Staff Number', { exact: true }).fill(number);
    await page.getByLabel('First Name', { exact: true }).fill('Nadia');
    await page.getByLabel('Last Name', { exact: true }).fill(suffix);
    await page.getByLabel('Email Address', { exact: true }).fill(`staff-${suffix}@example.test`);
    await expect(page.getByLabel('Employment Position', { exact: true })).toHaveCount(0);
    await page.getByLabel('Create system login account', { exact: true }).uncheck();
    await page.getByLabel('Work / mobile phone (optional)', { exact: true }).fill('+60123456789');
    await page.getByRole('button', { name: 'Save staff', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Nadia ${suffix}`, exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText('No login account is provisioned');
    await expect(page.getByRole('button', { name: 'Invite account', exact: true })).toBeVisible();
    await expect(page.getByLabel('Account access bundle', { exact: true })).toHaveValue('');
    await page.getByRole('link', { name: 'Edit staff', exact: true }).click();
    await page.getByLabel('First Name', { exact: true }).fill('Updated Nadia');
    await page.getByLabel('Staff Status', { exact: true }).selectOption('0');
    await page.getByLabel('Create system login account', { exact: true }).uncheck();
    await page.getByLabel('Availability change reason', { exact: true }).fill('Synthetic staff availability review');
    await page.getByRole('button', { name: 'Save staff', exact: true }).click();
    await expect(page.getByRole('heading', { name: `Updated Nadia ${suffix}`, exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Activity', exact: true }).click();
    await expect(page.getByText('Synthetic staff availability review', { exact: false })).toBeVisible();
    if (suffix === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 740 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: test.info().outputPath('staff-profile.png'), fullPage: true });
    await page.getByRole('link', { name: 'Back to staff', exact: true }).click();
    await page.getByRole('button', { name: /^Filters/ }).click();
    await page.getByLabel('Personnel', { exact: true }).selectOption('all');
    await page.getByLabel('Staff Status', { exact: true }).selectOption('inactive');
    await page.getByLabel('Search staff', { exact: true }).fill(number);
    await expect(page.getByRole('row').filter({ hasText: number })).toHaveCount(1);
    await expect(page.getByText(`Updated Nadia ${suffix}`, { exact: true }).filter({ visible: true }).first()).toBeVisible();
    await page.screenshot({ path: test.info().outputPath('staff-directory.png'), fullPage: true });
});
