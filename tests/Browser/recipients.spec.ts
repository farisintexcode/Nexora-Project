import { expect, test } from './fixtures';

test('service authority review and frozen recipient exclusions remain explicit and unsent', async ({ page }) => {
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!password, 'Requires disposable synthetic recipient fixtures.');
    const surface = test.info().project.name;
    const reference = 'RIDER-' + surface.toUpperCase();
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/communications/recipients');
    await page.getByLabel('Find email contacts', { exact: true }).fill(reference);
    await page.getByRole('button', { name: 'Filter contacts', exact: true }).click();
    await expect(page.getByText('Select email for ' + reference, { exact: true })).toBeVisible();
    await page.locator('article').filter({ hasText: 'Select email for ' + reference }).getByRole('link', { name: 'Review email authority', exact: true }).click();
    await page.getByLabel('Requested preference status', { exact: true }).selectOption('allowed');
    await page.getByLabel('Service communication basis', { exact: true }).selectOption('EXISTING_ACCOUNT_RELATIONSHIP');
    await page.getByLabel('Email contact scope', { exact: true }).selectOption({ label: 'service-' + surface + '@example.test' });
    await page.getByLabel('Account scope', { exact: true }).selectOption({ label: 'M06-' + surface.toUpperCase() });
    await page.getByLabel('Evidence source', { exact: true }).fill('Synthetic reviewed account');
    await page.getByLabel('Source / agreement reference', { exact: true }).fill('BROWSER-AUTHORITY');
    await page.getByLabel('Email source', { exact: true }).fill('Synthetic customer record');
    await page.getByLabel('Reason for this request / restriction', { exact: true }).fill('Synthetic legitimate service basis');
    await page.getByRole('button', { name: 'Record email authority', exact: true }).click();
    await expect(page.getByText('Pending', { exact: true })).toBeVisible();
    await page.getByLabel('Review reason', { exact: true }).fill('Synthetic evidence reviewed');
    await page.getByRole('button', { name: 'Approve eligibility request', exact: true }).click();
    await page.getByRole('dialog', { name: 'Approve this request?', exact: true }).getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Approved · Current', { exact: true })).toBeVisible();
    if (surface === 'rider-mobile') {
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        }
    }
    await page.screenshot({ path: '.local-tools/m14-' + surface + '-authority.png', fullPage: true });
    await page.getByRole('link', { name: 'Recipient selection', exact: true }).click();
    await page.getByLabel('Approved template version', { exact: true }).selectOption({ label: 'Friendly Payment Reminder · v1.0' });
    await page.getByLabel('Find email contacts', { exact: true }).fill(reference);
    await page.getByRole('button', { name: 'Filter contacts', exact: true }).click();
    await page.locator('article').filter({ hasText: 'Select email for ' + reference }).getByRole('checkbox').check();
    await page.getByLabel('Account for ' + reference, { exact: true }).selectOption({ label: 'M06-' + surface.toUpperCase() });
    await page.getByRole('button', { name: 'Save recipient preview', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Saved recipient preview', exact: true })).toBeVisible();
    await expect(page.getByText('Exclusion checks: required_source_data', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Email queue & capacity', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm exact eligible recipients', exact: true })).toBeDisabled();
    await expect(page.getByText(/1,000 recipients per rolling 24 hours/)).toBeVisible();
    await page.getByRole('button', { name: 'Recheck current eligibility', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Sources still match this preview.' })).toBeVisible();
    await page.getByText('View frozen recipient content', { exact: true }).click();
    await expect(page.getByText('Required data was unavailable. No rendered message was created for this recipient.', { exact: true })).toBeVisible();
    await page.screenshot({ path: '.local-tools/m14-' + surface + '-recipients.png', fullPage: true });
    await page.getByRole('button', { name: 'Cancel unsent preview', exact: true }).click();
    await page.getByRole('dialog', { name: 'Cancel pending work?', exact: true }).getByRole('button', { name: 'Cancel work', exact: true }).click();
    await expect(page.getByText('Cancelled', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});


