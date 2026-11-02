import { expect, test, type Page } from './fixtures';

async function login(page: Page, rider = false) {
    test.skip(!process.env.DCMS_E2E_PASSWORD, 'Requires isolated synthetic UI fixtures.');
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.goto(rider ? '/rider/login' : '/login');
    await page.getByLabel('Email', { exact: true }).fill(rider ? 'rider@example.test' : 'admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill(process.env.DCMS_E2E_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(rider ? /\/rider$/ : /\/admin$/);
}

async function fits(page: Page) {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('public forms use readable orange actions and preserve theme preference', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    for (const route of ['/login', '/rider/login', '/forgot-password']) {
        await page.goto(route);
        await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        const action = page.locator('.btn-primary').first();
        await expect(action).toHaveCSS('background-color', 'rgb(251, 133, 0)');
        await expect(action).toHaveCSS('color', 'rgb(2, 48, 71)');
        await fits(page);
    }
    await page.goto('/login');
    await page.screenshot({ path: test.info().outputPath('login-light.png'), fullPage: true });
    await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click();
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toHaveCSS('color', 'rgb(2, 48, 71)');
    await page.screenshot({ path: test.info().outputPath('login-dark.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('Admin screens share the theme without changing their navigation or forms', async ({ page }) => {
    test.setTimeout(300000);
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await expect(page.locator('#admin-navigation')).toHaveCSS('background-color', 'rgb(2, 48, 71)');
    const routes = ['/admin', '/admin/staff', '/admin/staff/create', '/admin/assignments', '/admin/assignments/create', '/admin/tasks', '/admin/tasks/create', '/admin/imports', '/admin/verification', '/admin/attendance', '/admin/attendance/configuration', '/admin/calls', '/admin/calls/follow-ups', '/admin/communications', '/admin/communications/letters', '/admin/communications/recipients', '/admin/communications/receipts', '/account'];
    for (const route of routes) {
        const response = await page.goto(route);
        expect(response?.status(), route).toBe(200);
        await expect(page.locator('#main-content')).toBeVisible();
        await fits(page);
        if (['/admin', '/admin/communications'].includes(route)) {
            await page.screenshot({ path: test.info().outputPath(route.replaceAll('/', '-') + '-light.png'), fullPage: true });
        }
    }
    await page.goto('/admin');
    await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click();
    await expect(page.locator('#admin-navigation')).toHaveCSS('background-color', 'rgb(2, 48, 71)');
    await page.screenshot({ path: test.info().outputPath('dashboard-dark.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('Rider field screens retain mobile actions and readable active navigation', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page, true);
    for (const route of ['/rider', '/rider/assignments', '/rider/tasks', '/rider/attendance', '/rider/notifications', '/account']) {
        const response = await page.goto(route);
        expect(response?.status(), route).toBe(200);
        await expect(page.locator('#main-content')).toBeVisible();
        await fits(page);
        await page.screenshot({ path: test.info().outputPath(route.replaceAll('/', '-') + '-light.png'), fullPage: true });
    }
    await page.goto('/rider');
    const home = page.getByRole('navigation', { name: 'Rider navigation', exact: true }).getByRole('link', { name: 'Home', exact: true });
    await expect(home.locator('span').first()).toHaveCSS('background-color', 'rgb(2, 48, 71)');
    await expect(home).toHaveCSS('color', 'rgb(2, 48, 71)');
    for (const width of [320, 430, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await fits(page);
    }
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click();
    await page.keyboard.press('Escape');
    await page.screenshot({ path: test.info().outputPath('rider-dark.png'), fullPage: true });
    expect(errors).toEqual([]);
});


