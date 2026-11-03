import { expect, test } from './fixtures';

test('authorized users review and queue private letters without substituting financial data', async ({ page }) => {
    test.setTimeout(240000);
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!password, 'Requires isolated synthetic letter fixtures.');
    const surface = test.info().project.name;
    const slot = surface === 'desktop' ? 1 : 2;
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/communications');
    await page.getByRole('link', { name: 'Private letters', exact: true }).click();
    await page.getByLabel('Exact customer reference', { exact: true }).fill('RIDER-' + surface.toUpperCase());
    await page.getByRole('button', { name: 'Find customer', exact: true }).click();
    await page.getByLabel('Approved template', { exact: true }).selectOption({ label: slot === 1 ? 'Friendly Payment Reminder · v1.0' : 'Outstanding Balance Follow-Up · v1.0' });
    await page.getByRole('button', { name: 'Review letter preview', exact: true }).click();
    await expect(page.getByText(/Required source data unavailable:/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm and generate PDF', exact: true })).toHaveCount(0);
    await page.goto('/admin/communications');
    await page.getByRole('link', { name: 'View approved option ' + slot, exact: true }).click();
    await page.getByRole('button', { name: 'Create / open next draft', exact: true }).click();
    await page.getByLabel('Template name', { exact: true }).fill('Synthetic letter ' + surface);
    await page.getByLabel('Email subject', { exact: true }).fill('Notice for {{customer_name}}');
    await page.getByLabel('Message / letter body', { exact: true }).fill('Dear {{customer_name}}, please contact {{company_name}}.');
    for (const label of ['Selected account number', 'Outstanding amount from approved financial source', 'Payment due date from approved financial source']) {
        await page.getByLabel(label, { exact: true }).uncheck();
    }
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Draft saved.' })).toBeVisible();
    await page.getByRole('button', { name: 'Submit for approval', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Approve saved version', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Approve saved version', exact: true }).click();
    await page.getByRole('link', { name: 'Prepare private letter', exact: true }).click();
    await page.getByLabel('Exact customer reference', { exact: true }).fill('RIDER-' + surface.toUpperCase());
    await page.getByRole('button', { name: 'Find customer', exact: true }).click();
    await page.getByRole('button', { name: 'Review letter preview', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Saved letter preview', exact: true })).toBeVisible();
    await expect(page.getByText('Dear Synthetic Field Customer ' + surface + ', please contact ' + (process.env.DCMS_COMPANY_NAME ?? 'Nexora') + '.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Confirm and generate PDF', exact: true }).click();
    await page.getByRole('dialog', { name: 'Generate private PDF?', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Letter queued for private PDF generation.' })).toBeVisible();
    await expect(page.getByText('PDF generation is queued.', { exact: false })).toBeVisible();
    if (surface === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        }
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: '.local-tools/letters-' + surface + '.png', fullPage: true });
    expect(errors).toEqual([]);
});

