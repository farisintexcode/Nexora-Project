import { expect, test } from './fixtures';

for (const role of ['System Admin', 'Administrative', 'Rider']) {
    test(`HR registers an invited ${role} and retains separate staff/account states`, async ({ page }) => {
        const password = process.env.DCMS_E2E_PASSWORD;
        test.skip(!password, 'Requires disposable BrowserTestSeeder fixtures and a runtime secret.');
        const suffix = `${test.info().project.name}-${role.replaceAll(' ', '-').toLowerCase()}-${Date.now()}`;
        await page.goto('/login');
        await page.getByLabel('Email', { exact: true }).fill('hr-admin@example.test');
        await page.getByLabel('Password', { exact: true }).fill(password!);
        await page.getByRole('button', { name: 'Sign in', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/system$/);
        await page.goto('/admin/staff/create');
        await expect(page.getByRole('heading', { name: 'Add New Staff', exact: true })).toBeVisible();
        await expect(page.getByLabel('System Role', { exact: true }).locator('option')).toHaveText(['System Admin', 'Administrative', 'Rider']);
        await expect(page.getByText('Appointment date', { exact: false })).toHaveCount(0);
        await expect(page.getByLabel('Create system login account')).toBeChecked();
        await page.getByLabel('Staff ID / Staff Number', { exact: true }).fill(suffix);
        await page.getByLabel('Email Address', { exact: true }).fill(`${suffix}@example.test`);
        await page.getByLabel('First Name', { exact: true }).fill('Browser');
        await page.getByLabel('Last Name', { exact: true }).fill(suffix);
        await page.getByLabel('System Role', { exact: true }).selectOption(role);
        await expect(page.getByLabel('Employment Position', { exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: 'Save staff', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/staff\/[0-9a-f-]+$/);
        await expect(page.getByText('Invited', { exact: true })).toBeVisible();
        await expect(page.getByText('Created At', { exact: true })).toBeVisible();
        await page.goto('/admin/staff');
        await page.getByLabel('Personnel', { exact: true }).selectOption('all');
        await page.getByLabel('System Role', { exact: true }).selectOption(role);
        await page.getByLabel('Account Status', { exact: true }).selectOption('invited');
        await page.getByLabel('Search staff', { exact: true }).fill(suffix);
        const row = page.getByRole('row').filter({ hasText: suffix });
        await expect(row).toHaveCount(1);
        await expect(row).toContainText('Invited');
        await row.getByRole('button', { name: 'Deactivate', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Deactivation reason', { exact: true }).fill('Synthetic browser pause');
        await dialog.getByRole('button', { name: 'Deactivate', exact: true }).click();
        await expect(row).toHaveCount(0, { timeout: 30000 });
        await page.getByLabel('Staff Status', { exact: true }).selectOption('inactive');
        await expect(row).toContainText('Inactive', { timeout: 30000 });
        await expect(row).toContainText('Invited');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    });
}
