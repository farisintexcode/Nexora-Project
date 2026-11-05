import { expect, test } from './fixtures';

test('Admin previews scoped report families filters and queues a frozen export',async({page})=>{
 const password=process.env.DCMS_E2E_PASSWORD;test.skip(!password,'Synthetic reporting fixtures required.');
 const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));await page.emulateMedia({reducedMotion:'reduce'});
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill(password!);await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForURL('**/admin');
 if(test.info().project.name!=='desktop')await page.getByRole('button',{name:'Menu',exact:true}).click();
 const sidebar=page.locator('#admin-navigation');await sidebar.getByRole('button',{name:'Task Management',exact:true}).click();await sidebar.getByRole('link',{name:'Report',exact:true}).click();await expect(page).toHaveURL(/\/admin\/reports$/);
 await expect(page.getByRole('heading',{name:'Reports',exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Preview report',exact:true}).click();await expect(page.getByRole('heading',{name:'Task preview',exact:true})).toBeVisible();await expect(page.locator('table tbody tr').first()).toBeVisible();
 await page.getByLabel('Report family',{exact:true}).selectOption('rider_performance');await page.getByRole('button',{name:'Preview report',exact:true}).click();await expect(page.getByRole('heading',{name:'Rider Performance preview',exact:true})).toBeVisible();await expect(page.getByRole('columnheader',{name:'Verified/completed tasks',exact:true})).toBeVisible();
 await page.getByRole('textbox',{name:'Task ID',exact:true}).fill('99999');await page.getByRole('button',{name:'Preview report',exact:true}).click();await expect(page.getByText('No matching tasks',{exact:true})).toBeVisible();
 await page.getByRole('button',{name:'Reset filters',exact:true}).click();await page.getByRole('button',{name:'Preview report',exact:true}).click();
 const download=page.waitForEvent('download');await page.getByRole('button',{name:'Download CSV',exact:true}).click();expect((await download).suggestedFilename()).toMatch(/^nexora-task-.*\.csv$/);
 for(const width of [320,430,768,1280]){await page.setViewportSize({width,height:850});await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);}
 expect(errors).toEqual([]);await page.screenshot({path:'.local-tools/reports-'+test.info().project.name+'.png',fullPage:true});
});
