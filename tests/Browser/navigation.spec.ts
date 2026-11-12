import { expect, test, Page } from './fixtures';

async function signIn(page: Page, rider = false) {
    const email = rider ? process.env.DCMS_E2E_RIDER_EMAIL : process.env.DCMS_E2E_ADMIN_EMAIL;
    const password = process.env.DCMS_E2E_PASSWORD;
    test.skip(!email || !password, 'Use isolated synthetic navigation fixtures.');
    await page.goto(rider ? '/rider/login' : '/login');
    await page.getByLabel('Email', { exact: true }).fill(email!);
    await page.getByLabel('Password', { exact: true }).fill(password!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(rider ? /\/rider$/ : /\/admin$/, { timeout: 90000 });
}

test('theme follows the system and remembers a manual choice on public pages', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
    await page.goto('/login');
    await expect(page).toHaveTitle('Admin sign in | Nexora');
    await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/images\/brand\/nexora-browser-32\.png(?:\?v=navy3)?$/);
    await expect(page.locator('.nexora-logo-full img').first()).toHaveAttribute('src', /\/images\/brand\/nexora-logo-(light|dark)\.svg$/);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.getByRole('button', { name: 'Switch to light mode', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByRole('link', { name: 'Rider sign in', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
    await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toHaveCSS('color', 'rgb(2, 48, 71)');
    await page.screenshot({ path: test.info().outputPath('dark-login.png'), fullPage: true });
});

test('Admin navigation supports search groups collapse tooltips and mobile keyboard access', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await signIn(page);
    const desktop = test.info().project.name === 'desktop';
    const sidebar = page.locator('#admin-navigation');
    await expect(sidebar.getByLabel('Administration — planned', { exact: true })).toHaveCount(0);
    if (!desktop) {
        await page.getByRole('button', { name: 'Menu', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Close menu', exact: true })).toBeFocused();
        await expect(page.locator('#main-content')).toHaveJSProperty('inert', true);
        await page.keyboard.press('Shift+Tab');
        await expect(sidebar.getByRole('link', { name: 'Nexora home', exact: true })).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Menu', exact: true })).toBeFocused();
        await page.getByRole('button', { name: 'Menu', exact: true }).click();
    }
    const fullPanel = await sidebar.boundingBox();
    expect(fullPanel!.x).toBe(0);
    expect(fullPanel!.y).toBe(0);
    expect(fullPanel!.height).toBe(page.viewportSize()!.height);
    const footer = await sidebar.locator('.sidebar-footer').boundingBox();
    expect(footer!.y + footer!.height).toBeCloseTo(page.viewportSize()!.height, 0);
    await expect(sidebar).toHaveCSS('border-radius', '0px');
    await sidebar.getByRole('button', { name: 'Assignment', exact: true }).click();
    await sidebar.getByRole('link', { name: 'Add Assignment', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/assignments\/create$/);
    if (!desktop) await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await expect(sidebar.getByRole('link', { name: 'Add Assignment', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(sidebar.getByRole('button', { name: 'Assignment', exact: true })).toHaveAttribute('aria-expanded', 'true');
    await sidebar.getByRole('searchbox', { name: 'Search navigation', exact: true }).fill('staff');
    await expect(sidebar.getByRole('link', { name: 'Staff Management', exact: true })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'Add Assignment', exact: true })).toBeHidden();
    await sidebar.getByRole('searchbox', { name: 'Search navigation', exact: true }).fill('not-a-module');
    await expect(sidebar.getByRole('status')).toContainText('No matching pages');
    await sidebar.getByRole('searchbox', { name: 'Search navigation', exact: true }).fill('');
    if (desktop) {
        await sidebar.getByRole('button', { name: 'Collapse sidebar', exact: true }).click();
        await expect(page.locator('body')).toHaveClass(/sidebar-compact/);
        await expect(sidebar.locator('.nexora-logo-icon')).toBeVisible();
        await expect(sidebar.locator('.nexora-logo-full')).toBeHidden();
        const railBox = await sidebar.boundingBox();
        const headerBox = await page.locator('header').boundingBox();
        expect(headerBox!.x).toBeGreaterThanOrEqual(railBox!.x + railBox!.width);
        await sidebar.getByRole('button', { name: 'Human Resources', exact: true }).focus();
        await expect(page.getByRole('tooltip')).toHaveText('Human Resources');
        await page.screenshot({ path: test.info().outputPath('compact-navigation.png'), fullPage: true });
        await page.reload();
        await expect(page.locator('body')).toHaveClass(/sidebar-compact/);
        await sidebar.getByRole('button', { name: 'Search navigation', exact: true }).click();
        await expect(sidebar.getByRole('searchbox', { name: 'Search navigation', exact: true })).toBeFocused();
        await expect(page.locator('body')).not.toHaveClass(/sidebar-compact/);
    } else {
        await page.keyboard.press('Escape');
    }
    await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click();
    await page.screenshot({ path: test.info().outputPath('dark-navigation.png'), fullPage: true });
    for (const width of [320, 430, 768, 1280]) {
        await page.setViewportSize({ width, height: 850 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), { message: `No overflow at ${width}px after responsive layout settles` }).toBe(true);
    }
    await expect(page.locator('header').getByRole('link', { name: /, account settings$/ })).toBeVisible();
    await expect(page.locator('header').getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('Rider keeps four bottom destinations and groups account actions under the avatar', async ({ page }) => {
    await signIn(page, true);
    const navigation = page.getByRole('navigation', { name: 'Rider navigation', exact: true });
    await expect(navigation.getByRole('link')).toHaveText(['Home','Assignment','My Task','Attendance']);
    const profile = page.locator('header').getByRole('button', { name: 'Profile', exact: true });
    await profile.focus();
    await page.keyboard.press('ArrowDown');
    const accountMenu = page.getByRole('menu', { name: 'Profile', exact: true });
    await expect(accountMenu.getByRole('menuitem')).toHaveText(['My Profile', 'Account Settings', 'Logout']);
    await expect(accountMenu.getByRole('menuitem', { name: 'My Profile', exact: true })).toBeFocused();
    await page.keyboard.press('End');
    await expect(accountMenu.getByRole('menuitem', { name: 'Logout', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(accountMenu).not.toBeVisible();
    await expect(profile).toBeFocused();
    await profile.click();
    await page.locator('main').click({ position: { x: 4, y: 4 } });
    await expect(accountMenu).not.toBeVisible();
    await profile.click();
    await accountMenu.getByRole('menuitem', { name: 'My Profile', exact: true }).click();
    await expect(page).toHaveURL(/\/rider\/profile$/);
    await expect(page.locator('#profile-information')).toBeInViewport();
    await expect(page.locator('header').getByRole('button',{name:'Sign out',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Menu',exact:true}).click();
    const sidebar=page.getByRole('dialog',{name:'Rider sidebar',exact:true});
    await expect(sidebar.locator('form[action$="/logout"]')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Close menu',exact:true})).toBeFocused();
    await page.getByRole('button',{name:'Switch to dark mode',exact:true}).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.screenshot({path:test.info().outputPath('dark-rider-drawer.png'),fullPage:true});
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button',{name:'Menu',exact:true})).toBeFocused();
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await profile.click();
    await expect(accountMenu).toBeVisible();
    await expect(page.locator('#rider-account-menu')).toHaveCSS('opacity', '1');
    await page.screenshot({path:test.info().outputPath('dark-rider.png'),fullPage:true});
    await accountMenu.getByRole('menuitem', { name: 'Account Settings', exact: true }).click();
    await expect(page).toHaveURL(/\/account$/);
    await expect(accountMenu).not.toBeVisible();
    await profile.click();
    await accountMenu.getByRole('menuitem', { name: 'Logout', exact: true }).click();
    await expect(page).toHaveURL(/\/rider\/login$/);
    await page.goto('/rider');
    await expect(page).toHaveURL(/\/rider\/login$/);
});
