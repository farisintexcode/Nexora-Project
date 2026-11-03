import { expect, test as base, type Page } from '@playwright/test';

let nextAuthenticationWindow = 0;

// Keep shared synthetic accounts below the real five-logins/minute limit without intercepting application requests.
export const test = base.extend<{ pacedAuthentication: void }>({
    pacedAuthentication: [async ({}, use) => {
        const wait = Math.max(0, nextAuthenticationWindow - Date.now());
        if (wait > 0) await new Promise(resolve => setTimeout(resolve, wait));
        nextAuthenticationWindow = Date.now() + 16000;
        await use();
    }, { auto: true }],
});
export { expect, type Page };

const dialogFor = (page: Page, label: string) => page.getByRole('dialog', { name: `Choose ${label}`, exact: true });
const atMidnight = (iso: string) => new Date(`${iso}T00:00:00`);
const dayLabel = (iso: string) => atMidnight(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

async function showMonth(page: Page, label: string, iso: string): Promise<void> {
    const dialog = dialogFor(page, label);
    const target = atMidnight(iso).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    for (let step = 0; step < 36; step++) {
        const shown = (await dialog.locator('p[aria-live="polite"]').textContent())?.trim() ?? '';
        if (shown === target) return;
        await dialog.getByRole('button', { name: atMidnight(iso) < new Date(`1 ${shown}`) ? 'Previous month' : 'Next month', exact: true }).click();
    }
    throw new Error(`Calendar never reached ${target}`);
}

/** Picks a date, or a date and time written as 2026-11-03T09:00, on a single-date calendar field. */
export async function chooseDate(page: Page, id: string, label: string, value: string): Promise<void> {
    const [date, time] = value.split('T');
    await page.locator(`#${id}`).click();
    await showMonth(page, label, date);
    const dialog = dialogFor(page, label);
    await dialog.getByRole('button', { name: dayLabel(date), exact: true }).click();
    if (time) await page.locator(`#${id}-time`).fill(time);
    await dialog.getByRole('button', { name: 'Done', exact: true }).click();
    await expect(dialog).toBeHidden();
}

/** Picks one day or a from-to range on a range calendar field. */
export async function chooseDateRange(page: Page, id: string, label: string, from: string, until: string = from): Promise<void> {
    const dialog = dialogFor(page, label);
    await page.locator(`#${id}`).click();
    await showMonth(page, label, from);
    if (from === until) {
        await dialog.getByRole('button', { name: dayLabel(from), exact: true }).dblclick();
    } else {
        await dialog.getByRole('button', { name: dayLabel(from), exact: true }).click();
        await showMonth(page, label, until);
        await dialog.getByRole('button', { name: dayLabel(until), exact: true }).click();
    }
    await expect(dialog).toBeHidden();
}
