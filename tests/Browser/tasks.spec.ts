import { expect, test, type Page } from './fixtures';

async function pickDateTime(page: Page, field: string, label: string, month: string, day: string, time: string): Promise<void> {
    await page.locator(field).click();
    const calendar = page.getByRole('dialog', { name: `Choose ${label}`, exact: true });
    for (let attempts = 0; attempts < 60 && !(await calendar.getByText(month, { exact: true }).isVisible()); attempts++) {
        await calendar.getByRole('button', { name: new Date(month) < new Date() ? 'Previous month' : 'Next month', exact: true }).click();
    }
    await calendar.getByRole('button', { name: day, exact: true }).click();
    await calendar.getByLabel('Time', { exact: true }).fill(time);
    await calendar.getByRole('button', { name: 'Done', exact: true }).click();
}

test('manual task editing and exact allocation preview are usable on desktop and mobile', async ({ page }) => {
    test.setTimeout(90000);
    const email = process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Use isolated synthetic task fixtures.');
    const account = `000TASK-${test.info().project.name}-${test.info().retry}`;
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/, { timeout: 90000 });
    await page.goto('/admin/tasks/create');
    const assignment = page.getByLabel('Assignment *', { exact: true });
    await assignment.selectOption((await assignment.locator('option').filter({ hasText: 'Synthetic Task Batch' }).first().getAttribute('value'))!);
    await page.getByLabel('Task ID *', { exact: true }).fill(`FG${test.info().project.name === 'desktop' ? 1 : 2}${test.info().retry}`);
    await page.getByLabel('Customer reference *', { exact: true }).fill('TASK-UI');
    await page.getByLabel('Account number *', { exact: true }).fill(account);
    await page.getByLabel('Service address', { exact: true }).fill('10 Synthetic Task Road');
    await page.getByLabel('Postcode', { exact: true }).fill('00001');
    await pickDateTime(page, '#planned-visit', 'Planned visit (MYT)', 'October 2026', '20 October 2026', '09:30');
    await expect(page.locator('#planned-visit')).toContainText('20/10/2026, 09:30');
    await pickDateTime(page, '#due-at', 'Due date & time (MYT)', 'October 2026', '21 October 2026', '17:30');
    await page.getByLabel('Special instructions', { exact: true }).fill('Synthetic task instructions');
    await page.getByRole('button', { name: 'Save task', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Task details', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Edit task', exact: true }).click();
    await expect(page.getByLabel('Account number *', { exact: true })).toBeDisabled();
    await page.getByLabel('Priority *', { exact: true }).selectOption('urgent');
    await page.getByRole('button', { name: 'Update task', exact: true }).click();
    await expect(page.getByText('Urgent', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Assign Rider', exact: true }).click();
    const receivingRider = await page.getByLabel('Receiving Rider *', { exact: true }).locator('option').filter({ hasText: 'Field Rider' }).getAttribute('value');
    await page.getByLabel('Receiving Rider *', { exact: true }).selectOption(receivingRider!);
    await page.getByLabel('Operation reason', { exact: true }).fill('Synthetic reviewed field allocation');
    await page.getByRole('button', { name: 'Assign to Rider', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: '1 task assigned to' })).toBeVisible();
    await expect(page).toHaveURL(/\/admin\/tasks$/);
    await page.screenshot({ path: test.info().outputPath('task-assigned.png'), fullPage: true });
    await page.goto('/admin/tasks');
    await page.getByLabel('Task ID', { exact: true }).fill(account);
    await expect(page.getByRole('link', { name: 'View task', exact: true }).filter({ visible: true })).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Filters' }).click();
    expect(await page.locator('#task-date').evaluate(element => {
        const input = element.getBoundingClientRect();
        const card = element.closest('.surface')!.getBoundingClientRect();
        return input.left >= card.left && input.right <= card.right;
    })).toBe(true);
    await page.screenshot({ path: test.info().outputPath('task-monitoring.png'), fullPage: true });
});
