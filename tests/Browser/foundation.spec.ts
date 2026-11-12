import { expect, test } from './fixtures';

test('public entry is usable and remains within the viewport', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Admin sign in' })).toBeVisible();
    await expect(page.getByLabel('Email', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('link', { name: 'Rider sign in', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Rider sign in' })).toBeVisible();
    await page.getByRole('link', { name: 'Forgot password?' }).click();
    await expect(page.getByRole('heading', { name: 'Recover your account' })).toBeVisible();
});

test('protected workspaces require login', async ({ page }) => {
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/rider');
    await expect(page).toHaveURL(/\/rider\/login$/);
});

test('synthetic credentials open only the intended workspace and sign out', async ({ page }) => {
    const mobile = test.info().project.name === 'rider-mobile';
    const email = mobile ? process.env.DCMS_E2E_RIDER_EMAIL : process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Provision synthetic accounts in an isolated local/test database.');
    await page.goto(mobile ? '/rider/login' : '/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(mobile ? /\/rider$/ : /\/admin$/, { timeout: 90000 });
    await expect(page.getByRole('heading', { name: /Welcome,/ })).toBeVisible();
    if (mobile) {
        await expect(page.getByRole('navigation', { name: 'Rider navigation' })).toBeVisible();
        for (const width of [320, 430]) {
            await page.setViewportSize({ width, height: 740 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        }
    }
    await page.screenshot({ path: test.info().outputPath('workspace.png'), fullPage: true });
    await page.getByRole('link', mobile ? { name: 'Profile', exact: true } : { name: /, account settings$/ }).click();
    await expect(page.getByRole('heading', { name: 'Account settings' })).toBeVisible();
    if (mobile) await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page.getByRole('heading', { name: mobile ? 'Rider sign in' : 'Admin sign in' })).toBeVisible();
});
