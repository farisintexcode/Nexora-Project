import { expect, test } from './fixtures';

test('SES operators review safe inbox states and request rechecks without activating sending', async ({ page }) => {
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!password, 'Requires isolated synthetic inbox fixtures.');
    const surface = test.info().project.name;
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/communications');
    await page.getByRole('link', { name: 'SES receipt inbox', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'SES receipt inbox', exact: true })).toBeVisible();
    await expect(page.getByText(/The SES listener is disabled/)).toBeVisible();
    const row = page.locator('article').filter({ has: page.getByRole('heading', { name: 'Synthetic SES receipts ' + surface, exact: true }) });
    await row.getByRole('button', { name: 'Recheck correlation', exact: true }).click();
    await page.getByRole('dialog', { name: 'Recheck this receipt?', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Verified receipt queued for safe rechecking.' })).toBeVisible();
    await page.getByLabel('Receipt processing state', { exact: true }).selectOption('processed');
    await expect(page.getByRole('heading', { name: 'No verified receipts in this view', exact: true })).toBeVisible();
    await page.getByLabel('Receipt processing state', { exact: true }).selectOption('needs_resolution');
    await expect(row).toBeVisible();
    if (surface === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        }
    }
    await page.screenshot({ path: '.local-tools/ses-receipts-' + surface + '.png', fullPage: true });
    expect(errors).toEqual([]);
});
